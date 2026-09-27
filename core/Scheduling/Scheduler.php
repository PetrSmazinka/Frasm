<?php

declare(strict_types=1);

namespace Core\Scheduling;

use Core\Config\Config;
use Core\Container\Container;
use Core\Exceptions\HttpResponseException;
use Core\Http\Request;
use Core\Logger\LoggerInterface;
use Core\Routing\Router;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * @file Scheduler.php
 * @brief Discovers #[Schedule] methods and runs the due ones.
 */

/**
 * @class Scheduler
 * @brief Executes scheduled methods of application controllers (app/Controllers) and task classes
 *        (app/Tasks), driven by `php bin/frasm schedule:run` from cron every minute.
 *
 * Scheduled methods are invoked through the container like controller actions. The bound Request
 * carries the attribute 'frasm.scheduled' (see Request::isScheduled()), so an action can respond
 * differently than to a browser (e.g. skip flash messages).
 */
class Scheduler
{
    /**
     * @var int Tolerance subtracted from intervals, so a 15-minute task started a second late by
     *          cron still runs in the 15th minute instead of the 16th.
     */
    private const INTERVAL_TOLERANCE = 30;

    /**
     * @var list<ScheduledTask>|null Discovered tasks (cached per process).
     */
    private ?array $tasks = null;

    /**
     * @brief Scheduler constructor.
     *
     * @param Container $container Service container.
     * @param Router $router Router (controller discovery).
     * @param TaskStore $store Task state and locks.
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly TaskStore $store,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @brief Checks whether automatic runs are enabled (`scheduler.enabled`).
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool)Config::get('scheduler.enabled', false);
    }

    /**
     * @brief Finds all methods with #[Schedule] in app/Controllers and app/Tasks.
     *
     * @return list<ScheduledTask>
     */
    public function tasks(): array
    {
        if ($this->tasks !== null) {
            return $this->tasks;
        }

        $classes = $this->router->discoverControllers(
            (string)Config::get('routing.controllers_path', FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Controllers'),
            (string)Config::get('routing.controllers_namespace', 'App\\Controllers\\')
        );
        $classes += $this->discoverTaskClasses();

        $tasks = [];
        foreach ($classes as $class => $file) {
            if (!class_exists($class, false)) {
                require_once $file;
            }
            if (!class_exists($class, false)) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $attributes = $method->getAttributes(Schedule::class);
                if ($attributes !== [] && !$method->isStatic()) {
                    $tasks[] = new ScheduledTask($class, $method->getName(), $attributes[0]->newInstance());
                }
            }
        }

        return $this->tasks = $tasks;
    }

    /**
     * @brief Finds a task by key.
     *
     * @param string $key "Class::method" (a leading backslash is ignored).
     * @return ScheduledTask|null
     */
    public function find(string $key): ?ScheduledTask
    {
        $key = ltrim($key, '\\');
        foreach ($this->tasks() as $task) {
            if ($task->key() === $key) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @brief Runs every task that is due at the given minute (nothing while the scheduler is disabled).
     *
     * @param DateTimeImmutable|null $now Current time (default: now).
     * @return list<array{task: string, success: bool, error: ?string, duration_ms: int}> Executed tasks.
     */
    public function runDue(?DateTimeImmutable $now = null): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $now ??= new DateTimeImmutable();
        $results = [];

        foreach ($this->tasks() as $task) {
            $schedule = $task->schedule;

            if ($schedule->everyMinutes !== null) {
                $claimed = $this->store->claim($task->key(), $schedule->timeout, 'schedule', $schedule->everyMinutes * 60 - self::INTERVAL_TOLERANCE);
            } elseif ((new CronExpression((string)$schedule->cron))->isDue($now)) {
                $claimed = $this->store->claim($task->key(), $schedule->timeout, 'schedule', null, $now->format('Y-m-d H:i:00'));
            } else {
                $claimed = false;
            }

            if ($claimed) {
                $results[] = $this->execute($task);
            }
        }

        return $results;
    }

    /**
     * @brief Runs a task immediately (still respecting its lock).
     *
     * @param ScheduledTask $task Task.
     * @param string $trigger Recorded trigger ('cli' for manual console runs).
     * @return array{task: string, success: bool, error: ?string, duration_ms: int}|null Result, or null when another run holds the lock.
     */
    public function runNow(ScheduledTask $task, string $trigger = 'cli'): ?array
    {
        if (!$this->store->claim($task->key(), $task->schedule->timeout, $trigger)) {
            return null;
        }

        return $this->execute($task);
    }

    /**
     * @brief Invokes a claimed task and records the outcome.
     *
     * @param ScheduledTask $task Task.
     * @return array{task: string, success: bool, error: ?string, duration_ms: int}
     */
    private function execute(ScheduledTask $task): array
    {
        $started = hrtime(true);
        $error = null;

        $request = new Request([], [], [], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '127.0.0.1'], '');
        $request->setAttribute(Request::SCHEDULED_ATTRIBUTE, true);
        $this->container->instance(Request::class, $request);

        try {
            $this->container->call([$this->container->make($task->class), $task->method]);
        } catch (HttpResponseException) {
            // redirect()/json() used by an action is a normal way to finish
        } catch (Throwable $e) {
            $error = $e::class . ': ' . $e->getMessage();
            $this->logger->error('Scheduled task {task} failed: {error}', ['task' => $task->key(), 'error' => $error, 'exception' => $e]);
        }

        $durationMs = (int)((hrtime(true) - $started) / 1_000_000);

        try {
            $this->store->finish($task->key(), $error === null, $error, $durationMs);
        } catch (Throwable $e) {
            // The lock expires after the task timeout, so a failed bookkeeping write does not block the task forever
            $this->logger->error('Scheduled task {task} could not record its result: {error}', ['task' => $task->key(), 'error' => $e->getMessage()]);
        }

        return ['task' => $task->key(), 'success' => $error === null, 'error' => $error, 'duration_ms' => $durationMs];
    }

    /**
     * @brief Lists classes in app/Tasks (namespace App\Tasks).
     *
     * @return array<string, string> Class => file.
     */
    private function discoverTaskClasses(): array
    {
        $classes = [];
        foreach (glob(FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Tasks' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $classes['App\\Tasks\\' . basename($file, '.php')] = $file;
        }

        return $classes;
    }
}
