<?php

declare(strict_types=1);

namespace Core\Testing;

use Closure;
use Core\Exceptions\AssertionFailedException;
use Core\Http\Response;
use Throwable;

/**
 * @file TestResponse.php
 * @brief Response of a TestClient request with assertions.
 */

/**
 * @class TestResponse
 * @brief Wraps a Response; assertions throw AssertionFailedException and return $this for chaining.
 *
 * When the application turned an exception into the response (500, 404, …), failure messages name it,
 * so a failing request shows its cause instead of just a status code.
 */
final class TestResponse
{
    /**
     * @brief TestResponse constructor.
     *
     * @param Response $response Response of the application.
     * @param Throwable|null $exception Exception the error handler turned into this response.
     * @param Closure|null $onAssert Called for every assertion (assertion count of the test).
     */
    public function __construct(
        private readonly Response $response,
        private readonly ?Throwable $exception = null,
        private readonly ?Closure $onAssert = null
    ) {
    }

    /**
     * @brief Returns the status code.
     *
     * @return int
     */
    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * @brief Returns the body.
     *
     * @return string
     */
    public function content(): string
    {
        return $this->response->getContent();
    }

    /**
     * @brief Returns a header value.
     *
     * @param string $name Header name.
     * @return string|null
     */
    public function header(string $name): ?string
    {
        return $this->response->getHeader($name);
    }

    /**
     * @brief Decodes a JSON body.
     *
     * @return mixed
     */
    public function json(): mixed
    {
        return json_decode($this->content(), true);
    }

    /**
     * @brief Returns the exception behind an error response, if any.
     *
     * @return Throwable|null
     */
    public function exception(): ?Throwable
    {
        return $this->exception;
    }

    /**
     * @brief Returns the wrapped response.
     *
     * @return Response
     */
    public function response(): Response
    {
        return $this->response;
    }

    /**
     * @brief Checks whether the response redirects.
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return in_array($this->status(), [301, 302, 303, 307, 308], true) && $this->header('Location') !== null;
    }

    /**
     * @brief Asserts the status code.
     *
     * @param int $status Expected status.
     * @return self
     */
    public function assertStatus(int $status): self
    {
        return $this->check($this->status() === $status, "Expected status {$status}, got {$this->status()}.");
    }

    /**
     * @brief Asserts status 200.
     *
     * @return self
     */
    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    /**
     * @brief Asserts a redirect, optionally to a URL containing a string.
     *
     * @param string|null $to Expected part of the Location header.
     * @return self
     */
    public function assertRedirect(?string $to = null): self
    {
        $this->check($this->isRedirect(), "Expected a redirect, got status {$this->status()}.");
        if ($to !== null) {
            $location = (string)$this->header('Location');
            $this->check(str_contains($location, $to), "Expected a redirect to \"{$to}\", got \"{$location}\".");
        }

        return $this;
    }

    /**
     * @brief Asserts that the body contains text (HTML-escaped the way views print it).
     *
     * @param string $text Text.
     * @return self
     */
    public function assertSee(string $text): self
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return $this->check(
            str_contains($this->content(), $escaped) || str_contains($this->content(), $text),
            "Expected the response to contain \"{$text}\"."
        );
    }

    /**
     * @brief Asserts that the body does not contain text.
     *
     * @param string $text Text.
     * @return self
     */
    public function assertDontSee(string $text): self
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return $this->check(
            !str_contains($this->content(), $escaped) && !str_contains($this->content(), $text),
            "Expected the response not to contain \"{$text}\"."
        );
    }

    /**
     * @brief Asserts a header, optionally containing a string.
     *
     * @param string $name Header name.
     * @param string|null $contains Expected part of the value.
     * @return self
     */
    public function assertHeader(string $name, ?string $contains = null): self
    {
        $value = $this->header($name);
        $this->check($value !== null, "Expected header {$name}.");
        if ($contains !== null) {
            $this->check(str_contains((string)$value, $contains), "Expected header {$name} to contain \"{$contains}\", got \"{$value}\".");
        }

        return $this;
    }

    /**
     * @brief Counts an assertion and fails with context when it does not hold.
     *
     * @param bool $condition Condition.
     * @param string $message Failure message.
     * @return self
     * @throws AssertionFailedException
     */
    private function check(bool $condition, string $message): self
    {
        if ($this->onAssert !== null) {
            ($this->onAssert)();
        }
        if (!$condition) {
            if ($this->exception !== null) {
                $message .= ' Cause: ' . $this->exception::class . ': ' . $this->exception->getMessage()
                    . ' (' . basename($this->exception->getFile()) . ':' . $this->exception->getLine() . ')';
            }
            throw new AssertionFailedException($message);
        }

        return $this;
    }
}
