<?php

declare(strict_types=1);

namespace Core\Error;

use Core\Config\Config;
use Core\Exceptions\AuthException;
use Core\Exceptions\CoreException;
use Core\Exceptions\CsrfException;
use Core\Exceptions\DatabaseException;
use Core\Exceptions\FrasmException;
use Core\Exceptions\HttpException;
use Core\Exceptions\HttpResponseException;
use Core\Exceptions\MethodNotAllowedException;
use Core\Exceptions\RouteNotFoundException;
use Core\Exceptions\ValidationException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Logger\LoggerInterface;
use Core\Session\Session;
use ErrorException;
use Throwable;

/**
 * @file ErrorHandler.php
 * @brief Centralized PHP error, uncaught exception and fatal error handling.
 */

/**
 * @class ErrorHandler
 * @brief Converts PHP errors to exceptions, logs failures and renders safe error responses.
 *
 * - Warnings/notices become ErrorException in every environment (identical behavior in dev and prod);
 *   deprecations are only logged so a PHP upgrade cannot take the application down.
 * - Server errors (5xx) are logged with their trace; client errors (4xx) are not logged here.
 * - JSON is returned when the client expects it (Accept: application/json or X-Requested-With),
 *   otherwise HTML: a diagnostic page in debug mode, or `app/Views/errors/{status}.php`
 *   (falling back to a built-in minimal page) in production. No stack trace or exception message
 *   leaks when `app.debug` is false.
 * - ValidationException on a browser form submission redirects back with errors and old input flashed.
 */
class ErrorHandler
{
    /**
     * @var int Fatal error types handled at shutdown.
     */
    protected const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /**
     * @var list<string> Input keys never flashed back to forms.
     */
    protected const SENSITIVE_INPUT = ['password', 'password_confirmation', 'current_password', '_csrf_token'];

    /**
     * @var Request|null Request used by the last-resort handlers.
     */
    protected ?Request $request = null;

    /**
     * @var string|null Memory reserved for rendering after an out-of-memory fatal error.
     */
    protected ?string $reservedMemory = null;

    /**
     * @brief ErrorHandler constructor.
     *
     * @param LoggerInterface $logger Logger receiving reported errors.
     */
    public function __construct(protected LoggerInterface $logger)
    {
    }

    /**
     * @brief Installs error, exception and shutdown handlers and applies display directives.
     *
     * Native error display is always off (errors are rendered by this handler, with details only
     * in debug mode); native logging stays on as a backup channel into the web server error log.
     *
     * @return void
     */
    public function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');

        $this->reservedMemory = str_repeat('x', 32768);

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleUncaught']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * @brief Sets the request used when rendering from the last-resort handlers.
     *
     * @param Request $request Current request.
     * @return void
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    /**
     * @brief PHP error handler: converts errors to ErrorException, logs deprecations.
     *
     * @param int $severity Error level.
     * @param string $message Error message.
     * @param string $file File of origin.
     * @param int $line Line of origin.
     * @return bool False when the error is suppressed (@) and PHP should ignore it.
     * @throws ErrorException For every non-deprecation error that is not suppressed.
     */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            $this->logger->notice('Deprecated: {message}', ['message' => $message, 'file' => "{$file}:{$line}"]);
            return true;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * @brief Last-resort handler for exceptions escaping the kernel.
     *
     * @param Throwable $e Uncaught exception.
     * @return void
     */
    public function handleUncaught(Throwable $e): void
    {
        try {
            $response = $this->handle($e, $this->request ?? Request::fromGlobals());
            $this->discardOutputBuffers();
            $response->send();
        } catch (Throwable $inner) {
            error_log('[Frasm] Error while handling uncaught exception: ' . $inner->getMessage());
            error_log('[Frasm] Original exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            if (!headers_sent()) {
                http_response_code(500);
            }
            echo 'Internal Server Error';
        }
    }

    /**
     * @brief Shutdown function catching fatal errors that bypass the error handler.
     *
     * @return void
     */
    public function handleShutdown(): void
    {
        $this->reservedMemory = null;

        $error = error_get_last();
        if ($error === null || !($error['type'] & self::FATAL_ERRORS)) {
            return;
        }

        $this->handleUncaught(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
    }

    /**
     * @brief Reports and renders an exception.
     *
     * @param Throwable $e Exception to handle.
     * @param Request $request Current request.
     * @return Response
     */
    public function handle(Throwable $e, Request $request): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        $this->report($e, $request);

        try {
            return $this->render($e, $request);
        } catch (Throwable $renderError) {
            $this->report($renderError, $request);
            return Response::html($this->minimalPage(500), 500);
        }
    }

    /**
     * @brief Logs the exception when it represents a server-side failure.
     *
     * @param Throwable $e Exception to report.
     * @param Request|null $request Current request (adds method, path and client IP to the log context).
     * @return void
     */
    public function report(Throwable $e, ?Request $request = null): void
    {
        if ($e instanceof HttpResponseException || $this->statusCodeFor($e) < 500) {
            return;
        }

        $context = ['exception' => $e];
        if ($request !== null) {
            $context['method'] = $request->method();
            $context['uri'] = $request->uri();
            $context['ip'] = $request->ip();
        }

        try {
            $this->logger->error($e::class . ': ' . $e->getMessage(), $context);
        } catch (Throwable) {
            error_log('[Frasm] ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * @brief Renders an error response in the format expected by the client.
     *
     * @param Throwable $e Exception to render.
     * @param Request $request Current request.
     * @return Response
     */
    public function render(Throwable $e, Request $request): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        if ($e instanceof ValidationException && !$request->expectsJson()) {
            return $this->redirectBackWithErrors($e, $request);
        }

        $status = $this->statusCodeFor($e);
        $response = $request->expectsJson()
            ? Response::json($this->jsonPayload($e, $status), $status, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)
            : Response::html($this->isDebug() ? $this->debugPage($e, $status) : $this->productionPage($status), $status);

        foreach ($this->headersFor($e) as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * @brief Maps an exception to an HTTP status code.
     *
     * Only framework exceptions may choose their status via the exception code; any other
     * Throwable (driver errors, TypeError, ...) is always a 500.
     *
     * @param Throwable $e Exception.
     * @return int
     */
    public function statusCodeFor(Throwable $e): int
    {
        return match (true) {
            $e instanceof HttpException => $e->getStatusCode(),
            $e instanceof ValidationException => 422,
            $e instanceof RouteNotFoundException => 404,
            $e instanceof MethodNotAllowedException => 405,
            $e instanceof CsrfException => $this->clientCode($e->getCode(), 403),
            $e instanceof AuthException => $this->clientCode($e->getCode(), 401),
            $e instanceof DatabaseException => 500,
            $e instanceof CoreException => ($e->getCode() >= 400 && $e->getCode() < 600) ? (int)$e->getCode() : 500,
            default => 500,
        };
    }

    /**
     * @brief Returns additional headers dictated by the exception (Allow, Retry-After, ...).
     *
     * @param Throwable $e Exception.
     * @return array<string, string>
     */
    protected function headersFor(Throwable $e): array
    {
        if ($e instanceof HttpException) {
            return $e->getHeaders();
        }

        if ($e instanceof MethodNotAllowedException && $e->getAllowedMethods() !== []) {
            return ['Allow' => implode(', ', $e->getAllowedMethods())];
        }

        return [];
    }

    /**
     * @brief Builds the JSON error payload.
     *
     * @param Throwable $e Exception.
     * @param int $status HTTP status code.
     * @return array<string, mixed>
     */
    protected function jsonPayload(Throwable $e, int $status): array
    {
        $payload = [
            'status'  => $status,
            'message' => $this->isDebug() ? $e->getMessage() : $this->publicMessage($e, $status),
        ];

        if ($e instanceof ValidationException) {
            $payload['errors'] = $e->errors();
        }

        if ($this->isDebug()) {
            $payload['exception'] = $e::class;
            $payload['file'] = $e->getFile();
            $payload['line'] = $e->getLine();
            $payload['trace'] = explode("\n", $e->getTraceAsString());
            if ($e instanceof FrasmException && $e->getContext() !== []) {
                $payload['context'] = $e->getContext();
            }
        }

        return $payload;
    }

    /**
     * @brief Returns a message safe to expose in production.
     *
     * @param Throwable $e Exception.
     * @param int $status HTTP status code.
     * @return string
     */
    protected function publicMessage(Throwable $e, int $status): string
    {
        if ($e instanceof ValidationException) {
            return $e->getMessage();
        }

        return Response::reasonPhrase($status) ?: 'Error';
    }

    /**
     * @brief Redirects a failed form submission back to its origin with errors and old input.
     *
     * The Referer is followed only when it points to the same host (no open redirect).
     *
     * @param ValidationException $e Validation failure.
     * @param Request $request Current request.
     * @return Response
     */
    protected function redirectBackWithErrors(ValidationException $e, Request $request): Response
    {
        $old = array_diff_key($request->input(), array_flip(self::SENSITIVE_INPUT));
        foreach (array_keys($old) as $key) {
            if (str_contains(strtolower((string)$key), 'password')) {
                unset($old[$key]);
            }
        }

        Session::flash('errors', $e->errors());
        Session::flash('old', $old);

        $target = $request->basePath() . '/';
        $referer = $request->header('Referer');
        if ($referer !== null) {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            if (is_string($refererHost) && strcasecmp($refererHost, $request->host()) === 0) {
                $target = $referer;
            }
        }

        return Response::redirect($target, 303);
    }

    /**
     * @brief Renders the production error page from `app/Views/errors/{status}.php` or the built-in fallback.
     *
     * The template receives `$status` (int) and `$message` (reason phrase).
     *
     * @param int $status HTTP status code.
     * @return string
     */
    protected function productionPage(int $status): string
    {
        $viewsPath = (string)Config::get('app.views_path', FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Views');
        $template = rtrim($viewsPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'errors' . DIRECTORY_SEPARATOR . $status . '.php';

        if (is_file($template)) {
            $render = static function (string $__template, int $status, string $message): string {
                ob_start();
                try {
                    require $__template;
                    return (string)ob_get_clean();
                } catch (Throwable $e) {
                    ob_end_clean();
                    throw $e;
                }
            };

            return $render($template, $status, Response::reasonPhrase($status));
        }

        return $this->minimalPage($status);
    }

    /**
     * @brief Returns a dependency-free minimal error page.
     *
     * @param int $status HTTP status code.
     * @return string
     */
    protected function minimalPage(int $status): string
    {
        $title = htmlspecialchars($status . ' - ' . (Response::reasonPhrase($status) ?: 'Error'), ENT_QUOTES, 'UTF-8');
        $text = $status >= 500
            ? 'Something went wrong. Please try again later.'
            : 'The request could not be completed.';

        return "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>{$title}</title></head>"
            . "<body style=\"font-family:sans-serif;text-align:center;padding:5rem;\"><h1>{$title}</h1><p>{$text}</p></body></html>";
    }

    /**
     * @brief Renders the diagnostic page used in debug mode.
     *
     * @param Throwable $e Exception.
     * @param int $status HTTP status code.
     * @return string
     */
    protected function debugPage(Throwable $e, int $status): string
    {
        $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Debug Exception</title>"
            . "<style>body{font-family:monospace;background:#1e1e2e;color:#cdd6f4;padding:2rem;}"
            . "h1{color:#f38ba8;margin-bottom:0.5rem;} h2{color:#fab387;margin-top:0;}"
            . "pre{background:#11111b;padding:1rem;border-radius:6px;overflow-x:auto;color:#a6adc8;}"
            . ".context{background:#181825;padding:1rem;margin:1rem 0;border-left:4px solid #89b4fa;}</style></head><body>";

        $current = $e;
        $first = true;
        while ($current !== null) {
            $html .= $first ? '' : "<hr><h3>Caused by:</h3>";
            $html .= "<h1>" . $esc($current::class) . ($first ? " (HTTP {$status})" : '') . "</h1>";
            $html .= "<h2>" . $esc($current->getMessage()) . "</h2>";
            $html .= "<p><strong>Location:</strong> " . $esc($current->getFile()) . " on line <strong>" . $current->getLine() . "</strong></p>";

            if ($current instanceof FrasmException && $current->getContext() !== []) {
                $json = json_encode($current->getContext(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                $html .= "<div class='context'><h3>Diagnostic Context:</h3><pre>" . $esc((string)$json) . "</pre></div>";
            }
            if ($current instanceof ValidationException) {
                $json = json_encode($current->errors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $html .= "<div class='context'><h3>Validation Errors:</h3><pre>" . $esc((string)$json) . "</pre></div>";
            }

            $html .= "<h3>Call Stack Trace:</h3><pre>" . $esc($current->getTraceAsString()) . "</pre>";

            $current = $current->getPrevious();
            $first = false;
        }

        return $html . "</body></html>";
    }

    /**
     * @brief Returns $code when it is a 4xx status, otherwise $fallback.
     *
     * @param int|string $code Exception code.
     * @param int $fallback Default status.
     * @return int
     */
    protected function clientCode(int|string $code, int $fallback): int
    {
        $code = (int)$code;
        return ($code >= 400 && $code < 500) ? $code : $fallback;
    }

    /**
     * @brief Discards any partially rendered output.
     *
     * @return void
     */
    protected function discardOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * @brief Reads the debug flag.
     *
     * @return bool
     */
    protected function isDebug(): bool
    {
        return (bool)Config::get('app.debug', false);
    }
}
