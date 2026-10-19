<?php

declare(strict_types=1);

namespace Core\Scheduling;

use Core\Config\Config;
use Core\I18n\Lang;
use Core\Logger\LoggerInterface;
use Core\Mail\Mailer;
use Core\Mail\MailMessage;
use Throwable;

/**
 * @file TaskAlerts.php
 * @brief E-mails to the administrators when scheduled tasks keep failing, and when they work again.
 */

/**
 * @class TaskAlerts
 * @brief Watches the outcome of every run of a scheduled task (`scheduler.alerts`).
 *
 * @code
 * 'scheduler' => ['alerts' => [
 *     'to'      => ['admin@example.com'],   // nobody = no alerts
 *     'account' => 'robot',                 // mail account (mail.accounts), default the default one
 *     'grace'   => 60,                      // minutes a task may fail before an alert (since its last success)
 * ]],
 * @endcode
 *
 * A failed run alerts once the last success is older than the grace (a task that never succeeded alerts at once):
 * a nightly task alerts at its first failure, one running every 5 minutes only after an hour of failures, so a short
 * outage of a remote service stays quiet. One alert per period of failures, and one more when the task succeeds
 * again. Texts can be translated in app/Lang/<locale>/frasm.php (keys task_failed_subject, task_failed_body,
 * task_recovered_subject, task_recovered_body; placeholders :app, :task, :error, :since, :host).
 */
class TaskAlerts
{
    /**
     * @var array<string, string> Default texts (English).
     */
    private const TEXTS = [
        'task_failed_subject'    => '[:app] Scheduled task failed: :task',
        'task_failed_body'       => "The scheduled task :task of :app keeps failing.\n\nLast error:\n:error\n\nLast success: :since\nServer: :host\n\nYou will get one more e-mail when it works again.",
        'task_recovered_subject' => '[:app] Scheduled task works again: :task',
        'task_recovered_body'    => "The scheduled task :task of :app finished successfully again.\n\nServer: :host",
    ];

    /**
     * @brief TaskAlerts constructor.
     *
     * @param TaskStore $store Task state.
     * @param Mailer $mailer E-mail.
     * @param LoggerInterface $logger Logger (an alert that cannot be sent is only logged).
     */
    public function __construct(private readonly TaskStore $store, private readonly Mailer $mailer, private readonly LoggerInterface $logger)
    {
    }

    /**
     * @brief Checks whether alerts are configured (some recipient).
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::recipients() !== [];
    }

    /**
     * @brief Handles the outcome of a run (after TaskStore::finish()).
     *
     * @param string $task Task key.
     * @param bool $success The run succeeded.
     * @param string|null $error Error of a failed run.
     * @return void
     */
    public function afterRun(string $task, bool $success, ?string $error): void
    {
        if (!self::isEnabled()) {
            return;
        }
        try {
            $state = $this->store->state($task);
            if ($state === null) {
                return;
            }
            $alerted = $state['alerted_at'] !== null;
            if ($success) {
                if ($alerted) {
                    $this->send('task_recovered', $task, '', null);
                    $this->store->markAlerted($task, false);
                }
                return;
            }
            $lastSuccess = $state['last_success_at'] === null ? null : strtotime((string)$state['last_success_at']);
            $grace = max(0, (int)Config::get('scheduler.alerts.grace', 60)) * 60;
            if (!$alerted && ($lastSuccess === null || $lastSuccess === false || time() - $lastSuccess >= $grace)) {
                $this->send('task_failed', $task, (string)$error, $state['last_success_at']);
                $this->store->markAlerted($task, true);
            }
        } catch (Throwable $e) {
            $this->logger->error('Alert about scheduled task {task} not sent: {error}', ['task' => $task, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @brief Sends an alert.
     *
     * @param string $kind task_failed or task_recovered.
     * @param string $task Task key.
     * @param string $error Error of the run.
     * @param string|null $since Last success.
     * @return void
     * @throws \Core\Exceptions\MailException
     */
    private function send(string $kind, string $task, string $error, ?string $since): void
    {
        $replace = [
            'app'   => (string)Config::get('app.name', 'Frasm'),
            'task'  => $task,
            'error' => $error,
            'since' => $since ?? '—',
            'host'  => (string)(gethostname() ?: ''),
        ];
        $this->mailer->account((string)Config::get('scheduler.alerts.account', Mailer::DEFAULT))
            ->send(new MailMessage(self::recipients(), self::text("{$kind}_subject", $replace), self::text("{$kind}_body", $replace)));
    }

    /**
     * @brief A text of the application's dictionary (frasm.<key>), otherwise the default one.
     *
     * @param string $key Key of TEXTS.
     * @param array<string, string> $replace Placeholder values.
     * @return string
     */
    private static function text(string $key, array $replace): string
    {
        // A subject stays one line whatever the error says
        $subject = str_ends_with($key, '_subject');
        if ($subject) {
            $replace = array_map(static fn(string $value): string => str_replace(["\r", "\n"], ' ', $value), $replace);
        }
        $text = Lang::get("frasm.{$key}", $replace);
        if ($text === "frasm.{$key}") {
            $pairs = [];
            foreach ($replace as $name => $value) {
                $pairs[':' . $name] = $value;
            }
            $text = strtr(self::TEXTS[$key], $pairs);
        }

        return $subject ? str_replace(["\r", "\n"], ' ', $text) : $text;
    }

    /**
     * @brief Recipients of the alerts.
     *
     * @return list<string>
     */
    private static function recipients(): array
    {
        return array_values(array_filter(array_map('trim', array_map('strval', (array)Config::get('scheduler.alerts.to', []))), fn(string $a): bool => $a !== ''));
    }
}
