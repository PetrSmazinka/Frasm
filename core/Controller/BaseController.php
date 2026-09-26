<?php

declare(strict_types=1);

namespace Core\Controller;

use Core\Config\Config;
use Core\Exceptions\CoreException;
use Core\Exceptions\CsrfException;

/**
 * @file BaseController.php
 * @brief Abstract foundational controller providing common HTTP, rendering, and security helpers.
 */

/**
 * @class BaseController
 * @brief Base controller offering response shortcuts, input readers, and CSRF token protection.
 */
abstract class BaseController
{
    /**
     * @var string Key used to store the CSRF token in session.
     */
    protected const CSRF_SESSION_KEY = '_frasm_csrf_token';

    /**
     * @var string Form field name and default HTTP header name for the token.
     */
    protected const CSRF_TOKEN_NAME = '_csrf_token';

    /**
     * @brief Renders an HTML view template and returns the rendered buffer.
     *
     * Automatically injects CSRF helpers ($csrf_token and $csrf_field) into the view scope.
     *
     * @param string $template View template path relative to the views directory (e.g. 'users/index').
     * @param array<string, mixed> $data Variables to expose to the view template.
     * @return string Rendered HTML content.
     * @throws CoreException If the view template file does not exist on disk.
     */
    protected function view(string $template, array $data = []): string
    {
        $defaultViewsPath = defined('FRASM_APP_DIR')
            ? FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Views'
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Views';

        $basePath = (string)Config::get('app.views_path', $defaultViewsPath);
        $cleanTemplate = str_ends_with($template, '.php') ? $template : $template . '.php';
        $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cleanTemplate;

        if (!is_file($fullPath)) {
            throw new CoreException("View template not found: '{$fullPath}'", 500);
        }

        // Expose CSRF helpers directly into the view scope
        $data['csrf_token'] = $this->getCsrfToken();
        $data['csrf_field'] = $this->csrfField();

        // Extract parameters into local scope
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            require $fullPath;
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new CoreException(
                "Error rendering view '{$template}': " . $e->getMessage(),
                500,
                $e
            );
        }
    }

    /**
     * @brief Generates (if absent) and returns the current CSRF token from session.
     *
     * @return string The 64-character hexadecimal CSRF token.
     */
    protected function getCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::CSRF_SESSION_KEY])) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION[self::CSRF_SESSION_KEY];
    }

    /**
     * @brief Returns an HTML hidden input element containing the current CSRF token.
     *
     * @return string HTML input element markup.
     */
    protected function csrfField(): string
    {
        $token = htmlspecialchars($this->getCsrfToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . self::CSRF_TOKEN_NAME . '" value="' . $token . '">';
    }

    /**
     * @brief Validates the CSRF token from POST body or HTTP headers.
     *
     * Timing-attack safe comparison via hash_equals().
     * Checks $_POST['_csrf_token'] first, then falls back to HTTP header 'X-CSRF-TOKEN'.
     *
     * @param bool $regenerate When true, regenerates the token immediately after successful validation.
     * @return void
     * @throws CsrfException If the token is missing, expired, or invalid.
     */
    protected function validateCsrf(bool $regenerate = false): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionToken = (string)($_SESSION[self::CSRF_SESSION_KEY] ?? '');

        // 1. Try fetching token from POST payload
        $submittedToken = $this->input(self::CSRF_TOKEN_NAME);

        // 2. Fall back to request headers (e.g. X-CSRF-TOKEN for Fetch/AJAX requests)
        if ($submittedToken === null && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $submittedToken = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        if (empty($sessionToken) || empty($submittedToken) || !is_string($submittedToken)) {
            throw new CsrfException("Missing or empty CSRF token.", 403);
        }

        if (!hash_equals($sessionToken, $submittedToken)) {
            throw new CsrfException("Invalid or expired CSRF token.", 403);
        }

        if ($regenerate) {
            $this->regenerateCsrfToken();
        }
    }

    /**
     * @brief Generates a new fresh CSRF token in session.
     *
     * @return string Newly generated CSRF token.
     */
    protected function regenerateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        return (string)$_SESSION[self::CSRF_SESSION_KEY];
    }

    /**
     * @brief Emits a JSON response directly and halts script execution.
     *
     * @param mixed $data Payload to serialize into JSON.
     * @param int $status HTTP response status code.
     * @param array<string, string> $headers Additional HTTP response headers.
     * @return never
     */
    protected function json(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);

        header('Content-Type: application/json; charset=UTF-8');
        foreach ($headers as $header => $value) {
            header("{$header}: {$value}");
        }

        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @brief Performs an HTTP redirect to a given URL.
     *
     * @param string $url Destination target URI or URL.
     * @param int $status HTTP redirect status code (301, 302, 303, 307, 308).
     * @return never
     */
    protected function redirect(string $url, int $status = 302): never
    {
        http_response_code($status);
        header("Location: {$url}");
        exit;
    }

    /**
     * @brief Retrieves a value from query string parameters ($_GET).
     *
     * @param string|null $key Parameter key or null to return all query params.
     * @param mixed $default Fallback value if key is not present.
     * @return mixed
     */
    protected function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_GET;
        }

        return $_GET[$key] ?? $default;
    }

    /**
     * @brief Retrieves a value from POST request body ($_POST).
     *
     * @param string|null $key Parameter key or null to return all POST params.
     * @param mixed $default Fallback value if key is not present.
     * @return mixed
     */
    protected function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_POST;
        }

        return $_POST[$key] ?? $default;
    }

    /**
     * @brief Parses and returns the decoded JSON payload sent in the request body.
     *
     * @param bool $associative When true, returned objects are converted into associative arrays.
     * @return mixed Decoded JSON structure or null on invalid or empty JSON payload.
     */
    protected function jsonBody(bool $associative = true): mixed
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        try {
            return json_decode($raw, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}