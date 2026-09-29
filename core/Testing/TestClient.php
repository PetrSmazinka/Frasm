<?php

declare(strict_types=1);

namespace Core\Testing;

use Closure;
use Core\Auth\Auth;
use Core\Container\Container;
use Core\Error\ErrorHandler;
use Core\Http\Kernel;
use Core\Http\Request;
use Core\Security\Csrf;
use Core\Session\Session;

/**
 * @file TestClient.php
 * @brief In-process HTTP client for tests: requests go through the Kernel without a web server.
 */

/**
 * @class TestClient
 * @brief Sends requests to the application like a browser of one user.
 *
 * Every client is one browser: it keeps its own PHP session between its requests (clients of one test
 * do not share it), sends the previous URL as Referer (validation errors redirect back), adds the CSRF
 * token of its session to form posts, and actingAs() signs a user in without the login form.
 *
 *     $response = $this->http('business.example.com')->actingAs(1, ['business.admin'])->get('/projects');
 *     $response->assertOk()->assertSee('Projekty');
 */
final class TestClient
{
    /**
     * @var int Maximum redirects followed by followRedirects().
     */
    private const MAX_REDIRECTS = 5;

    /**
     * @var string|null URL of the previous request (sent as Referer).
     */
    private ?string $previous = null;

    /**
     * @var bool Add the CSRF token to form posts.
     */
    private bool $csrf = true;

    /**
     * @var bool Follow redirects of GET-able responses.
     */
    private bool $follow = false;

    /**
     * @var array<string, string> Extra headers sent with every request.
     */
    private array $headers = [];

    /**
     * @var string|null ID of this client's PHP session (null = no session yet).
     */
    private ?string $sessionId = null;

    /**
     * @brief TestClient constructor.
     *
     * @param Kernel $kernel Application kernel.
     * @param string $host Host name (selects the domain of `app.domains`).
     * @param bool $https Send requests as HTTPS.
     * @param Closure|null $onAssert Called for every assertion made on a response (assertion count).
     */
    public function __construct(
        private readonly Kernel $kernel,
        private readonly string $host = 'localhost',
        private readonly bool $https = true,
        private readonly ?Closure $onAssert = null
    ) {
    }

    /**
     * @brief Signs a user in for the following requests (no password needed).
     *
     * The user must exist (TestCase::createUser()): the framework reloads the roles from the database
     * on every request and signs out users that do not exist.
     *
     * @param int|string $userId User ID.
     * @param list<string> $roles Roles stored in the session until they are reloaded.
     * @return self
     */
    public function actingAs(int|string $userId, array $roles = []): self
    {
        $this->bindRequest($this->request('GET', '/', [], []));
        Auth::login($userId, $roles);
        $this->rememberSession();

        return $this;
    }

    /**
     * @brief Sends form posts without the CSRF token (to test the protection itself).
     *
     * @return self
     */
    public function withoutCsrf(): self
    {
        $this->csrf = false;
        return $this;
    }

    /**
     * @brief Follows redirects of the following requests (up to five).
     *
     * @return self
     */
    public function followRedirects(): self
    {
        $this->follow = true;
        return $this;
    }

    /**
     * @brief Adds a header to every following request.
     *
     * @param string $name Header name.
     * @param string $value Header value.
     * @return self
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * @brief Sends a GET request.
     *
     * @param string $uri Path with an optional query string.
     * @param array<string, string> $headers Headers.
     * @return TestResponse
     */
    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->send('GET', $uri, [], $headers);
    }

    /**
     * @brief Sends a form POST (application/x-www-form-urlencoded) with the CSRF token of the session.
     *
     * @param string $uri Path.
     * @param array<string, mixed> $data Form fields (nested arrays like name[] are allowed).
     * @param array<string, string> $headers Headers.
     * @return TestResponse
     */
    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->send('POST', $uri, $data, $headers);
    }

    /**
     * @brief Sends a JSON request.
     *
     * @param string $method HTTP method.
     * @param string $uri Path.
     * @param array<mixed> $data Body, encoded as JSON.
     * @param array<string, string> $headers Headers.
     * @return TestResponse
     */
    public function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($this->csrf && !in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $headers += ['X-CSRF-TOKEN' => $this->csrfToken()];
        }

        return $this->dispatch($this->request(strtoupper($method), $uri, [], $headers, $body));
    }

    /**
     * @brief Sends a request.
     *
     * @param string $method HTTP method (PUT/PATCH/DELETE forms use POST with _method).
     * @param string $uri Path with an optional query string.
     * @param array<string, mixed> $data Form fields.
     * @param array<string, string> $headers Headers.
     * @return TestResponse
     */
    public function send(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'HEAD' && $this->csrf && !array_key_exists('_csrf_token', $data)) {
            $data['_csrf_token'] = $this->csrfToken();
        }

        $response = $this->dispatch($this->request($method, $uri, $method === 'GET' || $method === 'HEAD' ? [] : $data, $headers));

        for ($i = 0; $this->follow && $response->isRedirect() && $i < self::MAX_REDIRECTS; $i++) {
            $location = (string)$response->header('Location');
            $host = parse_url($location, PHP_URL_HOST);
            $path = (string)(parse_url($location, PHP_URL_PATH) ?? '/');
            $query = parse_url($location, PHP_URL_QUERY);
            $client = is_string($host) && strcasecmp($host, $this->host) !== 0 ? $this->forHost($host) : $this;
            $response = $client->dispatch($client->request('GET', $path . (is_string($query) ? "?{$query}" : ''), [], $headers));
        }

        return $response;
    }

    /**
     * @brief Returns the CSRF token of the current session.
     *
     * @return string
     */
    public function csrfToken(): string
    {
        $this->bindRequest($this->request('GET', '/', [], []));
        $token = Csrf::token();
        $this->rememberSession();

        return $token;
    }

    /**
     * @brief Returns a client for another host sharing the session and settings.
     *
     * @param string $host Host name.
     * @return self
     */
    private function forHost(string $host): self
    {
        $client = new self($this->kernel, $host, $this->https, $this->onAssert);
        $client->csrf = $this->csrf;
        $client->headers = $this->headers;
        $client->previous = $this->previous;
        $client->sessionId = $this->sessionId;

        return $client;
    }

    /**
     * @brief Handles a request and wraps the response.
     *
     * @param Request $request Request.
     * @return TestResponse
     */
    private function dispatch(Request $request): TestResponse
    {
        $errors = Container::getInstance()->get(ErrorHandler::class);
        $errors->takeLastException();

        $this->activateSession();
        $response = $this->kernel->handle($this->withSessionCookie($request));
        $this->rememberSession();
        $this->previous = ($this->https ? 'https://' : 'http://') . $this->host . $request->uri();

        return new TestResponse($response, $errors->takeLastException(), $this->onAssert);
    }

    /**
     * @brief Builds a request as a browser of this client would send it.
     *
     * @param string $method HTTP method.
     * @param string $uri Path with an optional query string.
     * @param array<string, mixed> $post Form fields.
     * @param array<string, string> $headers Headers.
     * @param string $body Raw body.
     * @return Request
     */
    private function request(string $method, string $uri, array $post, array $headers, string $body = ''): Request
    {
        $uri = '/' . ltrim($uri, '/');
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);

        $server = [
            'REQUEST_METHOD'  => $method,
            'REQUEST_URI'     => $uri,
            'SCRIPT_NAME'     => '/index.php',
            'HTTP_HOST'       => $this->host,
            'SERVER_NAME'     => $this->host,
            'REMOTE_ADDR'     => '127.0.0.1',
            'HTTP_USER_AGENT' => 'Frasm-TestClient',
        ];
        if ($this->https) {
            $server['HTTPS'] = 'on';
        }
        if ($post !== [] && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        if ($this->previous !== null && !isset($headers['Referer'])) {
            $headers['Referer'] = $this->previous;
        }
        foreach ($this->headers + $headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : "HTTP_{$key}"] = $value;
        }

        return new Request($query, $post, [], [], $server, $body);
    }

    /**
     * @brief Adds the session cookie of this client to a request.
     *
     * @param Request $request Request without cookies.
     * @return Request
     */
    private function withSessionCookie(Request $request): Request
    {
        if ($this->sessionId === null) {
            return $request;
        }

        $cookies = [session_name() => $this->sessionId];
        return new Request((array)$request->query(), (array)$request->post(), $cookies, [], $this->serverOf($request), $request->content());
    }

    /**
     * @brief Returns the server variables of a request built by request().
     *
     * @param Request $request Request.
     * @return array<string, mixed>
     */
    private function serverOf(Request $request): array
    {
        return (fn(): array => $this->server)->call($request);
    }

    /**
     * @brief Switches PHP to this client's session: closes another client's one, resumes this one.
     *
     * @return void
     */
    private function activateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && session_id() !== $this->sessionId) {
            session_write_close();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // PHP keeps the ID of the closed session; a client without a session must not resume it
            session_id($this->sessionId ?? (string)session_create_id());
        }
    }

    /**
     * @brief Remembers the session the last request left active.
     *
     * @return void
     */
    private function rememberSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->sessionId = (string)session_id();
        }
    }

    /**
     * @brief Makes a request the current one for code running outside the kernel (session, CSRF).
     *
     * @param Request $request Request.
     * @return void
     */
    private function bindRequest(Request $request): void
    {
        $this->activateSession();
        Container::getInstance()->instance(Request::class, $this->withSessionCookie($request));
        Session::start();
    }
}
