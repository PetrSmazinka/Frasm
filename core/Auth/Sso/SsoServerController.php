<?php

declare(strict_types=1);

namespace Core\Auth\Sso;

use Core\Auth\Auth;
use Core\Auth\LoginThrottle;
use Core\Config\Config;
use Core\Controller\BaseController;
use Core\Exceptions\HttpException;
use Core\Exceptions\RouteNotFoundException;
use Core\Http\Response;
use Core\RateLimit\RateLimiter;
use Core\Routing\Attributes\Authorize;
use Core\Routing\Attributes\Get;
use Core\Routing\Attributes\Post;
use Core\Routing\Attributes\Put;

/**
 * @file SsoServerController.php
 * @brief Endpoints of the SSO server (/_frasm/sso/*), active when sso.server.enabled is true.
 */

/**
 * @class SsoServerController
 * @brief Authorization redirect for browsers and a JSON API for clients authenticated by their API token.
 *
 * Browser: GET /_frasm/sso/authorize?client=&redirect_uri=&state=&challenge=[&prompt=none] signs the user in (the
 * login page of this application, or nothing with prompt=none) and sends them back with a one-time code.
 * Client (Authorization: Bearer <token with role sso.client>):
 *   POST /_frasm/sso/token {code, redirect_uri, verifier} → identity
 *   POST /_frasm/sso/password {identifier, password, ip} → identity (401 wrong credentials, 429 too many attempts)
 *   GET  /_frasm/sso/users → identities, GET /_frasm/sso/users/{id} → identity (404 = no access any more)
 *   GET  /_frasm/sso/lookup?identifier= → {id, username, name, email, has_access} of an account (404 = none)
 *   PUT  /_frasm/sso/users/{id}/roles {roles: [...]} → identity after the change
 *   POST /_frasm/sso/users {name, email, expires_at, roles} → 201 {identity, password} (409 e-mail of an existing account)
 *   PUT  /_frasm/sso/users/{id}/account {expires_at?, new_password?} → {identity, password} (only accounts the client
 *        created, else 404; expires_at null = without limit, missing = keep)
 */
class SsoServerController extends BaseController
{
    /**
     * @var int Password attempts per user name (and per end-user IP, ×3) in PASSWORD_WINDOW seconds.
     */
    private const PASSWORD_ATTEMPTS = 10;

    /**
     * @var int Length of the password attempt window in seconds.
     */
    private const PASSWORD_WINDOW = 900;

    /**
     * @brief SsoServerController constructor.
     *
     * @param SsoServer $server SSO server.
     * @param RateLimiter $limiter Limits password attempts.
     */
    public function __construct(private readonly SsoServer $server, private readonly RateLimiter $limiter)
    {
    }

    /**
     * @brief Signs the user in for a client and redirects back with a code (or an error).
     *
     * @return Response
     * @throws HttpException 400 on an unknown client or redirect URI (never redirected anywhere).
     */
    #[Get('/_frasm/sso/authorize')]
    public function authorize(): Response
    {
        $this->ensureEnabled();
        $client = (string)$this->query('client', '');
        $redirectUri = (string)$this->query('redirect_uri', '');
        $config = $this->server->client($client);
        if ($config === null || !in_array($redirectUri, $config['redirect_uris'], true)) {
            throw new HttpException(400, 'Unknown SSO client or redirect URI.');
        }
        $state = (string)$this->query('state', '');
        $challenge = (string)$this->query('challenge', '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $state) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)) {
            throw new HttpException(400, 'Invalid SSO state or code challenge.');
        }

        $back = static fn(array $params): Response => Response::redirect(
            $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params + ['state' => $state])
        );

        if (!Auth::check() || Auth::isStateless()) {
            if ($this->query('prompt') === 'none') {
                return $back(['error' => 'login_required']);
            }
            $loginPath = (string)Config::get('auth.login_path', '/login');
            $return = '/_frasm/sso/authorize?' . http_build_query(array_intersect_key((array)$this->query(), array_flip(['client', 'redirect_uri', 'state', 'challenge'])));

            return Response::redirect($loginPath . (str_contains($loginPath, '?') ? '&' : '?') . 'return=' . rawurlencode($return));
        }

        if ($this->server->identity($client, (int)Auth::id()) === null) {
            return $back(['error' => 'access_denied']);
        }

        return $back(['code' => $this->server->issueCode($client, (int)Auth::id(), $redirectUri, $challenge)]);
    }

    /**
     * @brief Exchanges a code for the identity of the user.
     *
     * @return Response
     */
    #[Post('/_frasm/sso/token')]
    #[Authorize('sso.client')]
    public function token(): Response
    {
        $client = $this->client();
        $identity = $this->server->redeemCode(
            $client,
            (string)$this->input('code', ''),
            (string)$this->input('redirect_uri', ''),
            (string)$this->input('verifier', '')
        );

        return $identity === null ? Response::json(['error' => 'invalid_grant'], 400) : Response::json($identity);
    }

    /**
     * @brief Checks a user name and password typed into the login form of a client.
     *
     * @return Response
     */
    #[Post('/_frasm/sso/password')]
    #[Authorize('sso.client')]
    public function password(): Response
    {
        $client = $this->client();
        $identifier = mb_strtolower(trim((string)$this->input('identifier', '')));
        $password = (string)$this->input('password', '');
        $ip = (string)$this->input('ip', '');
        if ($identifier === '' || $password === '' || strlen($identifier) > 255 || strlen($password) > 1024) {
            return Response::json(['error' => 'invalid_credentials'], 401);
        }

        $keys = ["sso-password|{$client}|user|{$identifier}" => self::PASSWORD_ATTEMPTS, "sso-password|{$client}|ip|{$ip}" => self::PASSWORD_ATTEMPTS * 3];
        foreach ($keys as $key => $limit) {
            if ($this->limiter->tooManyAttempts($key, $limit)) {
                return Response::json(['error' => 'too_many_attempts', 'retry_after' => $this->limiter->availableIn($key)], 429);
            }
        }

        $identity = $this->server->verifyPassword($client, $identifier, $password);
        if ($identity === null) {
            foreach (array_keys($keys) as $key) {
                $this->limiter->hit($key, self::PASSWORD_WINDOW);
            }
            return Response::json(['error' => 'invalid_credentials'], 401);
        }

        return Response::json($identity);
    }

    /**
     * @brief Lists the users of the client.
     *
     * @return Response
     */
    #[Get('/_frasm/sso/users')]
    #[Authorize('sso.client')]
    public function users(): Response
    {
        return Response::json($this->server->users($this->client()));
    }

    /**
     * @brief Finds an account by exact user name or e-mail (to grant it access).
     *
     * @return Response 404 when there is no such active account.
     */
    #[Get('/_frasm/sso/lookup')]
    #[Authorize('sso.client')]
    public function lookup(): Response
    {
        $identifier = trim((string)$this->query('identifier', ''));
        $account = $identifier === '' || strlen($identifier) > 255 ? null : $this->server->lookup($this->client(), $identifier);

        return $account === null ? Response::json(['error' => 'not_found'], 404) : Response::json($account);
    }

    /**
     * @brief Describes one user (clients refresh signed-in users with it).
     *
     * @param int $id User ID.
     * @return Response 404 when the user does not exist, expired or has no role of the client.
     */
    #[Get('/_frasm/sso/users/{id}')]
    #[Authorize('sso.client')]
    public function user(int $id): Response
    {
        $identity = $this->server->identity($this->client(), $id);

        return $identity === null ? Response::json(['error' => 'not_found'], 404) : Response::json($identity);
    }

    /**
     * @brief Replaces the client's roles of a user.
     *
     * @param int $id User ID.
     * @return Response
     */
    #[Put('/_frasm/sso/users/{id}/roles')]
    #[Authorize('sso.client')]
    public function roles(int $id): Response
    {
        $roles = $this->input('roles', []);
        if (!is_array($roles)) {
            return Response::json(['error' => 'invalid_roles'], 422);
        }
        try {
            $identity = $this->server->setRoles($this->client(), $id, array_values($roles));
        } catch (\InvalidArgumentException) {
            return Response::json(['error' => 'invalid_roles'], 422);
        }

        return Response::json(['identity' => $identity]);
    }

    /**
     * @brief Creates an account (a guest) with the client's roles and a generated password.
     *
     * @return Response
     */
    #[Post('/_frasm/sso/users')]
    #[Authorize('sso.client')]
    public function create(): Response
    {
        $client = $this->client();
        $roles = $this->input('roles', []);
        $expires = $this->input('expires_at');
        try {
            $result = $this->server->createUser($client, (string)$this->input('name', ''), (string)$this->input('email', ''),
                is_string($expires) && $expires !== '' ? $expires : null, is_array($roles) ? array_values($roles) : []);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage() === 'exists' ? Response::json(['error' => 'exists'], 409) : Response::json(['error' => 'invalid'], 422);
        }

        return Response::json($result, 201);
    }

    /**
     * @brief Changes the validity of an account the client created, or issues it a new password.
     *
     * @param int $id User ID.
     * @return Response
     */
    #[Put('/_frasm/sso/users/{id}/account')]
    #[Authorize('sso.client')]
    public function account(int $id): Response
    {
        $client = $this->client();
        $body = (array)($this->request()->json() ?? []);
        $expires = array_key_exists('expires_at', $body) ? (is_string($body['expires_at']) && $body['expires_at'] !== '' ? $body['expires_at'] : null) : false;
        try {
            $result = $this->server->manage($client, $id, $expires, (bool)($body['new_password'] ?? false));
        } catch (\InvalidArgumentException) {
            return Response::json(['error' => 'invalid'], 422);
        }

        return $result === null ? Response::json(['error' => 'not_found'], 404) : Response::json($result);
    }

    /**
     * @brief Changes the profile of a user: JSON {name?, email?, phone?} (only what is sent; '' phone removes it).
     *
     * @param int $id User ID.
     * @return Response {identity}; 404 when the user is not one of the client; 422 {error: invalid_name, invalid_email,
     *         invalid_phone}; 409 {error: email_taken} when another account has the e-mail.
     */
    #[Put('/_frasm/sso/users/{id}/profile')]
    #[Authorize('sso.client')]
    public function profile(int $id): Response
    {
        $client = $this->client();
        $body = (array)($this->request()->json() ?? []);
        $profile = array_intersect_key($body, ['name' => 1, 'email' => 1, 'phone' => 1]);
        if ($profile === [] || array_filter($profile, static fn(mixed $value): bool => !is_string($value)) !== []) {
            return Response::json(['error' => 'invalid'], 422);
        }
        try {
            $identity = $this->server->setProfile($client, $id, $profile);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getMessage() === 'email_taken' ? 409 : 422);
        }

        return $identity === null ? Response::json(['error' => 'not_found'], 404) : Response::json(['identity' => $identity]);
    }

    /**
     * @brief Issues a link to set a new password: JSON {identifier}. The client sends the link to the user (its own
     *        e-mail and address) and completes it with password-reset/complete.
     *
     * @return Response {token, identity}; 404 when no account of the client has the identifier (the client must not
     *         tell its user); 429 after too many requests for one identifier or address.
     */
    #[Post('/_frasm/sso/password-reset')]
    #[Authorize('sso.client')]
    public function passwordReset(): Response
    {
        $client = $this->client();
        $identifier = trim((string)$this->input('identifier', ''));
        $ip = trim((string)$this->input('ip', ''));
        $throttle = new LoginThrottle($this->limiter);
        if ($identifier === '' || $throttle->blocked($identifier, $ip, 'reset') !== null) {
            return Response::json(['error' => $identifier === '' ? 'invalid' : 'throttled'], $identifier === '' ? 422 : 429);
        }
        $throttle->failed($identifier, $ip, 'reset');
        $reset = $this->server->requestPasswordReset($client, $identifier);

        return $reset === null ? Response::json(['error' => 'not_found'], 404) : Response::json($reset);
    }

    /**
     * @brief Sets a new password with a link: JSON {token, password}.
     *
     * @return Response {identity}; 422 {error: invalid_token | weak_password}.
     */
    #[Post('/_frasm/sso/password-reset/complete')]
    #[Authorize('sso.client')]
    public function completePasswordReset(): Response
    {
        $client = $this->client();
        try {
            $identity = $this->server->completePasswordReset($client, (string)$this->input('token', ''), (string)$this->input('password', ''));
        } catch (\InvalidArgumentException) {
            return Response::json(['error' => 'weak_password'], 422);
        }

        return $identity === null ? Response::json(['error' => 'invalid_token'], 422) : Response::json(['identity' => $identity]);
    }

    /**
     * @brief Sends the profile photo of a user (JPEG; clients keep a copy, version in the identity).
     *
     * @param int $id User ID.
     * @return Response 404 without a photo or when the user is not one of the client.
     */
    #[Get('/_frasm/sso/users/{id}/avatar')]
    #[Authorize('sso.client')]
    public function avatar(int $id): Response
    {
        $path = $this->server->avatar($this->client(), $id);

        return $path === null ? Response::json(['error' => 'not_found'], 404) : Response::download($path, "avatar-{$id}.jpg", 'image/jpeg', true);
    }

    /**
     * @brief Returns the client calling the API.
     *
     * @return string
     * @throws RouteNotFoundException When the server is disabled.
     * @throws HttpException 403 when the API token is not a configured client.
     */
    private function client(): string
    {
        $this->ensureEnabled();
        $client = $this->server->clientOf(Auth::id());
        if ($client === null) {
            throw new HttpException(403, 'The API token is not a configured SSO client.');
        }

        return $client;
    }

    /**
     * @brief Hides the endpoints when this application is not an SSO server.
     *
     * @return void
     * @throws RouteNotFoundException
     */
    private function ensureEnabled(): void
    {
        if (!SsoServer::isEnabled()) {
            throw new RouteNotFoundException('Not Found');
        }
    }
}
