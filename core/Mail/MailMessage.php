<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Exceptions\MailException;

/**
 * @file MailMessage.php
 * @brief One e-mail: recipients (to, cc, bcc), subject, a plain text body with an optional HTML alternative and
 *        attachments.
 */

/**
 * @class MailMessage
 * @brief Immutable message, validated on construction and rendered as a MIME message (RFC 5322, 2045, 2047).
 *
 * Addresses are validated and subjects may not contain line breaks, so values from users cannot add headers.
 * Bodies are UTF-8, encoded as quoted-printable; with an HTML body the message is multipart/alternative, with
 * attachments multipart/mixed (attachments base64). Blind copies get the message but are not in its headers.
 *
 * @code
 * $mailer->send(new MailMessage('jan@example.com', 'Weekly report', "Hello,\n..."));
 * $mailer->send(new MailMessage(['jan@example.com'], 'Photos', $text, $html, cc: ['eva@example.com'],
 *     attachments: [['name' => 'foto.jpg', 'type' => 'image/jpeg', 'content' => $bytes]]));
 * @endcode
 */
final class MailMessage
{
    /**
     * @var list<string> Recipient addresses.
     */
    public readonly array $to;

    /**
     * @var list<string> Copy addresses (in the Cc header).
     */
    public readonly array $cc;

    /**
     * @var list<string> Blind copy addresses (not in the headers).
     */
    public readonly array $bcc;

    /**
     * @var list<array{name: string, type: string, content: string}> Attachments.
     */
    public readonly array $attachments;

    /**
     * @var string Message-ID (without angle brackets and domain: the domain of the sender is added).
     */
    public readonly string $id;

    /**
     * @brief MailMessage constructor.
     *
     * @param string|list<string> $to Recipient address(es).
     * @param string $subject Subject (one line).
     * @param string $text Plain text body.
     * @param string|null $html HTML alternative of the body.
     * @param string|null $replyTo Address replies go to.
     * @param list<string> $cc Copy addresses.
     * @param list<string> $bcc Blind copy addresses.
     * @param list<array{name: string, type: string, content: string}> $attachments Attachments (file name, media
     *                                                                           type, bytes).
     * @param string|null $inReplyTo Message-ID of the message this one answers (with angle brackets or without).
     * @throws MailException On an invalid address, a multi-line subject, an invalid attachment or no recipients.
     */
    public function __construct(
        string|array $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
        public readonly ?string $replyTo = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
        public readonly ?string $inReplyTo = null
    ) {
        $clean = static fn(array $list): array => array_values(array_unique(array_filter(array_map('trim', $list), fn(string $a): bool => $a !== '')));
        $this->to = $clean((array)$to);
        $this->cc = array_values(array_diff($clean($cc), $this->to));
        $this->bcc = array_values(array_diff($clean($bcc), $this->to, $this->cc));
        if ($this->to === [] && $this->cc === [] && $this->bcc === []) {
            throw new MailException('An e-mail needs at least one recipient.');
        }
        foreach ([...$this->to, ...$this->cc, ...$this->bcc, ...($replyTo !== null ? [$replyTo] : [])] as $address) {
            self::assertAddress($address);
        }
        if ($inReplyTo !== null && !preg_match('/^<?[^<>\s]+>?$/D', $inReplyTo)) {
            throw new MailException('Invalid In-Reply-To.');
        }
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || !is_string($attachment['content'] ?? null) || !preg_match('#^[\w.+-]+/[\w.+-]+$#D', (string)($attachment['type'] ?? ''))
                || trim((string)($attachment['name'] ?? '')) === '' || preg_match('/[\r\n"]/', (string)$attachment['name'])) {
                throw new MailException('Invalid attachment.');
            }
        }
        $this->attachments = array_values(array_map(fn(array $a): array => ['name' => (string)$a['name'], 'type' => (string)$a['type'], 'content' => $a['content']], $attachments));
        $this->id = bin2hex(random_bytes(16));
        if (preg_match('/[\r\n]/', $subject)) {
            throw new MailException('The subject of an e-mail must be a single line.');
        }
    }

    /**
     * @brief All addresses the message is delivered to (to, cc, bcc).
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    /**
     * @brief Checks an e-mail address (also rejects line breaks and angle brackets).
     *
     * @param string $address Address.
     * @return void
     * @throws MailException When invalid.
     */
    public static function assertAddress(string $address): void
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || strpbrk($address, "\r\n<>") !== false) {
            throw new MailException("Invalid e-mail address '{$address}'.");
        }
    }

    /**
     * @brief Renders the message with headers, lines ending with CRLF.
     *
     * @param string $from Sender address (validated).
     * @param string $fromName Sender display name.
     * @return string
     * @throws MailException When the sender address is invalid.
     */
    public function toMime(string $from, string $fromName = ''): string
    {
        self::assertAddress($from);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::mailbox($from, $fromName),
            'To: ' . ($this->to === [] ? 'undisclosed-recipients:;' : implode(', ', $this->to)),
            'Subject: ' . self::encodeHeader($this->subject),
            'Message-ID: ' . $this->messageId($from),
            'MIME-Version: 1.0',
        ];
        if ($this->cc !== []) {
            $headers[] = 'Cc: ' . implode(', ', $this->cc);
        }
        if ($this->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $this->replyTo;
        }
        if ($this->inReplyTo !== null) {
            $reference = '<' . trim($this->inReplyTo, '<>') . '>';
            $headers[] = 'In-Reply-To: ' . $reference;
            $headers[] = 'References: ' . $reference;
        }

        // Body: text, or text and HTML as alternatives
        $body = self::part('text/plain', $this->text);
        $bodyType = null;
        if ($this->html !== null) {
            $alternative = '=_a' . bin2hex(random_bytes(12));
            $bodyType = "multipart/alternative; boundary=\"{$alternative}\"";
            $body = "--{$alternative}\r\n" . self::part('text/plain', $this->text) . "\r\n"
                . "--{$alternative}\r\n" . self::part('text/html', $this->html) . "\r\n"
                . "--{$alternative}--\r\n";
        }
        if ($this->attachments === []) {
            if ($bodyType === null) {
                return implode("\r\n", $headers) . "\r\n" . $body;
            }
            $headers[] = "Content-Type: {$bodyType}";
            return implode("\r\n", $headers) . "\r\n\r\n" . $body;
        }

        $mixed = '=_m' . bin2hex(random_bytes(12));
        $headers[] = "Content-Type: multipart/mixed; boundary=\"{$mixed}\"";
        $mime = implode("\r\n", $headers) . "\r\n\r\n--{$mixed}\r\n"
            . ($bodyType === null ? $body : "Content-Type: {$bodyType}\r\n\r\n" . $body) . "\r\n";
        foreach ($this->attachments as $attachment) {
            $name = self::encodeHeader($attachment['name']);
            $mime .= "--{$mixed}\r\nContent-Type: {$attachment['type']}; name=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n" . chunk_split(base64_encode($attachment['content']), 76, "\r\n");
        }

        return $mime . "--{$mixed}--\r\n";
    }

    /**
     * @brief Message-ID header value of the message sent from an address.
     *
     * @param string $from Sender address.
     * @return string "<id@domain>"
     */
    public function messageId(string $from): string
    {
        return '<' . $this->id . '@' . substr((string)strrchr($from, '@'), 1) . '>';
    }

    /**
     * @brief Renders a body part: its headers, an empty line and the quoted-printable content.
     *
     * @param string $type Media type.
     * @param string $content Content (UTF-8).
     * @return string
     */
    private static function part(string $type, string $content): string
    {
        $content = (string)preg_replace('/\r\n?|\n/', "\r\n", $content);

        return "Content-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($content) . "\r\n";
    }

    /**
     * @brief Formats a mailbox "Name <address>" (just the address without a name).
     *
     * @param string $address Address.
     * @param string $name Display name.
     * @return string
     */
    private static function mailbox(string $address, string $name): string
    {
        $name = trim((string)preg_replace('/[\r\n]+/', ' ', $name));
        return $name === '' ? $address : self::encodeHeader($name) . " <{$address}>";
    }

    /**
     * @brief Encodes a header value with non-ASCII characters (RFC 2047, base64 words).
     *
     * @param string $value Value.
     * @return string
     */
    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }

        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }
}
