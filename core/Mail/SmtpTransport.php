<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Exceptions\MailException;

/**
 * @file SmtpTransport.php
 * @brief Sends e-mails through an SMTP server (RFC 5321) with TLS and authentication.
 */

/**
 * @class SmtpTransport
 * @brief Minimal SMTP client: one connection per message, implicit TLS (port 465) or STARTTLS (port 587).
 *
 * Certificates are always verified. Credentials are only sent over an encrypted connection unless encryption is
 * explicitly turned off ('none', e.g. a local relay). AUTH PLAIN is preferred, AUTH LOGIN is the fallback.
 * Errors carry the reply of the server, never the password.
 */
class SmtpTransport implements TransportInterface
{
    /**
     * @var resource|null Connection to the server while a message is being sent.
     */
    private $socket = null;

    /**
     * @brief SmtpTransport constructor.
     *
     * @param string $host Server host name (also checked against its certificate).
     * @param int $port Port (465 = implicit TLS, 587 = STARTTLS, 25).
     * @param string $encryption 'ssl' (implicit TLS), 'tls' (STARTTLS, required) or 'none'.
     * @param string $username Login (empty = no authentication).
     * @param string $password Password.
     * @param int $timeout Seconds for connecting and for every reply.
     * @param string $ehlo Host name announced in EHLO (empty = gethostname()).
     * @throws MailException On an invalid configuration.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 465,
        private readonly string $encryption = 'ssl',
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly int $timeout = 15,
        private readonly string $ehlo = ''
    ) {
        if ($host === '' || preg_match('/[\s\/:]/', $host)) {
            throw new MailException('Set mail.smtp.host to the host name of the SMTP server.');
        }
        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            throw new MailException("mail.smtp.encryption must be 'ssl', 'tls' or 'none', '{$encryption}' given.");
        }
    }

    /**
     * @brief Sends a message.
     *
     * @param MailMessage $message Message.
     * @param string $from Sender address (envelope and From header).
     * @param string $fromName Sender display name.
     * @return void
     * @throws MailException When the server cannot be reached or refuses the message.
     */
    public function send(MailMessage $message, string $from, string $fromName): void
    {
        $data = $message->toMime($from, $fromName);

        $this->connect();
        try {
            $this->expect(220);
            $capabilities = $this->hello();
            if ($this->encryption === 'tls') {
                if (!isset($capabilities['STARTTLS'])) {
                    throw new MailException("The SMTP server {$this->host} does not offer STARTTLS.");
                }
                $this->command('STARTTLS', 220);
                if (@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    throw new MailException("TLS with the SMTP server {$this->host} failed (certificate or protocol).");
                }
                $capabilities = $this->hello();
            }
            if ($this->username !== '') {
                $this->authenticate($capabilities['AUTH'] ?? '');
            }

            $this->command("MAIL FROM:<{$from}>", 250);
            foreach ($message->recipients() as $recipient) {
                $this->command("RCPT TO:<{$recipient}>", [250, 251]);
            }
            $this->command('DATA', 354);
            // Dot-stuffing (RFC 5321 4.5.2): a line starting with a dot gets one more
            $this->write((string)preg_replace('/^\./m', '..', $data) . (str_ends_with($data, "\r\n") ? '' : "\r\n") . ".\r\n");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            $this->disconnect();
        }
    }

    /**
     * @brief Opens the connection (TLS from the start for 'ssl').
     *
     * @return void
     * @throws MailException When the server cannot be reached.
     */
    private function connect(): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $this->host,
        ]]);
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $socket = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errorCode, $errorMessage, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailException("Cannot connect to the SMTP server {$this->host}:{$this->port}: " . ($errorMessage !== '' ? $errorMessage : "error {$errorCode}") . '.');
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    /**
     * @brief Closes the connection.
     *
     * @return void
     */
    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * @brief Greets the server and reads its extensions.
     *
     * @return array<string, string> Extension keyword => parameters (e.g. 'AUTH' => 'PLAIN LOGIN').
     * @throws MailException
     */
    private function hello(): array
    {
        $name = $this->ehlo !== '' ? $this->ehlo : (string)gethostname();
        $lines = $this->command('EHLO ' . (preg_match('/^[A-Za-z0-9.-]+$/D', $name) ? $name : 'localhost'), 250);

        $capabilities = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', trim($line), 2);
            $capabilities[strtoupper($parts[0])] = strtoupper($parts[1] ?? '');
        }

        return $capabilities;
    }

    /**
     * @brief Logs in with AUTH PLAIN or AUTH LOGIN.
     *
     * @param string $mechanisms Mechanisms offered by the server.
     * @return void
     * @throws MailException When no mechanism fits or the credentials are refused.
     */
    private function authenticate(string $mechanisms): void
    {
        $offered = explode(' ', $mechanisms);
        if (in_array('PLAIN', $offered, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0{$this->username}\0{$this->password}"), 235, 'AUTH PLAIN');
        } elseif (in_array('LOGIN', $offered, true)) {
            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode($this->username), 334, 'AUTH LOGIN (user name)');
            $this->command(base64_encode($this->password), 235, 'AUTH LOGIN (password)');
        } else {
            throw new MailException("The SMTP server {$this->host} offers no supported login method (PLAIN, LOGIN).");
        }
    }

    /**
     * @brief Sends a command and checks the reply code.
     *
     * @param string $command Command without CRLF.
     * @param int|list<int> $expected Accepted reply code(s).
     * @param string|null $label What to name the command in errors (hides credentials).
     * @return list<string> Lines of the reply.
     * @throws MailException On an unexpected reply.
     */
    private function command(string $command, int|array $expected, ?string $label = null): array
    {
        $this->write($command . "\r\n");
        return $this->expect($expected, $label ?? $command);
    }

    /**
     * @brief Writes to the server.
     *
     * @param string $data Data.
     * @return void
     * @throws MailException When the connection fails.
     */
    private function write(string $data): void
    {
        for ($written = 0, $length = strlen($data); $written < $length; $written += $count) {
            $count = @fwrite($this->socket, substr($data, $written, 65536));
            if ($count === false || $count === 0) {
                throw new MailException("The connection to the SMTP server {$this->host} was lost.");
            }
        }
    }

    /**
     * @brief Reads a (multi-line) reply and checks its code.
     *
     * @param int|list<int> $expected Accepted reply code(s).
     * @param string $after What the reply answers (for errors).
     * @return list<string> Lines of the reply without the code.
     * @throws MailException On a timeout, a lost connection or an unexpected code.
     */
    private function expect(int|array $expected, string $after = 'connecting'): array
    {
        $lines = [];
        do {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                $reason = stream_get_meta_data($this->socket)['timed_out'] ? 'did not answer in time' : 'closed the connection';
                throw new MailException("The SMTP server {$this->host} {$reason} ({$after}).");
            }
            $code = (int)substr($line, 0, 3);
            $lines[] = rtrim(substr($line, 4));
        } while (($line[3] ?? ' ') === '-');

        if (!in_array($code, (array)$expected, true)) {
            throw new MailException("The SMTP server {$this->host} refused {$after}: {$code} " . implode(' ', $lines));
        }

        return $lines;
    }
}
