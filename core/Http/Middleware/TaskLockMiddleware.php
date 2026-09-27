<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Exceptions\CoreException;
use Core\Exceptions\HttpException;
use Core\Http\Request;
use Core\Http\RequestHandlerInterface;
use Core\Http\Response;
use Core\Scheduling\TaskStore;
use Throwable;

/**
 * @file TaskLockMiddleware.php
 * @brief Serializes HTTP calls of a #[Schedule] action with its scheduled runs.
 */

/**
 * @class TaskLockMiddleware
 * @brief Takes the task lock for the duration of the request and records the run.
 *
 * Added automatically by the router to routes whose action carries #[Schedule]
 * (specification `task:<Class::method>,<timeout>`). When a scheduled run is in progress, the request
 * waits up to WAIT_SECONDS for it to finish and then runs; afterwards it answers 409.
 * A manual run counts as a start, so the next scheduled run follows one interval later.
 */
class TaskLockMiddleware implements ParameterizedMiddlewareInterface
{
    /**
     * @var int Seconds a request waits for a running instance of the task.
     */
    private const WAIT_SECONDS = 30;

    /**
     * @var string Task key.
     */
    private string $task = '';

    /**
     * @var int Lock lifetime in seconds.
     */
    private int $timeout = 3600;

    /**
     * @brief TaskLockMiddleware constructor.
     *
     * @param TaskStore $store Task state and locks.
     */
    public function __construct(private readonly TaskStore $store)
    {
    }

    /**
     * @brief Applies the task key and lock timeout.
     *
     * @param list<string> $parameters [task key, timeout].
     * @return void
     * @throws CoreException On missing parameters.
     */
    public function setParameters(array $parameters): void
    {
        if (($parameters[0] ?? '') === '') {
            throw new CoreException('TaskLockMiddleware needs the task key as its first parameter.');
        }

        $this->task = $parameters[0];
        $this->timeout = max(1, (int)($parameters[1] ?? 3600));
    }

    /**
     * @brief Runs the action under the task lock.
     *
     * @param Request $request Incoming request.
     * @param RequestHandlerInterface $next Next handler.
     * @return Response
     * @throws HttpException 409 when another run does not finish within WAIT_SECONDS.
     */
    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (!$this->store->claim($this->task, $this->timeout, 'http')) {
            if (microtime(true) >= $deadline) {
                throw new HttpException(409, 'The task is already running.', ['Retry-After' => '10']);
            }
            usleep(500_000);
        }

        $started = hrtime(true);
        try {
            $response = $next->handle($request);
        } catch (Throwable $e) {
            $this->store->finish($this->task, false, $e::class . ': ' . $e->getMessage(), $this->elapsed($started));
            throw $e;
        }

        $this->store->finish(
            $this->task,
            $response->getStatusCode() < 400,
            $response->getStatusCode() < 400 ? null : 'HTTP ' . $response->getStatusCode(),
            $this->elapsed($started)
        );

        return $response;
    }

    /**
     * @brief Returns milliseconds since a hrtime() start.
     *
     * @param int|float $started hrtime(true) value.
     * @return int
     */
    private function elapsed(int|float $started): int
    {
        return (int)((hrtime(true) - $started) / 1_000_000);
    }
}
