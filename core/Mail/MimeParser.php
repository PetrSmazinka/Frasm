<?php

declare(strict_types=1);

namespace Core\Mail;

/**
 * @file MimeParser.php
 * @brief Parses a received e-mail (RFC 5322 / MIME) into its headers, text, HTML and attachments.
 */

/**
 * @class MimeParser
 * @brief Tolerant parser of received messages: nested multiparts, base64 and quoted-printable, any charset (converted
 *        to UTF-8), encoded words (RFC 2047) and file names (RFC 2231).
 *
 * The first text/plain and text/html parts that are not attachments are the body; every other leaf part with content
 * is an attachment (inline images too, with their Content-ID).
 */
final class MimeParser
{
    /**
     * @var int Deepest nesting of multiparts followed.
     */
    private const MAX_DEPTH = 10;

    /**
     * @brief Parses a message.
     *
     * @param string $raw Whole message.
     * @return array{message_id: string|null, in_reply_to: string|null, subject: string, date: string|null,
     *               from: array{address: string, name: string}|null, to: list<array{address: string, name: string}>,
     *               cc: list<array{address: string, name: string}>, reply_to: array{address: string, name: string}|null,
     *               text: string, html: string|null,
     *               attachments: list<array{name: string, type: string, content: string, content_id: string|null}>}
     */
    public static function parse(string $raw): array
    {
        [$headers, $body] = self::split($raw);
        $result = ['text' => null, 'html' => null, 'attachments' => []];
        self::walk($headers, $body, $result, 0);
        $date = isset($headers['date']) ? strtotime($headers['date'][0]) : false;
        $id = static fn(?string $value): ?string => $value !== null && preg_match('/<([^<>\s]+)>/', $value, $match) ? $match[1] : null;
        $from = self::addresses($headers['from'][0] ?? '');
        $replyTo = self::addresses($headers['reply-to'][0] ?? '');

        return [
            'message_id'  => $id($headers['message-id'][0] ?? null),
            'in_reply_to' => $id($headers['in-reply-to'][0] ?? null),
            'subject'     => self::decodeHeader($headers['subject'][0] ?? ''),
            'date'        => $date === false ? null : date('Y-m-d H:i:s', $date),
            'from'        => $from[0] ?? null,
            'to'          => self::addresses(implode(', ', $headers['to'] ?? [])),
            'cc'          => self::addresses(implode(', ', $headers['cc'] ?? [])),
            'reply_to'    => $replyTo[0] ?? null,
            'text'        => $result['text'] ?? ($result['html'] !== null ? self::htmlToText($result['html']) : ''),
            'html'        => $result['html'],
            'attachments' => $result['attachments'],
        ];
    }

    /**
     * @brief Parses an address list ("Jan <jan@example.com>, eva@example.com").
     *
     * @param string $value Header value.
     * @return list<array{address: string, name: string}>
     */
    public static function addresses(string $value): array
    {
        $result = [];
        // Split by commas outside quotes and angle brackets
        foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)(?![^<]*>)/', $value) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^<>]+)>\s*$/s', $item, $match)) {
                $name = trim(self::decodeHeader(trim($match[1])), " \"'");
                $address = trim($match[2]);
            } else {
                $name = '';
                $address = trim($item, ' <>');
            }
            if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $result[] = ['address' => mb_strtolower($address), 'name' => str_replace('\\"', '"', $name)];
            }
        }

        return $result;
    }

    /**
     * @brief Decodes encoded words of a header (RFC 2047) to UTF-8.
     *
     * @param string $value Value.
     * @return string
     */
    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return self::utf8($value, 'UTF-8');
        }
        // Spaces between adjacent encoded words are not part of the text
        $value = (string)preg_replace('/\?=\s+=\?/', '?==?', $value);
        $decoded = (string)preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', static function (array $match): string {
            $charset = preg_replace('/\*.*$/', '', $match[1]);
            $text = strtoupper($match[2]) === 'B' ? (string)base64_decode($match[3]) : quoted_printable_decode(str_replace('_', ' ', $match[3]));

            return self::utf8($text, (string)$charset);
        }, $value);

        return $decoded;
    }

    /**
     * @brief Plain text of an HTML body (for messages without a text part).
     *
     * @param string $html HTML.
     * @return string
     */
    public static function htmlToText(string $html): string
    {
        $html = (string)preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string)preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6])>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace("/[ \t\u{00A0}]+/u", ' ', $text);

        return trim((string)preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text));
    }

    /**
     * @brief Walks a part: multiparts recursively, leaves into the result.
     *
     * @param array<string, list<string>> $headers Headers of the part.
     * @param string $body Body of the part.
     * @param array{text: string|null, html: string|null, attachments: list<array<string, mixed>>} $result Result.
     * @param int $depth Nesting.
     * @return void
     */
    private static function walk(array $headers, string $body, array &$result, int $depth): void
    {
        [$type, $params] = self::parameters($headers['content-type'][0] ?? 'text/plain; charset=us-ascii');
        $type = strtolower($type);
        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < self::MAX_DEPTH) {
            $boundary = preg_quote($params['boundary'], '/');
            $parts = preg_split('/\r?\n?--' . $boundary . '(?:--)?[ \t]*\r?\n?/', "\n" . $body) ?: [];
            // The preamble before the first boundary and the epilogue after the last one are no parts
            array_shift($parts);
            foreach ($parts as $part) {
                if (trim($part) === '') {
                    continue;
                }
                [$partHeaders, $partBody] = self::split($part);
                self::walk($partHeaders, $partBody, $result, $depth + 1);
            }
            return;
        }
        if ($type === 'message/rfc822' && $depth < self::MAX_DEPTH) {
            $result['attachments'][] = ['name' => 'zprava.eml', 'type' => 'message/rfc822', 'content' => self::decodeBody($headers, $body), 'content_id' => null];
            return;
        }

        $content = self::decodeBody($headers, $body);
        [$disposition, $dispositionParams] = self::parameters($headers['content-disposition'][0] ?? '');
        $name = self::filename($dispositionParams) ?? self::filename($params);
        $attachment = strtolower($disposition) === 'attachment' || ($name !== null && !in_array($type, ['text/plain', 'text/html'], true))
            || ($name !== null && strtolower($disposition) !== 'inline');
        if (!$attachment && $type === 'text/plain' && $result['text'] === null) {
            $result['text'] = self::utf8($content, $params['charset'] ?? 'us-ascii');
            return;
        }
        if (!$attachment && $type === 'text/html' && $result['html'] === null) {
            $result['html'] = self::utf8($content, $params['charset'] ?? 'us-ascii');
            return;
        }
        if ($content === '') {
            return;
        }
        $contentId = isset($headers['content-id']) ? trim($headers['content-id'][0], " <>") : null;
        $result['attachments'][] = [
            'name'       => $name ?? ('priloha-' . (count($result['attachments']) + 1) . self::extension($type)),
            'type'       => preg_match('#^[\w.+-]+/[\w.+-]+$#', $type) ? $type : 'application/octet-stream',
            'content'    => $content,
            'content_id' => $contentId,
        ];
    }

    /**
     * @brief Splits a message or part into headers (lower-case names, unfolded, possibly repeated) and body.
     *
     * @param string $raw Raw text.
     * @return array{0: array<string, list<string>>, 1: string}
     */
    private static function split(string $raw): array
    {
        $raw = ltrim($raw, "\r\n");
        $position = strpos($raw, "\r\n\r\n");
        $separator = 4;
        $lf = strpos($raw, "\n\n");
        if ($position === false || ($lf !== false && $lf < $position)) {
            $position = $lf;
            $separator = 2;
        }
        $head = $position === false ? $raw : substr($raw, 0, $position);
        $body = $position === false ? '' : substr($raw, $position + $separator);
        $headers = [];
        foreach (preg_split('/\r?\n(?![ \t])/', $head) ?: [] as $line) {
            if (preg_match('/^([^:\s]+):\s*(.*)$/s', $line, $match)) {
                $headers[strtolower($match[1])][] = trim((string)preg_replace('/\r?\n[ \t]+/', ' ', $match[2]));
            }
        }

        return [$headers, $body];
    }

    /**
     * @brief Decodes the transfer encoding of a body.
     *
     * @param array<string, list<string>> $headers Headers.
     * @param string $body Body.
     * @return string
     */
    private static function decodeBody(array $headers, string $body): string
    {
        return match (strtolower(trim($headers['content-transfer-encoding'][0] ?? '7bit'))) {
            'base64'           => (string)base64_decode((string)preg_replace('/[^A-Za-z0-9+\/=]/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    /**
     * @brief Splits a header like Content-Type into its value and parameters (RFC 2231 continuations joined).
     *
     * @param string $value Header value.
     * @return array{0: string, 1: array<string, string>}
     */
    private static function parameters(string $value): array
    {
        $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [];
        $main = trim((string)array_shift($parts));
        $params = [];
        foreach ($parts as $part) {
            if (preg_match('/^\s*([^=\s]+)\s*=\s*(.*)$/s', $part, $match)) {
                $params[strtolower($match[1])] = trim(trim($match[2]), '"');
            }
        }
        // name*0="…"; name*1="…" and name*=UTF-8''%C3%A9
        $joined = [];
        foreach ($params as $key => $param) {
            if (preg_match('/^([^*]+)\*(\d+)(\*?)$/', $key, $match)) {
                $joined[$match[1] . ($match[3] !== '' || isset($joined[$match[1] . '*']) ? '*' : '')][(int)$match[2]] = $param;
                unset($params[$key]);
            }
        }
        foreach ($joined as $key => $pieces) {
            ksort($pieces);
            $params[$key] = implode('', $pieces);
        }

        return [$main, $params];
    }

    /**
     * @brief File name from the parameters (filename, name, RFC 2231 or encoded words).
     *
     * @param array<string, string> $params Parameters.
     * @return string|null
     */
    private static function filename(array $params): ?string
    {
        foreach (['filename*', 'name*'] as $key) {
            if (isset($params[$key]) && preg_match("/^([^']*)'[^']*'(.*)$/", $params[$key], $match)) {
                return self::safeName(self::utf8(rawurldecode($match[2]), $match[1] !== '' ? $match[1] : 'UTF-8'));
            }
        }
        foreach (['filename', 'name'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                return self::safeName(self::decodeHeader($params[$key]));
            }
        }

        return null;
    }

    /**
     * @brief Keeps a file name safe (no paths, no control characters).
     *
     * @param string $name Name.
     * @return string
     */
    private static function safeName(string $name): string
    {
        $name = trim((string)preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/', '_', basename(str_replace('\\', '/', $name))), ' .');

        return $name === '' ? 'priloha' : mb_substr($name, 0, 150);
    }

    /**
     * @brief Converts text in a charset to UTF-8 (invalid bytes dropped).
     *
     * @param string $text Text.
     * @param string $charset Charset.
     * @return string
     */
    private static function utf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset)) ?: 'UTF-8';
        if (in_array($charset, ['UTF-8', 'UTF8', 'US-ASCII', 'ASCII'], true)) {
            return mb_check_encoding($text, 'UTF-8') ? $text : (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);

        return is_string($converted) ? $converted : (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * @brief Extension for an unnamed attachment.
     *
     * @param string $type Media type.
     * @return string
     */
    private static function extension(string $type): string
    {
        return match ($type) {
            'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'application/pdf' => '.pdf',
            'text/calendar' => '.ics', 'text/plain' => '.txt', 'text/html' => '.html', default => '.bin',
        };
    }
}
