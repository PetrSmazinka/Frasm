<?php

declare(strict_types=1);

namespace Core\Auth\Sso;

use Core\Auth\Auth;
use Core\Auth\Avatars;
use Core\Config\Config;
use Core\Container\Container;
use Core\DB\DB;
use Core\Exceptions\AuthException;
use Core\Http\Request;
use Core\Routing\Domains;
use Core\Session\Session;

/**
 * @file SsoClient.php
 * @brief Client side of single sign-on: users of another Frasm application (the SSO server) sign in here.
 */

/**
 * @class SsoClient
 * @brief Redirect login (authorization code + PKCE), password login through the server API, local shadow accounts
 *        and their refresh.
 *
 * Every remote user gets a local row in `frasm_users` (external_id = their ID at the server), so sessions,
 * remember-me tokens and application tables work as with local users. The row keeps the client roles as
 * permissions and `password_hash` = 'sso:' + the server's credential stamp, which can never match a password
 * (no local password login) and changes when the user changes their password at the server, ending the
 * sessions here (Auth::refreshIdentity()).
 */
class SsoClient
{
    /**
     * @var string Session key of the pending authorization request.
     */
    private const SESSION_REQUEST = '_sso_request';

    /**
     * @var string Session key marking that a silent login already failed in this session.
     */
    private const SESSION_SILENT = '_sso_silent_failed';

    /**
     * @var string Cookie set by logout(): no silent login in this browser until the user signs in again.
     */
    private const SIGNED_OUT_COOKIE = 'frasm_sso_signed_out';

    /**
     * @brief Checks whether this application signs users in through an SSO server.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool)Config::get('sso.client.enabled', false);
    }

    /**
     * @brief Returns the URL of the server's authorization page and remembers the request in the session.
     *
     * @param string $return Where to go after signing in (a local path or a URL of a domain of this application).
     * @param bool $silent Only check whether the user is signed in at the server (no login form there).
     * @return string
     */
    public function authorizeUrl(string $return, bool $silent = false): string
    {
        $state = self::random();
        $verifier = self::random() . self::random();
        Session::set(self::SESSION_REQUEST, ['state' => $state, 'verifier' => $verifier, 'return' => $this->safeReturn($return), 'silent' => $silent]);

        return $this->server() . '/_frasm/sso/authorize?' . http_build_query([
            'client'       => (string)Config::get('sso.client.client'),
            'redirect_uri' => $this->redirectUri(),
            'state'        => $state,
            'challenge'    => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ] + ($silent ? ['prompt' => 'none'] : []));
    }

    /**
     * @brief Checks whether a silent login may be tried (once per session until it fails, never after logout()
     *        until the user signs in again).
     *
     * @return bool
     */
    public function canTrySilent(): bool
    {
        return Session::get(self::SESSION_SILENT) === null && self::request()->cookie(self::SIGNED_OUT_COOKIE) === null;
    }

    /**
     * @brief Signs the user out here and stops silent logins in this browser (otherwise the login page would sign
     *        them in again at once while they are signed in at the server). The session at the server stays.
     *
     * @return void
     */
    public function logout(): void
    {
        Auth::logout();
        Session::set(self::SESSION_SILENT, true);
        self::signedOutCookie(true);
    }

    /**
     * @brief Finishes the redirect login.
     *
     * @param array<string, mixed> $query Query of the callback (code or error, state).
     * An account without access here (error access_denied) goes back to the login page of the application with
     * `denied=1` – the page says so in its own look and offers another account; the silent login is switched off for
     * the session, so the page does not send the user back to the server again.
     *
     * @return string Where to go next.
     * @throws AuthException 400 on a forged or stale request, 403 when signing in failed otherwise, 502 when the
     *                       server cannot be reached.
     */
    public function callback(array $query): string
    {
        $request = Session::get(self::SESSION_REQUEST);
        Session::remove(self::SESSION_REQUEST);
        if (!is_array($request) || !is_string($query['state'] ?? null) || !hash_equals((string)$request['state'], $query['state'])) {
            throw new AuthException('The sign-in request expired, please try again.', 400);
        }

        if (isset($query['error'])) {
            if ($request['silent'] && $query['error'] === 'login_required') {
                Session::set(self::SESSION_SILENT, true);
                return $this->loginPage((string)$request['return']);
            }
            if ($query['error'] === 'access_denied') {
                Session::set(self::SESSION_SILENT, true);
                return $this->loginPage((string)$request['return'], true);
            }
            throw new AuthException('Signing in failed.', 403);
        }

        $identity = $this->call('POST', '/_frasm/sso/token', [
            'code' => (string)($query['code'] ?? ''), 'redirect_uri' => $this->redirectUri(), 'verifier' => (string)$request['verifier'],
        ]);
        if ($identity['status'] !== 200) {
            throw new AuthException('Signing in failed.', 400);
        }
        $this->login($identity['body'], (bool)Config::get('sso.client.remember', true));

        return (string)$request['return'];
    }

    /**
     * @brief Signs in with a user name and password checked by the server.
     *
     * @param string $identifier User name or e-mail.
     * @param string $password Password.
     * @param bool $remember Keep the user signed in (remember-me cookie).
     * @param string $ip IP address of the user (the server limits attempts per address).
     * @return string|null Null on success, otherwise the reason: 'invalid', 'throttled' or 'unavailable'.
     */
    public function passwordLogin(string $identifier, string $password, bool $remember, string $ip): ?string
    {
        try {
            $response = $this->call('POST', '/_frasm/sso/password', ['identifier' => $identifier, 'password' => $password, 'ip' => $ip]);
        } catch (AuthException) {
            return 'unavailable';
        }
        if ($response['status'] === 429) {
            return 'throttled';
        }
        if ($response['status'] !== 200) {
            return 'invalid';
        }
        $this->login($response['body'], $remember);

        return null;
    }

    /**
     * @brief Returns the users of this client at the server.
     *
     * @return list<array<string, mixed>> Identities.
     * @throws AuthException When the server cannot be reached.
     */
    public function users(): array
    {
        $response = $this->call('GET', '/_frasm/sso/users');
        return $response['status'] === 200 && array_is_list($response['body']) ? $response['body'] : [];
    }

    /**
     * @brief Copies all users of this client from the server into local accounts (so they can be set up before they
     *        sign in for the first time) and disables local accounts that lost access.
     *
     * @return array<string, int> External ID => local user ID of the users with access.
     * @throws AuthException When the server cannot be reached.
     */
    public function syncUsers(): array
    {
        $response = $this->call('GET', '/_frasm/sso/users');
        if ($response['status'] !== 200 || !array_is_list($response['body'])) {
            throw new AuthException('The SSO server did not list the users.', 502);
        }

        $local = [];
        foreach ($response['body'] as $identity) {
            if (is_array($identity)) {
                $local[(string)$identity['id']] = $this->store($identity);
            }
        }
        $linked = DB::getInstance()->select("SELECT `external_id` FROM `frasm_users` WHERE `external_id` IS NOT NULL AND `permissions` <> ''");
        foreach (array_column($linked, 'external_id') as $externalId) {
            if (!isset($local[(string)$externalId])) {
                $this->revoke((string)$externalId);
            }
        }

        return $local;
    }

    /**
     * @brief Finds an account at the server by exact user name or e-mail.
     *
     * @param string $identifier User name or e-mail.
     * @return array{id: string, username: string, name: string, email: string, has_access: bool}|null
     * @throws AuthException When the server cannot be reached.
     */
    public function lookup(string $identifier): ?array
    {
        $response = $this->call('GET', '/_frasm/sso/lookup?' . http_build_query(['identifier' => $identifier]));
        return $response['status'] === 200 ? $response['body'] : null;
    }

    /**
     * @brief Returns the local user ID of a user at the server, if the user has a local account.
     *
     * @param string $externalId User ID at the server.
     * @return int|null
     */
    public function localId(string $externalId): ?int
    {
        $id = DB::getInstance()->selectValue('SELECT `id` FROM `frasm_users` WHERE `external_id` = ?', [$externalId]);
        return $id === null ? null : (int)$id;
    }

    /**
     * @brief Changes the roles of a user at the server and updates the local account.
     *
     * @param string $externalId User ID at the server.
     * @param list<string> $roles Roles of this client.
     * @return array<string, mixed>|null Identity after the change (null = the user has no access any more).
     * @throws AuthException When the server cannot be reached or refuses the roles.
     */
    public function setRoles(string $externalId, array $roles): ?array
    {
        $response = $this->call('PUT', '/_frasm/sso/users/' . rawurlencode($externalId) . '/roles', ['roles' => array_values($roles)]);
        if ($response['status'] !== 200) {
            throw new AuthException('The SSO server refused the roles.', 422);
        }
        $identity = $response['body']['identity'] ?? null;
        is_array($identity) ? $this->store($identity) : $this->revoke($externalId);

        return is_array($identity) ? $identity : null;
    }

    /**
     * @brief Creates an account at the server (a time-limited guest) and its local copy.
     *
     * @param string $name Name.
     * @param string $email E-mail.
     * @param string|null $expiresAt Validity (Y-m-d H:i:s), null = without limit.
     * @param list<string> $roles Roles of this client.
     * @return array{identity: array<string, mixed>, password: string, local_id: int}|null Null when an account with the
     *         e-mail exists already (give it roles with lookup() and setRoles() instead).
     * @throws AuthException When the server cannot be reached or refuses the data.
     */
    public function createUser(string $name, string $email, ?string $expiresAt, array $roles): ?array
    {
        $response = $this->call('POST', '/_frasm/sso/users', ['name' => $name, 'email' => $email, 'expires_at' => $expiresAt, 'roles' => array_values($roles)]);
        if ($response['status'] === 409) {
            return null;
        }
        if ($response['status'] !== 201 || !is_array($response['body']['identity'] ?? null)) {
            throw new AuthException('The SSO server refused to create the account.', 422);
        }

        return ['identity' => $response['body']['identity'], 'password' => (string)$response['body']['password'],
            'local_id' => $this->store($response['body']['identity'])];
    }

    /**
     * @brief Changes an account this client created: validity and/or a new password.
     *
     * @param string $externalId User ID at the server.
     * @param string|null|false $expiresAt Validity (Y-m-d H:i:s), null = without limit, false = keep.
     * @param bool $newPassword Generate a new password.
     * @return array{identity: array<string, mixed>|null, password: string|null}|null Null when the account was not
     *         created by this client.
     * @throws AuthException When the server cannot be reached or refuses the data.
     */
    public function manageUser(string $externalId, string|null|false $expiresAt, bool $newPassword = false): ?array
    {
        $body = ['new_password' => $newPassword] + ($expiresAt === false ? [] : ['expires_at' => $expiresAt]);
        $response = $this->call('PUT', '/_frasm/sso/users/' . rawurlencode($externalId) . '/account', $body);
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] !== 200) {
            throw new AuthException('The SSO server refused the change of the account.', 422);
        }
        $identity = $response['body']['identity'] ?? null;
        is_array($identity) ? $this->store($identity) : $this->revoke($externalId);

        return ['identity' => is_array($identity) ? $identity : null, 'password' => is_string($response['body']['password'] ?? null) ? $response['body']['password'] : null];
    }

    /**
     * @brief Re-reads a user from the server when the local copy is older than sso.client.refresh seconds (or at
     *        once: the server said it changed).
     *
     * @param int $localId Local user ID.
     * @param bool $force Read it whatever its age.
     * @return bool False when the user lost access (the local account is disabled), true otherwise
     *              (also when the server is unreachable: the local copy is used).
     */
    public function refresh(int $localId, bool $force = false): bool
    {
        $row = DB::getInstance()->selectOne(
            'SELECT `external_id` FROM `frasm_users`
             WHERE `id` = ? AND `external_id` IS NOT NULL
               AND (? OR `synced_at` IS NULL OR `synced_at` < NOW() - INTERVAL ? SECOND) LIMIT 1',
            [$localId, $force ? 1 : 0, max(30, (int)Config::get('sso.client.refresh', 300))]
        );
        if ($row === null) {
            return true;
        }

        try {
            $response = $this->call('GET', '/_frasm/sso/users/' . rawurlencode((string)$row['external_id']));
        } catch (AuthException) {
            return true;
        }
        if ($response['status'] === 404) {
            $this->revoke((string)$row['external_id']);
            return false;
        }
        if ($response['status'] === 200) {
            $this->store($response['body']);
        }

        return true;
    }

    /**
     * @brief Signs a remote user in locally.
     *
     * @param array<string, mixed> $identity Identity from the server.
     * @param bool $remember Issue a remember-me cookie.
     * @return void
     */
    private function login(array $identity, bool $remember): void
    {
        $localId = $this->store($identity);
        Session::remove(self::SESSION_SILENT);
        if (self::request()->cookie(self::SIGNED_OUT_COOKIE) !== null) {
            self::signedOutCookie(false);
        }
        Auth::login($localId, array_values(array_map('strval', (array)$identity['roles'])), $remember);
    }

    /**
     * @brief Creates or updates the local account of a remote user.
     *
     * A local account with the same user name or e-mail that is not linked yet (e.g. the administrator created by
     * `db:seed`) is taken over: it becomes the account of the remote user.
     *
     * @param array<string, mixed> $identity Identity from the server.
     * @return int Local user ID.
     * @throws AuthException On a malformed identity.
     */
    private function store(array $identity): int
    {
        foreach (['id', 'username', 'name', 'email', 'stamp'] as $key) {
            if (!is_string($identity[$key] ?? null) || $identity[$key] === '') {
                throw new AuthException('The SSO server sent an invalid identity.', 502);
            }
        }
        $data = [
            'username'      => $identity['username'],
            'name'          => $identity['name'],
            'email'         => $identity['email'],
            'phone'         => is_string($identity['phone'] ?? null) && $identity['phone'] !== '' ? mb_substr($identity['phone'], 0, 32) : null,
            'password_hash' => 'sso:' . $identity['stamp'],
            'permissions'   => implode(',', array_map('strval', (array)($identity['roles'] ?? []))),
            'expires_at'    => is_string($identity['expires_at'] ?? null) ? $identity['expires_at'] : null,
        ];

        $localId = DB::getInstance()->transactional(function (DB $db) use ($identity, $data): int {
            $id = $db->selectValue('SELECT `id` FROM `frasm_users` WHERE `external_id` = ? FOR UPDATE', [$identity['id']])
                ?? $db->selectValue(
                    'SELECT `id` FROM `frasm_users` WHERE `external_id` IS NULL AND (`username` = ? OR `email` = ?) LIMIT 1 FOR UPDATE',
                    [$identity['username'], $identity['email']]
                );
            if ($id === null) {
                return (int)$db->insert('frasm_users', $data + ['external_id' => $identity['id'], 'synced_at' => date('Y-m-d H:i:s')]);
            }
            $db->query(
                'UPDATE `frasm_users` SET `username` = ?, `name` = ?, `email` = ?, `phone` = ?, `password_hash` = ?, `permissions` = ?, `expires_at` = ?,
                    `external_id` = ?, `synced_at` = NOW() WHERE `id` = ?',
                [...array_values($data), $identity['id'], $id]
            );

            return (int)$id;
        });
        if (array_key_exists('avatar', $identity)) {
            $this->syncAvatar($localId, (string)$identity['id'], is_string($identity['avatar']) && $identity['avatar'] !== '' ? $identity['avatar'] : null);
        }

        return $localId;
    }

    /**
     * @brief Keeps the local copy of a user's profile photo in line with the server (downloaded only when its version
     *        changed; an unreachable server keeps the old copy).
     *
     * @param int $localId Local user ID.
     * @param string $externalId User ID at the server.
     * @param string|null $version Version on the server, null = no photo.
     * @return void
     */
    private function syncAvatar(int $localId, string $externalId, ?string $version): void
    {
        $local = DB::getInstance()->selectValue('SELECT `avatar` FROM `frasm_users` WHERE `id` = ?', [$localId]);
        if ($version === null) {
            if ($local !== null) {
                Avatars::remove($localId);
            }
            return;
        }
        if ($local === $version && Avatars::path($localId) !== null) {
            return;
        }
        try {
            $image = $this->download('/_frasm/sso/users/' . rawurlencode($externalId) . '/avatar');
            if ($image !== null) {
                Avatars::store($localId, $image, $version);
            }
        } catch (AuthException | \InvalidArgumentException) {
            // Tried again with the next refresh
        }
    }

    /**
     * @brief Changes the profile of a user at the server (their profile in this application): name, e-mail and phone,
     *        each only when given.
     *
     * @param string $externalId User ID at the server.
     * @param array{name?: string, email?: string, phone?: string} $profile New values ('' phone removes it).
     * @return bool False when the user is not one of this client.
     * @throws \InvalidArgumentException With the reason: invalid_name, invalid_email, email_taken, invalid_phone.
     * @throws AuthException When the server cannot be reached.
     */
    public function setProfile(string $externalId, array $profile): bool
    {
        $response = $this->call('PUT', '/_frasm/sso/users/' . rawurlencode($externalId) . '/profile', $profile);
        if ($response['status'] === 422 || $response['status'] === 409) {
            throw new \InvalidArgumentException((string)($response['body']['error'] ?? 'invalid'));
        }
        if ($response['status'] !== 200 || !is_array($response['body']['identity'] ?? null)) {
            return false;
        }
        $this->store($response['body']['identity']);

        return true;
    }

    /**
     * @brief Disables the local account of a user who lost access (roles removed, sessions end, rows stay).
     *
     * @param string $externalId User ID at the server.
     * @return void
     */
    private function revoke(string $externalId): void
    {
        $db = DB::getInstance();
        $db->query("UPDATE `frasm_users` SET `permissions` = '', `password_hash` = 'sso:revoked', `synced_at` = NOW() WHERE `external_id` = ?", [$externalId]);
        $db->query('DELETE t FROM `frasm_remember_tokens` t JOIN `frasm_users` u ON u.`id` = t.`user_id` WHERE u.`external_id` = ?', [$externalId]);
    }

    /**
     * @brief Calls the server API with the client's token.
     *
     * @param string $method HTTP method.
     * @param string $path Path of the API.
     * @param array<string, mixed>|null $body JSON body.
     * @return array{status: int, body: array<mixed>}
     * @throws AuthException 502 when the server cannot be reached.
     */
    protected function call(string $method, string $path, ?array $body = null): array
    {
        $curl = curl_init($this->server() . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | (str_starts_with($this->server(), 'http://') ? CURLPROTO_HTTP : 0),
            CURLOPT_TIMEOUT        => max(1, (int)Config::get('sso.client.timeout', 10)),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . (string)Config::get('sso.client.token'),
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ] + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)] : []));
        $content = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($content === false || $status === 0 || $status >= 500) {
            throw new AuthException('The SSO server is not available' . ($error !== '' ? " ({$error})" : '') . '.', 502);
        }
        $decoded = json_decode((string)$content, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * @brief Asks the server for a link to set a new password of a user (the application sends it in its own e-mail).
     *
     * @param string $identifier User name or e-mail as typed.
     * @param string $ip Address of the user (the server limits requests per address too).
     * @return array{token: string, name: string, email: string}|null Null when no account of this client has it (say
     *         nothing different to the user).
     * @throws AuthException 429 after too many requests, 502 when the server cannot be reached.
     */
    public function requestPasswordReset(string $identifier, string $ip): ?array
    {
        $response = $this->call('POST', '/_frasm/sso/password-reset', ['identifier' => $identifier, 'ip' => $ip]);
        if ($response['status'] === 429) {
            throw new AuthException('Too many requests.', 429);
        }
        $identity = $response['body']['identity'] ?? null;
        if ($response['status'] !== 200 || !is_string($response['body']['token'] ?? null) || !is_array($identity)) {
            return null;
        }

        return ['token' => $response['body']['token'], 'name' => (string)($identity['name'] ?? ''), 'email' => (string)($identity['email'] ?? '')];
    }

    /**
     * @brief Sets a new password with a link from requestPasswordReset() (the local copy follows).
     *
     * @param string $token Token of the link.
     * @param string $password New password.
     * @return bool False when the link is invalid or expired.
     * @throws \InvalidArgumentException 'weak_password'.
     * @throws AuthException When the server cannot be reached.
     */
    public function completePasswordReset(string $token, string $password): bool
    {
        $response = $this->call('POST', '/_frasm/sso/password-reset/complete', ['token' => $token, 'password' => $password]);
        if ($response['status'] === 422 && ($response['body']['error'] ?? '') === 'weak_password') {
            throw new \InvalidArgumentException('weak_password');
        }
        if ($response['status'] !== 200 || !is_array($response['body']['identity'] ?? null)) {
            return false;
        }
        $this->store($response['body']['identity']);

        return true;
    }

    /**
     * @brief Downloads a file of the server API (a profile photo).
     *
     * @param string $path Path of the API.
     * @return string|null Content, null on 404.
     * @throws AuthException 502 when the server cannot be reached or fails.
     */
    protected function download(string $path): ?string
    {
        $curl = curl_init($this->server() . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | (str_starts_with($this->server(), 'http://') ? CURLPROTO_HTTP : 0),
            CURLOPT_TIMEOUT        => max(1, (int)Config::get('sso.client.timeout', 10)),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . (string)Config::get('sso.client.token')],
        ]);
        $content = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($status === 404) {
            return null;
        }
        if ($content === false || $status !== 200) {
            throw new AuthException('The SSO server is not available.', 502);
        }

        return (string)$content;
    }

    /**
     * @brief Returns the base URL of the server.
     *
     * @return string
     * @throws AuthException When it is not configured.
     */
    private function server(): string
    {
        $server = rtrim((string)Config::get('sso.client.server', ''), '/');
        if (!preg_match('#^https?://[^/?\#]+$#', $server)) {
            throw new AuthException('Set sso.client.server to the URL of the SSO server (e.g. https://example.com).', 500);
        }

        return $server;
    }

    /**
     * @brief Returns the callback URL registered at the server.
     *
     * @return string
     */
    private function redirectUri(): string
    {
        return (string)Config::get('sso.client.redirect_uri');
    }

    /**
     * @brief Returns the login page of this application (with the page to return to).
     *
     * @param string $return Page to return to.
     * @return string
     */
    private function loginPage(string $return, bool $denied = false): string
    {
        $loginPath = (string)Config::get('auth.login_path', '/login');
        // An absolute target (another subdomain): its own login page, returning to the path there
        $origin = '';
        if (Auth::safeReturnPath($return) === null && preg_match('#^(https://[^/?\#]+)(.*)$#', $return, $parts)) {
            $origin = $parts[1];
            $return = $parts[2] === '' ? '/' : $parts[2];
        }

        $query = array_filter(['return' => $return === '/' ? null : $return, 'denied' => $denied ? '1' : null]);

        return $origin . $loginPath . ($query === [] ? '' : (str_contains($loginPath, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * @brief Accepts a local path, or an https URL on a host of this application (sign-in on one subdomain,
     *        return to another); anything else becomes '/'.
     *
     * @param string $return Requested target.
     * @return string
     */
    private function safeReturn(string $return): string
    {
        if (Auth::safeReturnPath($return) !== null) {
            return $return;
        }
        $parts = parse_url($return);
        $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        if (is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass'])
            && $host !== '' && Container::getInstance()->get(Domains::class)->resolve($host) !== null
            && !preg_match('/[\x00-\x1F\x7F]/', $return)) {
            return $return;
        }

        return '/';
    }

    /**
     * @brief Sets or clears the cookie stopping silent logins (for all subdomains sharing the session).
     *
     * @param bool $set Set (one year) or clear.
     * @return void
     */
    private static function signedOutCookie(bool $set): void
    {
        setcookie(self::SIGNED_OUT_COOKIE, $set ? '1' : '', [
            'expires'  => $set ? time() + 365 * 86400 : time() - 3600,
            'path'     => '/',
            'domain'   => Session::cookieDomain(),
            'secure'   => self::request()->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @brief Returns the current request.
     *
     * @return Request
     */
    private static function request(): Request
    {
        $container = Container::getInstance();
        return $container->bound(Request::class) ? $container->get(Request::class) : Request::fromGlobals();
    }

    /**
     * @brief Returns a random URL-safe string (128 bits).
     *
     * @return string
     */
    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
