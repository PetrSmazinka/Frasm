<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Auth\Sso\SsoClient;
use Core\Container\Container;
use Core\Controller\BaseController;
use Core\DB\DB;
use Core\Exceptions\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Logger\LoggerInterface;
use Core\RateLimit\RateLimiter;
use Core\Routing\Attributes\Authorize;
use Core\Routing\Attributes\Post;
use InvalidArgumentException;

/**
 * @file PasskeyController.php
 * @brief Endpoints of the passkeys (WebAuthn) used by public/js/frasm-passkey.js.
 */

/**
 * @class PasskeyController
 * @brief Registration of a passkey by a signed-in user, sign-in with one, removal; active when
 *        `auth.passkeys.enabled` is true (otherwise 404).
 *
 * JSON in and out; the binary fields are base64url. A sign-in starts a normal session of the user (with the
 * remember-me cookie when asked for); an SSO client reads the account from its server at once and refuses it when the
 * access ended there. Attempts are limited per address (ATTEMPTS a minute).
 */
class PasskeyController extends BaseController
{
    /**
     * @var int Requests of the sign-in endpoints a minute and address.
     */
    private const ATTEMPTS = 20;

    /**
     * @brief PasskeyController constructor.
     *
     * @param Passkeys $passkeys Passkeys.
     * @param RateLimiter $limiter Attempts.
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(private readonly Passkeys $passkeys, private readonly RateLimiter $limiter, private readonly LoggerInterface $logger)
    {
    }

    /**
     * @brief Options to create a passkey of the signed-in user.
     *
     * @param Request $request Request.
     * @return Response
     */
    #[Post('/_frasm/passkeys/register/options')]
    #[Authorize]
    public function registrationOptions(Request $request): Response
    {
        $this->ensureEnabled();
        try {
            return Response::json($this->passkeys->registrationOptions((int)Auth::id(), $request->host()));
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    /**
     * @brief Stores the passkey just created: JSON {name, credential: {rawId, response: {clientDataJSON,
     *        attestationObject, transports}}}.
     *
     * @param Request $request Request.
     * @return Response {id}; 422 {error}.
     */
    #[Post('/_frasm/passkeys/register')]
    #[Authorize]
    public function register(Request $request): Response
    {
        $this->ensureEnabled();
        $body = (array)($request->json() ?? []);
        $response = (array)($body['credential']['response'] ?? []);
        $clientData = Passkeys::decode((string)($response['clientDataJSON'] ?? ''));
        $attestation = Passkeys::decode((string)($response['attestationObject'] ?? ''));
        if ($clientData === null || $attestation === null) {
            return Response::json(['error' => 'malformed'], 422);
        }
        try {
            $id = $this->passkeys->register((int)Auth::id(), self::origin($request), $clientData, $attestation, (string)($body['name'] ?? ''),
                array_map('strval', (array)($response['transports'] ?? [])));
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
        $this->logger->info('User {user_id} added a passkey.', ['user_id' => Auth::id(), 'ip' => $request->ip()]);

        return Response::json(['id' => $id]);
    }

    /**
     * @brief Options to sign in with a passkey.
     *
     * @param Request $request Request.
     * @return Response
     */
    #[Post('/_frasm/passkeys/login/options')]
    public function authenticationOptions(Request $request): Response
    {
        $this->ensureEnabled();
        $this->limit($request);

        return Response::json($this->passkeys->authenticationOptions($request->host()));
    }

    /**
     * @brief Signs in with a passkey: JSON {credential: {rawId, response: {clientDataJSON, authenticatorData,
     *        signature, userHandle}}, remember, return}.
     *
     * @param Request $request Request.
     * @return Response {redirect}; 401 {error} when the passkey is not valid or the account cannot sign in.
     */
    #[Post('/_frasm/passkeys/login')]
    public function login(Request $request): Response
    {
        $this->ensureEnabled();
        $this->limit($request);
        $body = (array)($request->json() ?? []);
        $credential = (array)($body['credential'] ?? []);
        $response = (array)($credential['response'] ?? []);
        $fields = [];
        foreach (['rawId' => $credential['rawId'] ?? '', 'clientDataJSON' => $response['clientDataJSON'] ?? '', 'authenticatorData' => $response['authenticatorData'] ?? '',
            'signature' => $response['signature'] ?? ''] as $name => $value) {
            $fields[$name] = Passkeys::decode((string)$value);
            if ($fields[$name] === null || $fields[$name] === '') {
                return Response::json(['error' => 'malformed'], 422);
            }
        }
        $userHandle = isset($response['userHandle']) && $response['userHandle'] !== null ? Passkeys::decode((string)$response['userHandle']) : null;

        try {
            $userId = $this->passkeys->authenticate(self::origin($request), $fields['rawId'], $fields['clientDataJSON'], $fields['authenticatorData'], $fields['signature'], $userHandle);
        } catch (InvalidArgumentException $exception) {
            $this->logger->warning('Failed passkey sign-in ({reason}).', ['reason' => $exception->getMessage(), 'ip' => $request->ip()]);
            return Response::json(['error' => $exception->getMessage()], 401);
        }

        // An SSO client asks its server first: access may have ended there
        if (SsoClient::isEnabled() && !Container::getInstance()->get(SsoClient::class)->refresh($userId, true)) {
            return Response::json(['error' => 'no_access'], 401);
        }
        $user = DB::getInstance()->selectOne('SELECT `permissions`, `expires_at` FROM `frasm_users` WHERE `id` = ?', [$userId]);
        $roles = $user === null ? [] : DatabaseUserProvider::parseRoles((string)$user['permissions']);
        if ($user === null || ($user['expires_at'] !== null && (string)$user['expires_at'] <= date('Y-m-d H:i:s')) || (SsoClient::isEnabled() && $roles === [])) {
            return Response::json(['error' => 'no_access'], 401);
        }
        Auth::login($userId, $roles, (bool)($body['remember'] ?? false));
        $this->logger->info('User {user_id} signed in with a passkey.', ['user_id' => $userId, 'ip' => $request->ip()]);

        return Response::json(['redirect' => Auth::safeReturnPath($body['return'] ?? null) ?? '/']);
    }

    /**
     * @brief Removes a passkey of the signed-in user.
     *
     * @param int $id Passkey ID.
     * @return Response {removed: true}; 404.
     */
    #[Post('/_frasm/passkeys/{id}/delete')]
    #[Authorize]
    public function delete(int $id): Response
    {
        $this->ensureEnabled();
        if (!$this->passkeys->remove((int)Auth::id(), $id)) {
            return Response::json(['error' => 'not_found'], 404);
        }

        return Response::json(['removed' => true]);
    }

    /**
     * @brief Origin of the page that called (WebAuthn compares it with the one signed by the browser).
     *
     * @param Request $request Request.
     * @return string
     */
    private static function origin(Request $request): string
    {
        return ($request->isSecure() ? 'https' : 'http') . '://' . $request->host();
    }

    /**
     * @brief Limits the sign-in attempts of an address.
     *
     * @param Request $request Request.
     * @return void
     * @throws HttpException 429
     */
    private function limit(Request $request): void
    {
        $key = 'passkey:' . $request->ip();
        if ($this->limiter->tooManyAttempts($key, self::ATTEMPTS)) {
            throw new HttpException(429, 'Too many attempts.');
        }
        $this->limiter->hit($key, 60);
    }

    /**
     * @brief Hides the endpoints when passkeys are off.
     *
     * @return void
     * @throws HttpException 404
     */
    private function ensureEnabled(): void
    {
        if (!Passkeys::isEnabled()) {
            throw new HttpException(404, 'Not Found');
        }
    }
}
