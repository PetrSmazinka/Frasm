<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use Core\Auth\Auth;
use Core\Controller\BaseController;
use Core\Http\Request;
use Core\Logger\LoggerInterface;
use Core\Routing\Attributes\Get;
use Core\Routing\Attributes\Middleware;
use Core\Routing\Attributes\Post;

/**
 * @file AuthController.php
 * @brief Handles user authentication workflows including login form display, credential verification, and logout.
 */
class AuthController extends BaseController
{
    /**
     * @var string Bcrypt hash of a random string, verified when the user does not exist so that
     *             response time does not reveal whether an account exists (cost 12 = `bin/frasm db:seed` on PHP 8.4+).
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$FHyYIXEq/2bKZ90urZnu/OG5ETC/RdB7EvfR1qe3pSxHL1OaIGBS.';

    /**
     * @brief AuthController constructor.
     *
     * @param LoggerInterface $logger Application logger (injected by the container).
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @brief Displays the authentication form.
     *
     * Redirects to the homepage if a session is already established.
     *
     * @return string Rendered login view HTML.
     */
    #[Get('/login')]
    public function showLogin(): string
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        return $this->view('auth/login', [
            'pageTitle' => 'Sign in',
        ]);
    }

    /**
     * @brief Processes login attempts via POST.
     *
     * CSRF is verified by the global CsrfMiddleware; brute force is limited to 5 attempts
     * per minute and client. Missing fields are reported by the validator (redirect back with
     * $errors), wrong credentials by a flash message.
     *
     * @param Request $request Current HTTP request.
     * @return never
     */
    #[Post('/login')]
    #[Middleware('throttle:5,60')]
    public function login(Request $request): never
    {
        $credentials = $this->validate([
            'identifier' => 'required|max:255',
            'password'   => 'required|max:1024',
        ], attributes: ['identifier' => 'username or email']);

        $identifier = trim((string)$credentials['identifier']);
        $password = (string)$credentials['password'];

        // 1. Fetch user record from persistent storage
        $user = User::findByIdentifier($identifier);

        // 2. Always run password_verify() so the response time does not reveal whether the account exists
        $passwordValid = password_verify($password, $user !== null ? (string)$user['password_hash'] : self::DUMMY_PASSWORD_HASH);

        if ($user === null || !$passwordValid) {
            $this->logger->warning('Failed login attempt for {identifier}.', [
                'identifier' => $identifier,
                'ip'         => $request->ip(),
            ]);

            $this->flash('error', 'Invalid username or password.');
            $this->redirect('/login');
        }

        // 3. Establish authenticated session (regenerates the session ID)
        Auth::login((int)$user['id'], User::parsePermissions((string)$user['permissions']), !empty($this->input('remember_me')));

        $this->logger->info('User {user_id} logged in.', ['user_id' => (int)$user['id'], 'ip' => $request->ip()]);

        $this->flash('success', "Welcome back, {$user['name']}!");
        $this->redirect('/');
    }

    /**
     * @brief Terminates the authenticated session.
     *
     * Invalidates the active session state and redirects to the login view.
     * CSRF is verified by the global CsrfMiddleware.
     *
     * @return never
     */
    #[Post('/logout')]
    public function logout(): never
    {
        Auth::logout();
        $this->flash('info', 'You have been successfully logged out.');
        $this->redirect('/login');
    }
}
