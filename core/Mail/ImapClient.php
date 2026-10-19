<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Exceptions\MailException;

/**
 * @file ImapClient.php
 * @brief Minimal IMAP4rev1 client (RFC 3501) for reading a mailbox without the PHP imap extension.
 */

/**
 * @class ImapClient
 * @brief Logs in, selects a mailbox, searches and fetches whole messages by UID; never changes the mailbox
 *        (BODY.PEEK keeps messages unread).
 *
 * @code
 * $imap = new ImapClient('imap.example.com', 993, 'ssl', 'box@example.com', 'secret');
 * $box = $imap->select('INBOX');                   // ['exists' => …, 'uidvalidity' => …, 'uidnext' => …]
 * foreach ($imap->search('UID 120:*') as $uid) {
 *     // $imap->size($uid) and $imap->fetchHeader($uid) for messages too large to download
 *     $parsed = MimeParser::parse($imap->fetch($uid));
 * }
 * $imap->logout();
 * @endcode
 */
final class ImapClient
{
    /**
     * @var resource|null Connection.
     */
    private $socket = null;

    /**
     * @var int Number of the last command tag.
     */
    private int $tag = 0;

    /**
     * @brief ImapClient constructor.
     *
     * @param string $host Server.
     * @param int $port Port (993 for ssl, 143 for tls or none).
     * @param string $encryption ssl (TLS from the start), tls (STARTTLS) or none.
     * @param string $username User name.
     * @param string $password Password.
     * @param int $timeout Seconds for connecting and every read.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 20
    ) {
    }

    /**
     * @brief Creates a client from configuration {host, port, encryption, username, password, timeout}.
     *
     * @param array<string, mixed> $config Configuration (e.g. mail.imap).
     * @return self
     * @throws MailException When the host is missing.
     */
    public static function fromConfig(array $config): self
    {
        if (trim((string)($config['host'] ?? '')) === '') {
            throw new MailException('IMAP is not configured (host).');
        }
        $encryption = (string)($config['encryption'] ?? 'ssl');

        return new self((string)$config['host'], (int)($config['port'] ?? ($encryption === 'ssl' ? 993 : 143)), $encryption,
            (string)($config['username'] ?? ''), (string)($config['password'] ?? ''), (int)($config['timeout'] ?? 20));
    }

    /**
     * @brief Selects a mailbox (read only) and logs in first when needed.
     *
     * @param string $mailbox Mailbox name.
     * @return array{exists: int, uidvalidity: int, uidnext: int}
     * @throws MailException
     */
    public function select(string $mailbox = 'INBOX'): array
    {
        $this->open();
        $response = $this->command('EXAMINE ' . self::quote($mailbox));
        $result = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($response['lines'] as $line) {
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $match)) {
                $result['exists'] = (int)$match[1];
            } elseif (preg_match('/UIDVALIDITY (\d+)/i', $line, $match)) {
                $result['uidvalidity'] = (int)$match[1];
            } elseif (preg_match('/UIDNEXT (\d+)/i', $line, $match)) {
                $result['uidnext'] = (int)$match[1];
            }
        }

        return $result;
    }

    /**
     * @brief Searches the selected mailbox.
     *
     * @param string $criteria Search criteria (e.g. "UID 100:*", "SINCE 1-Oct-2026"); only safe characters.
     * @return list<int> UIDs, ascending.
     * @throws MailException
     */
    public function search(string $criteria): array
    {
        if (!preg_match('/^[A-Za-z0-9 :*\-]+$/D', $criteria)) {
            throw new MailException('Invalid IMAP search criteria.');
        }
        $uids = [];
        foreach ($this->command('UID SEARCH ' . $criteria)['lines'] as $line) {
            if (preg_match('/^\* SEARCH(.*)$/i', $line, $match)) {
                foreach (preg_split('/\s+/', trim($match[1])) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int)$uid;
                    }
                }
            }
        }
        sort($uids);

        return array_values(array_unique($uids));
    }

    /**
     * @brief Fetches a whole message (RFC 822) without marking it read.
     *
     * @param int $uid UID.
     * @return string|null Null when there is no such message.
     * @throws MailException
     */
    public function fetch(int $uid): ?string
    {
        $response = $this->command("UID FETCH {$uid} (BODY.PEEK[])");

        return $response['literals'][0] ?? null;
    }

    /**
     * @brief Size of a message in bytes (to skip downloading huge ones).
     *
     * @param int $uid UID.
     * @return int|null Null when there is no such message.
     * @throws MailException
     */
    public function size(int $uid): ?int
    {
        foreach ($this->command("UID FETCH {$uid} (RFC822.SIZE)")['lines'] as $line) {
            if (preg_match('/RFC822\.SIZE (\d+)/i', $line, $match)) {
                return (int)$match[1];
            }
        }

        return null;
    }

    /**
     * @brief Fetches only the header of a message (without marking it read).
     *
     * @param int $uid UID.
     * @return string|null Null when there is no such message.
     * @throws MailException
     */
    public function fetchHeader(int $uid): ?string
    {
        return $this->command("UID FETCH {$uid} (BODY.PEEK[HEADER])")['literals'][0] ?? null;
    }

    /**
     * @brief Logs out and closes the connection.
     *
     * @return void
     */
    public function logout(): void
    {
        if ($this->socket !== null) {
            try {
                $this->command('LOGOUT');
            } catch (MailException) {
                // closing anyway
            }
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * @brief Connects and logs in (once).
     *
     * @return void
     * @throws MailException
     */
    private function open(): void
    {
        if ($this->socket !== null) {
            return;
        }
        $address = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $socket = @stream_socket_client($address, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailException("The IMAP server {$this->host} cannot be reached ({$error}).");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new MailException("Unexpected IMAP greeting from {$this->host}.");
        }
        if ($this->encryption === 'tls') {
            $this->command('STARTTLS');
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                throw new MailException("TLS with the IMAP server {$this->host} failed.");
            }
        }
        if (!str_starts_with($greeting, '* PREAUTH')) {
            try {
                $this->command('LOGIN ' . self::quote($this->username) . ' ' . self::quote($this->password));
            } catch (MailException) {
                throw new MailException("The IMAP server {$this->host} refused the login.");
            }
        }
    }

    /**
     * @brief Sends a command and reads its response up to the tagged result.
     *
     * @param string $command Command without the tag.
     * @return array{lines: list<string>, literals: list<string>} Untagged lines and literal data.
     * @throws MailException When the server answers NO or BAD, or the connection fails.
     */
    private function command(string $command): array
    {
        if ($this->socket === null) {
            throw new MailException('IMAP is not connected.');
        }
        $tag = 'F' . (++$this->tag);
        if (@fwrite($this->socket, "{$tag} {$command}\r\n") === false) {
            throw new MailException('IMAP connection lost.');
        }
        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->readLine();
            // A literal {n}: n bytes follow, then the rest of the line
            while (preg_match('/\{(\d+)\}$/', $line, $match)) {
                $literals[] = $this->readBytes((int)$match[1]);
                $line .= ' ' . $this->readLine();
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK/i', $line)) {
                    throw new MailException('IMAP error: ' . substr($line, strlen($tag) + 1, 200));
                }
                return ['lines' => $lines, 'literals' => $literals];
            }
            $lines[] = $line;
        }
    }

    /**
     * @brief Reads one line (without CRLF).
     *
     * @return string
     * @throws MailException On a timeout or a closed connection.
     */
    private function readLine(): string
    {
        $line = @fgets($this->socket);
        if ($line === false) {
            throw new MailException('IMAP connection lost or timed out.');
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @brief Reads exactly n bytes.
     *
     * @param int $length Bytes.
     * @return string
     * @throws MailException
     */
    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($this->socket, min(65536, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new MailException('IMAP connection lost while reading a message.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @brief Quotes a string for IMAP.
     *
     * @param string $value Value.
     * @return string
     * @throws MailException On line breaks.
     */
    private static function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new MailException('Invalid IMAP string.');
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }
}
