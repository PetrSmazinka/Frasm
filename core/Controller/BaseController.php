<?php

declare(strict_types=1);

namespace Core\Controller;

use Core\Config\Config;
use Core\Container\Container;
use Core\Exceptions\CoreException;
use Core\Exceptions\CsrfException;
use Core\Exceptions\HttpResponseException;
use Core\Exceptions\ValidationException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\Csrf;
use Core\Session\Session;
use Core\Validation\Validator;

/**
 * @file BaseController.php
 * @brief Abstract foundational controller providing common HTTP, rendering, and security helpers.
 */

/**
 * @class BaseController
 * @brief Base controller offering response shortcuts, input readers, validation and CSRF token protection.
 *
 * Controllers are instantiated through the DI container, so subclasses may declare constructor
 * dependencies. json() and redirect() never return: they abort the action by throwing an
 * HttpResponseException which the framework turns back into a regular Response, so middleware
 * still processes it. Alternatively return a Core\Http\Response from the action.
 */
abstract class BaseController
{
    /**
     * @var string Key used to store the CSRF token in session.
     */
    protected const CSRF_SESSION_KEY = Csrf::SESSION_KEY;

    /**
     * @var string Form field name and default HTTP header name for the token.
     */
    protected const CSRF_TOKEN_NAME = Csrf::FIELD_NAME;

    /**
     * @brief Renders an HTML view template and returns the rendered buffer.
     *
     * Automatically injects CSRF helpers ($csrf_token, $csrf_field), flash notifications
     * ($flash_success, $flash_error, $flash_info) and validation feedback ($errors, $old)
     * into the view scope.
     *
     * @param string $template View template path relative to the views directory (e.g. 'users/index').
     * @param array<string, mixed> $data Variables to expose to the view template.
     * @return string Rendered HTML content.
     * @throws CoreException If the view template file does not exist on disk or rendering fails.
     */
    protected function view(string $template, array $data = []): string
    {
        $defaultViewsPath = defined('FRASM_APP_DIR')
            ? FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Views'
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Views';

        $basePath = (string)Config::get('app.views_path', $defaultViewsPath);
        $cleanTemplate = str_ends_with($template, '.php') ? $template : $template . '.php';

        if (str_contains($cleanTemplate, '..') || str_contains($cleanTemplate, "\0")) {
            throw new CoreException("Invalid view template name: '{$template}'", 500);
        }

        $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cleanTemplate;

        if (!is_file($fullPath)) {
            throw new CoreException("View template not found: '{$fullPath}'", 500);
        }

        // Expose CSRF helpers directly into the view scope
        $data['csrf_token'] = Csrf::token();
        $data['csrf_field'] = Csrf::field();

        // Expose flash notifications and validation feedback, consuming them from session
        $data['flash_success'] = Session::getFlash('success');
        $data['flash_error'] = Session::getFlash('error');
        $data['flash_info'] = Session::getFlash('info');
        $data['errors'] = (array)Session::getFlash('errors', []);
        $data['old'] = (array)Session::getFlash('old', []);

        $render = static function (string $__frasmViewPath, array $__frasmViewData): string {
            // Extract parameters into an isolated scope (no access to $this)
            extract($__frasmViewData, EXTR_SKIP);

            ob_start();
            try {
                require $__frasmViewPath;
                return (string)ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };

        try {
            return $render($fullPath, $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CoreException("Error rendering view '{$template}': " . $e->getMessage(), 500, $e);
        }
    }

    /**
     * @brief Returns the current HTTP request.
     *
     * @return Request
     */
    protected function request(): Request
    {
        $container = Container::getInstance();
        return $container->bound(Request::class) ? $container->get(Request::class) : Request::fromGlobals();
    }

    /**
     * @brief Validates request input (or the given data) and returns the validated fields.
     *
     * On failure a ValidationException is thrown: JSON clients receive HTTP 422 with the errors,
     * browser forms are redirected back with $errors and $old available in the next view.
     *
     * @param array<string, string|list<string|\Closure>> $rules Rules keyed by field.
     * @param array<string, string> $messages Custom messages ('field.rule' or 'rule').
     * @param array<string, string> $attributes Human readable field names.
     * @param array<string, mixed>|null $data Data to validate (defaults to the request body input).
     * @return array<string, mixed> Validated data.
     * @throws ValidationException If validation fails.
     */
    protected function validate(array $rules, array $messages = [], array $attributes = [], ?array $data = null): array
    {
        return Validator::make($data ?? $this->request()->input(), $rules, $messages, $attributes)->validate();
    }

    /**
     * @brief Generates (if absent) and returns the current CSRF token from session.
     *
     * @return string The 64-character hexadecimal CSRF token.
     */
    protected function getCsrfToken(): string
    {
        return Csrf::token();
    }

    /**
     * @brief Returns an HTML hidden input element containing the current CSRF token.
     *
     * @return string HTML input element markup.
     */
    protected function csrfField(): string
    {
        return Csrf::field();
    }

    /**
     * @brief Validates the CSRF token from the request body or the X-CSRF-TOKEN header.
     *
     * Unsafe requests are already validated globally by CsrfMiddleware; calling this explicitly
     * is useful for token regeneration or when the middleware is disabled.
     *
     * @param bool $regenerate When true, regenerates the token immediately after successful validation.
     * @return void
     * @throws CsrfException If the token is missing, expired, or invalid.
     */
    protected function validateCsrf(bool $regenerate = false): void
    {
        $submitted = Csrf::tokenFromRequest($this->request());

        if ($submitted === null) {
            throw new CsrfException("Missing or empty CSRF token.", 403);
        }

        if (!Csrf::validate($submitted)) {
            throw new CsrfException("Invalid or expired CSRF token.", 403);
        }

        if ($regenerate) {
            Csrf::regenerate();
        }
    }

    /**
     * @brief Generates a new fresh CSRF token in session.
     *
     * @return string Newly generated CSRF token.
     */
    protected function regenerateCsrfToken(): string
    {
        return Csrf::regenerate();
    }

    /**
     * @brief Aborts the action with a JSON response.
     *
     * @param mixed $data Payload to serialize into JSON.
     * @param int $status HTTP response status code.
     * @param array<string, string> $headers Additional HTTP response headers.
     * @return never
     * @throws HttpResponseException Always (carries the response).
     */
    protected function json(mixed $data, int $status = 200, array $headers = []): never
    {
        throw new HttpResponseException(Response::json($data, $status, $headers));
    }

    /**
     * @brief Aborts the action with an HTTP redirect.
     *
     * @param string $url Destination target URI or URL (never pass unvalidated user input: open redirect).
     * @param int $status HTTP redirect status code (301, 302, 303, 307, 308).
     * @return never
     * @throws HttpResponseException Always (carries the response).
     */
    protected function redirect(string $url, int $status = 302): never
    {
        throw new HttpResponseException(Response::redirect($url, $status));
    }

    /**
     * @brief Retrieves a value from query string parameters.
     *
     * @param string|null $key Parameter key or null to return all query params.
     * @param mixed $default Fallback value if key is not present.
     * @return mixed
     */
    protected function query(?string $key = null, mixed $default = null): mixed
    {
        return $this->request()->query($key, $default);
    }

    /**
     * @brief Retrieves a value from the request body (form fields or JSON payload).
     *
     * @param string|null $key Parameter key or null to return all body params.
     * @param mixed $default Fallback value if key is not present.
     * @return mixed
     */
    protected function input(?string $key = null, mixed $default = null): mixed
    {
        return $this->request()->input($key, $default);
    }

    /**
     * @brief Parses and returns the decoded JSON payload sent in the request body.
     *
     * @param bool $associative When true, returned objects are converted into associative arrays.
     * @return mixed Decoded JSON structure or null on invalid or empty JSON payload.
     */
    protected function jsonBody(bool $associative = true): mixed
    {
        return $this->request()->json($associative);
    }

    /**
     * @brief Sets a one-time flash notification.
     *
     * @param string $type Flash identifier (e.g. 'success', 'error', 'info').
     * @param string $message Message content.
     * @return void
     */
    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }
}
