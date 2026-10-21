# Frasm

A lightweight, dependency-free PHP framework for Apache and MariaDB/MySQL, designed to run comfortably
on small servers such as a Raspberry Pi.

Frasm renders plain HTML on the server and makes it feel like a single-page app: page transitions
happen without full reloads, components update themselves over the wire, and the server can push
fragments to the browser. No Composer, no build step, no Node.js.

## Features

- **Attribute routing** – `#[Get('/users/{id}')]` on controller methods, typed route parameters, compiled route cache.
- **Dependency injection** – autowiring container for controllers, commands and services.
- **Middleware** – PSR-15 style pipeline: CSRF, authentication, authorization, CORS, rate limiting.
- **Authentication** – sessions, "remember me", Bearer API tokens for machine-to-machine access; role changes,
  password changes and deleted accounts take effect in signed-in sessions immediately.
- **Validation** – declarative rules (`'required|email|max:255'`), form errors redirected back automatically.
- **HTML over the wire** – navigation without page reloads, live components, Server-Sent Events.
- **Web Push** – VAPID and payload encryption implemented natively, channels, action buttons.
- **E-mail** – SMTP client with TLS and authentication, no dependencies; tests never send real mail.
- **Job queue** – database-backed queue with a worker for cron or systemd.
- **Built for small hardware** – buffered logging, RAM-backed counters and optional tmpfs storage to spare SD cards.
- **Secure defaults** – strict sessions, CSRF protection, signed component state, no stack traces in production.

## Requirements

- PHP **8.3+** with `mysqli`, `openssl`, `mbstring`, `json` and `posix`
  (optional: `curl` for Web Push, `pcntl` for graceful worker shutdown, OPcache for performance)
- MariaDB **10.6+** or MySQL **8.0+**
- Apache **2.4** with `mod_rewrite` (or PHP's built-in server for development)

## Installation

Download the installer and create a new project:

```bash
wget -qO install.sh https://raw.githubusercontent.com/PetrSmazinka/Frasm/master/install.sh
bash install.sh /var/www/myapp
```

The installer checks the requirements, downloads the framework, creates the project structure with a
Hello world application, generates `config/local.php` with a fresh application key and a random
administrator password, and prints the remaining steps:

```bash
cd /var/www/myapp
# 1. Enter your database credentials in config/local.php
make migrate    # create the tables
make seed       # create the administrator account
make serve      # preview at http://127.0.0.1:8000
```

Installer options:

| Option | Description |
|---|---|
| `--ref <tag>` | Install a specific version (branch or tag, default `master`; use a tag in production) |
| `--no-example` | Create an empty application instead of the Hello world example |
| `--pwa` | Make the site installable as an app (manifest, icons, service worker) |
| `--scheduler` | Enable `#[Schedule]` tasks (the cron entry is managed for you) |
| `--web-user <group>` | Group of the web server that needs access to `storage/` (default `www-data`) |
| `--docker <container>` | PHP runs only in this container (see [Docker](#docker)) |
| `--docker-workdir <dir>` | The project directory as mounted in the container (required with `--docker`) |
| `--docker-user <uid:gid>` | User running PHP in the container (default: you, with the project directory's group) |

### Docker

When PHP runs only in a container, the host has no `php`. The installer then downloads the framework on the
host (with your git credentials) and runs PHP in the container, which must have the project directory mounted:

```bash
bash install.sh --docker apache_php --docker-workdir /var/www/myapp ~/www/myapp
```

It writes `frasm.mk` (not versioned) with these settings, and every `make` command then runs through
`docker exec` in that container: `make migrate`, `make update`, `make db:rollback`, … An existing project
gets `frasm.mk` the same way from its first `./install.sh --update --docker … .` For scheduled tasks in
Docker see [Scheduled tasks](#docker-1).

### Apache

Point the document root to `public/`; the bundled `.htaccess` routes every request to `public/index.php`.
It also refuses to serve secrets and internals that might land in `public/` by mistake (dot files, keys and
certificates such as `.pem`/`.key`, `.env`/`.ini`, database dumps, logs, backups). Keep secrets in `config/local.php`
or `storage/`, never in `public/` or `app/Assets/` (assets are served only from an allowlist of types).

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/myapp/public

    <Directory /var/www/myapp/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Permissions

The project belongs to you, so you can edit and update it without `sudo`; the web server only gets what
it needs through its group: read access to `config/local.php` and write access to `storage/`.

The installer sets this up when you are a member of the web server group (log in again after adding yourself):

```bash
sudo usermod -aG www-data "$USER"             # once; then log out and back in
sudo install -d -o "$USER" -g www-data /var/www/myapp
bash install.sh /var/www/myapp
```

Running the whole installer with `sudo` works too: it hands the project over to the user who invoked `sudo`.
If anything cannot be applied, the installer prints the exact commands to finish the job.

## Updating

```bash
make update                        # latest master
make update REF=v0.2.0 MIGRATE=1   # a specific version, and apply database changes
```

An update replaces only the framework (`core/`, `bin/`, `public/index.php`, `public/js/frasm-*.js`, …).
Your `app/`, `database/`, `storage/`, `config/local.php` and `frasm.mk` are never touched. Missing configuration
files are added; changed defaults of existing ones are only reported. With `frasm.mk` (Docker), `make update`
passes its settings to the installer.

## Version control and restoring a project

The generated `.gitignore` keeps the framework out of your repository, so it holds only your application:
`app/`, `database/`, `config/`, your files in `public/` and `.frasm-version`. The installer maintains the
block between `# >>> frasm` and `# <<< frasm` on every update; put your own rules below it.

`config/local.php` is ignored as well – it holds the application key and passwords. Back it up separately.

To bring a project back from its repository, clone it and let the installer add the framework of the
version recorded in `.frasm-version`:

```bash
git clone <your-project-repo> /var/www/myapp && cd /var/www/myapp
cp /path/to/backup/local.php config/local.php
wget -qO install.sh https://raw.githubusercontent.com/PetrSmazinka/Frasm/master/install.sh
bash install.sh --restore --migrate .
```

Without a backed-up `config/local.php` the installer creates a new one (new application key); enter the
database credentials, then run `make migrate`. The PWA manifest and icons are regenerated from the configuration.

## Project structure

```text
myapp/
├── app/                  your code: Controllers, Models, Views, Components, Commands, Assets (CSS, JS)
├── config/               configuration; config/local.php holds secrets and per-server overrides
├── database/migrations/  your database migrations
├── public/               document root – the framework's only (front controller, scripts, generated PWA files)
├── storage/              logs and caches (writable by the web server)
├── core/                 the framework – do not edit, replaced on update
└── bin/frasm             command-line interface
```

### Site layer

An application can be maintained in one place and installed into several projects (each with its own updates
replacing `app/`). What belongs to one project then goes into `site/`, which has the structure of `app/` and the
namespace `Site\` (e.g. `site/Controllers/HomeController.php` is `Site\Controllers\HomeController`):

- views, assets and language files of `site/` replace those of `app/` with the same path (`site/Views/errors/404.php`,
  `site/Assets/img/logo.png`; a dictionary of `site/Lang` is merged over the one of `app/Lang`);
- controllers, tasks, commands, tests (`site/Tests`, namespace `Site\Tests`) and migrations
  (`site/database/migrations`) are added to those of the application – a route must not be defined twice;
- `site/bootstrap.php` runs after `app/bootstrap.php`;
- `Tests/config.php` of a layer (app, then site) is merged into the configuration before the tests run, before the
  `testing` settings of `config/local.php` – tests of an application installed into several projects can rely on
  fixed values (domains, names, addresses) whatever the project configures.

Without `site/` nothing changes. `Core\Layers` lists the layers and finds files in them. Extra make targets of such
an application (or of the project) go into `*.mk` files next to the `Makefile`, which includes them.

## A quick tour

### Routing and controllers

Controllers in `app/Controllers` are discovered automatically.

```php
namespace App\Controllers;

use Core\Controller\BaseController;
use Core\Routing\Attributes\{Authorize, Get, Middleware, Post};

#[Authorize('editor')]
class ArticleController extends BaseController
{
    public function __construct(private ArticleRepository $articles) {}   // autowired

    #[Get('/articles/{id}')]
    public function show(int $id): string                                 // 'abc' → 404
    {
        return $this->view('articles/show', ['article' => $this->articles->find($id)]);
    }

    #[Post('/articles')]
    #[Middleware('throttle:10,60')]
    public function store(): never
    {
        $data = $this->validate([
            'title' => 'required|max:200',
            'email' => 'nullable|email',
        ]);

        $this->articles->create($data);
        $this->redirect('/articles');
    }
}
```

Actions may return a string (HTML), an array (JSON) or a `Core\Http\Response`.

### Subdomains

One project can serve several (sub)domains. Name the hosts in `config/local.php` and bind controllers
(or single actions) to a name with `#[Domain]`:

```php
'app' => [
    'domains' => [
        'main'      => ['example.com', 'www.example.com'],   // the first host is used for links
        'smarthome' => 'smarthome.example.com',
        'tenant'    => '{tenant}.example.com',               // a placeholder stands for one label
    ],
],
```

```php
#[Domain('smarthome')]
class SmarthomeController extends BaseController
{
    #[Get('/')]                                 // https://smarthome.example.com/
    public function index(): string { /* ... */ }
}

#[Domain('tenant')]
class ShopController extends BaseController
{
    #[Get('/orders/{id}')]                      // https://acme.example.com/orders/7
    public function order(string $tenant, int $id): string { /* ... */ }
}
```

Routes without `#[Domain]` (such as `/login`) answer on every listed host; routes of the requested domain win
over them, while fixed paths still win over paths with placeholders – a domain's `/{slug}` does not hide
`/login`. Once
`app.domains` is set, any other host – a bare IP address, a forged `Host` header – gets 404, so list every
host that must keep working (for example the server's LAN address under `main`). In development map the
names to `localhost`, `smarthome.localhost` and `{tenant}.localhost` in `config/local.php`: browsers resolve
`*.localhost` to your machine, so `make serve` handles all of them.

Link across domains with `url()`: `url('/login')` stays on the current host, `url('/', 'smarthome')` goes to
the smarthome domain and `url('/orders', 'tenant', ['tenant' => 'acme'])` fills the placeholder (from a tenant's
page, its own value is used when omitted). The request attributes `domain` and `domain_params` tell every action
which domain it runs on.

A domain can also be a section of a host – a path behind it. Several names then share one host, each with its
own path, which keeps one origin (one installable app, one service worker, one login) while the code stays
split by domain:

```php
'domains' => [
    'app'   => 'app.example.com',          // the rest of the host: dashboard, /login, …
    'songs' => 'app.example.com/songs',
    'admin' => 'app.example.com/admin',
],
```

Routes of `#[Domain('songs')]` are registered below `/songs` (`#[Get('/{id}')]` answers `/songs/12`, `#[Get('/')]`
answers `/songs`), a request belongs to the domain with the longest matching path of its host (whole segments)
and `url()` puts the path in front: `url('/12', 'songs')` gives `https://app.example.com/songs/12`, and
`url('/12')` on a page of the songs section stays in it.

Every subdomain has its own login by default. To share it, set `'session' => ['domain' => 'example.com']`
and give the cookies names of their own (`session.name`, `auth.remember_cookie`): all subdomains – including
other applications hosted there – receive them.

Apache serves all hosts from one virtual host (a placeholder domain needs a wildcard DNS record and certificate):

```apache
ServerName example.com
ServerAlias www.example.com smarthome.example.com *.example.com
```


Error pages follow the subdomain too. In production the first existing template of `app/Views/errors/` renders an
error: `{domain}/{status}.php`, `{domain}/error.php`, `{status}.php`, `error.php` (each gets `$status`, `$message`
and `$domain`). So `errors/shop/403.php` can explain in the shop's look that the signed-in user lacks access, while
other subdomains keep the common pages. The domain of a request no route matched (a 404) is resolved from its host.

### Views and navigation

Views are plain PHP templates in `app/Views`. Put `<?= frasm_head() ?>` in the `<head>` of your layout:
links and forms then load without a full page reload, with a progress bar and working back/forward
buttons. Use `data-frasm-nav="false"` to opt a link out, or `data-frasm-target="#id"` to replace just one element.

### Stylesheets and scripts

Your CSS and JavaScript belong to `app/Assets` – `public/` is the framework's. Link them with `asset()`:

```php
<link rel="stylesheet" href="<?= asset('css/app.css') ?>" data-frasm-track>
<script src="<?= asset('js/app.js') ?>" defer data-frasm-track></script>
```

`asset()` adds a version derived from the file's modification time (`/assets/css/app.css?v=…`), so browsers
cache the file for a year and fetch it again only after it changes. Assets are served before the framework
boots (no session, routing or database work); only common web file types are served (styles, scripts, images,
fonts, audio: mp3, ogg, wav, m4a). `data-frasm-track`
makes a deployment of changed assets reload open pages instead of mixing old and new code.

### Live components

```php
class Counter extends \Core\View\Component
{
    public int $count = 0;

    #[\Core\View\Attributes\Locked]
    public int $max = 10;               // the browser cannot change locked properties

    public function increment(): void
    {
        $this->count = min($this->count + 1, $this->max);
    }

    protected function template(): string
    {
        return FRASM_APP_DIR . '/Views/components/counter.php';
    }
}
```

```html
<button data-action="increment">+1</button>
<input data-model="count">
```

The component state is signed with the application key, so it cannot be tampered with in the browser.

### Server-Sent Events

```php
#[Get('/sensors/stream')]
public function stream(): Response
{
    return Response::eventStream(function (EventStream $stream): void {
        do {
            $stream->html('#temperature', '<span id="temperature">' . $this->sensor->read() . ' °C</span>');
        } while ($stream->sleep(5));
    });
}
```

```html
<div data-frasm-stream="/sensors/stream"><span id="temperature">…</span></div>
```

### Files and downloads

`Response::download()` streams a file from disk without loading it into memory:

```php
#[Get('/documents/{id}')]
public function show(int $id): Response
{
    $document = $this->documents->find($id);

    return Response::download($document->path, $document->name);                         // save as …
    // return Response::download($document->path, $document->name, 'video/mp4', inline: true);  // show in the browser
}
```

File responses answer byte ranges, so audio and video can be seeked (`206 Partial Content`, `416` for a range
outside the file; `If-Range` is honoured), and carry validators (`ETag`, `Last-Modified`), so a repeated request
gets `304 Not Modified`. Browsers revalidate the file on every use (`Cache-Control: private, no-cache`); set your
own `Cache-Control` header to let them keep it. The session is closed before the file is streamed, so a long
download does not block the user's other requests. `->onProgress(fn() => …, seconds: 5)` calls a function when
streaming starts and then every few seconds while it goes on – e.g. to tell background jobs that a video is playing,
so they leave the disk to it.

A slow action (generating an image, a long computation) blocks the user's other requests as well: PHP locks the
session while it is open. Release the lock once the session has been read:

```php
#[Get('/photos/{id}/thumbnail')]
public function thumbnail(int $id): Response
{
    $this->photos->authorize($id);   // uses the session (signed-in user, roles)
    Session::release();              // from here on the session is read-only; other requests go on

    return Response::download($this->thumbnails->create($id), contentType: 'image/webp', inline: true);
}
```

After `Session::release()` the session data can still be read (`Auth::check()`, roles, the CSRF token); writing
(`Session::set()`, flash messages) throws an exception.

### Single sign-on between applications

One Frasm application can own the accounts and others, on different domains, sign their users in with them
(`config/sso.php`). The server gives every client an API token and a role prefix; users get access by roles with
that prefix (`band.member` is `member` in the client):

```php
// Server, config/local.php (then: make token:create ARGS="band --roles=sso.client")
'sso' => ['server' => ['enabled' => true, 'clients' => [
    'band' => ['redirect_uris' => ['https://band.example.com/_frasm/sso/callback'], 'role_prefix' => 'band.'],
]]],

// Client, config/local.php
'sso'  => ['client' => ['enabled' => true, 'server' => 'https://example.com', 'client' => 'band', 'token' => '…',
                        'redirect_uri' => 'https://band.example.com/_frasm/sso/callback']],
'auth' => ['provider' => \Core\Auth\Sso\SsoUserProvider::class],
```

The client's login page links to `/_frasm/sso/login?return=…` (the server's login page, or no page at all when
the user is signed in there; `?silent=1` only checks), or checks a password typed into its own form with
`SsoClient::passwordLogin()`. Sign users out with `SsoClient::logout()`: it also stops the silent sign-in in that
browser until they sign in again. Clients keep a local copy of each account, so sessions and "remember me" work as
usual; the copy is refreshed every few minutes, so a removed role, a deleted account or a changed password at the
server signs the user out. The server's login page must send users back to its `return` parameter
(`Auth::safeReturnPath()`); protected pages add it when they redirect to the login page. An account without access to
the client comes back to the client's login page with `?denied=1` (and the silent sign-in switched off), so the page
can say so in the client's own look and offer another account.

Clients manage access with `SsoClient::lookup()` (find an account by user name or e-mail), `setRoles()` and
`syncUsers()` (local copies of all users, e.g. to set them up before their first sign-in).
A client may also create accounts, e.g. time-limited guests: `SsoClient::createUser($name, $email, $expiresAt, $roles)`
returns the identity and a generated password (null when the e-mail belongs to an existing account – give that one
roles instead). Accounts it created, and only those, it can extend or end and issue a new password:
`SsoClient::manageUser($externalId, $expiresAt|null|false, $newPassword)`.

Accounts can expire: users with `expires_at` in the past cannot sign in and are signed out (e.g. guests).

Failed sign-ins are limited per account and per address with `Core\Auth\LoginThrottle` (`auth.login_attempts`:
`max` per account, `per_ip`, `window` in seconds; defaults 5, 20 and 15 minutes): check `blocked($identifier, $ip)`
before verifying a password, call `failed()` after a wrong one and `succeeded()` after a sign-in.

A forgotten password is replaced through a one-time link: `Core\Auth\PasswordResets::issue($userId)` gives the token
(valid `auth.password_reset_ttl` seconds, 2 hours by default; only its hash is stored), the application e-mails its
own link, and `complete($token, $newPassword)` sets the password and ends the remember-me logins. An SSO client asks
the server with `SsoClient::requestPasswordReset($identifier, $ip)` (null when there is no such account – tell the user
the same either way) and finishes with `completePasswordReset($token, $password)`; the e-mail and the page stay the
client's own.

A client copies the accounts and refreshes a user's copy at most every `sso.client.refresh` seconds on that user's
requests. To pass changes at once, give the client a `notify` URL on the server
(`sso.server.clients.<name>.notify` = `https://client.example/_frasm/sso/changed`): `SsoServer::notify($userId)` then
posts the account ID there (the client API does it by itself; call it after the application's own changes of an
account – profile, photo, administration). The client reads the identity again with its own token, so the message
needs no authentication or CSRF token.

Accounts carry a phone number and a profile photo (`frasm_users.phone`, `avatar`). Store a photo with
`Core\Auth\Avatars::store($userId, $imageBytes)` (any JPEG/PNG/WebP/GIF becomes an upright 256 px square) and show it
to signed-in users at `/_frasm/avatars/{id}?v={version}` (`Avatars::version()`; cached for good while the version
stays). The photos are files `<id>.jpg` in `auth.avatars` (default `storage/app/avatars`); a directory set there
is created only when its parent exists, so a folder on an external drive is never created under its empty mount point.
An SSO server passes both in the identity; its clients keep the phone and a local copy of the photo (downloaded
only when its version changes), so they never link the server. A client changes the name, e-mail and phone of its user with
`SsoClient::setProfile($externalId, ['name' => …, 'email' => …, 'phone' => …])` (each only when given; a taken
e-mail is refused). `Avatars::square()` turns a photo into the same form for photos an
application keeps on its own.
Without a photo a user shows `Avatars::initials($name)` ("Jan Novák" → "JN") in a circle of `Avatars::color($userId)`
– one of `Avatars::COLORS` per user, so the same person has the same color in every view and in the cursors of
co-editing; `Avatars::dataUri($file)` gives a small copy for editors that cannot load the photo themselves.

### Tokens for other services

Services that authenticate requests with a JSON Web Token and a shared secret (document servers, webhooks,
internal APIs) are talked to with `Core\Security\Jwt`:

```php
use Core\Security\Jwt;

$token = Jwt::encode(['document' => $id, 'exp' => time() + 300], $secret);   // HS256, secret of 32+ bytes

$claims = Jwt::decode(Jwt::fromBearer($request->header('Authorization')) ?? '', $secret, leeway: 30);
if ($claims === null) {
    // forged, malformed, expired or not yet valid
}
```

Only HS256 is accepted: the algorithm named in the token's header is never trusted, so `alg: none` or another
algorithm is rejected. `exp` and `nbf` are checked when present; other claims are up to you. For tokens the
application issues to itself (signed links), `Signer::token()` is shorter.

### Web Push notifications

```bash
make push:vapid    # prints the keys for config/local.php
```

Set a default icon for all notifications in `config/local.php` (`'push' => ['icon' => '/assets/img/icon.png']`), then send
from code:

```php
// Immediately, or through the queue worker
$push->send(new PushMessage('Door opened', 'Front door at 21:04', url: '/cameras'), PushTarget::user($id));
$push->queue(new PushMessage('Daily report', ttl: 3600), PushTarget::channel('reports'));

// Picture and action buttons: "url" opens a page, "post" calls your endpoint in the background
$push->send(new PushMessage(
    'Gate open',
    'The gate has been open for 10 minutes.',
    options: ['image' => '/assets/img/gate.jpg'],
    actions: [
        ['action' => 'camera', 'title' => 'Camera', 'url' => '/camera'],
        ['action' => 'close', 'title' => 'Close', 'post' => '/api/gate/close'],
    ],
), PushTarget::user($id));
```

or from the command line:

```bash
make push:send ARGS='"Gate open" --user=1 --image=/assets/img/gate.jpg --action="camera|Camera|/camera" --action="close|Close|post:/api/gate/close"'
```

A `post` action reaches your controller with a signed token instead of a CSRF token; the pressed button is
available as `$request->getAttribute('push_action')`. Action buttons and large images are shown by
Chromium-based browsers; others display the plain notification.

In the browser, call `Frasm.push.subscribe()` from a button click.

### E-mail

Configure the SMTP server in `config/local.php` and check it with `make mail:test ARGS=you@example.com`:

```php
'mail' => [
    'transport' => 'smtp',                        // default 'log': messages are only written to the log
    'from'      => ['address' => 'app@example.com', 'name' => 'My App'],   // empty address = smtp.username
    'smtp'      => [
        'host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl',  // or 587 + 'tls' (STARTTLS)
        'username' => 'app@example.com', 'password' => '…',
    ],
],
```

```php
public function __construct(private Mailer $mailer) {}

$this->mailer->send(new MailMessage('jan@example.com', 'Daily report', $text, html: $html));
// Copies, blind copies (not in the headers), attachments and a reply to a received message:
$this->mailer->send(new MailMessage(['jan@example.com'], 'Photos', $text, cc: ['eva@example.com'], bcc: ['boss@example.com'],
    attachments: [['name' => 'photo.jpg', 'type' => 'image/jpeg', 'content' => $bytes]], inReplyTo: $receivedMessageId));
```

More sending accounts (e.g. a robot for notifications next to the mailbox people answer) live under
`mail.accounts`; whatever an account leaves out comes from the default one, so a second mailbox on the same
server needs only its sender and login. Tests catch the mail of every account (`ArrayTransport::sent()` tells the
sender); check an account with `make mail:test ARGS="you@example.com --account=robot"`:

```php
'mail' => [
    // … the default account as above …
    'accounts' => [
        'robot' => ['from' => ['address' => 'robot@example.com', 'name' => 'My App'],
                    'smtp' => ['username' => 'robot@example.com', 'password' => '…']],
    ],
],
```

```php
$this->mailer->account('robot')->send(new MailMessage($member['email'], 'Reminder', $text));
```

Reading a mailbox needs no PHP extension: `ImapClient` (IMAP over TLS; it only reads, messages stay unread) and
`MimeParser` (headers, text, HTML and attachments of a message, converted to UTF-8):

```php
$imap = ImapClient::fromConfig(Config::get('mail.imap'));   // host, port 993, encryption 'ssl', username, password
$imap->select('INBOX');
foreach ($imap->search('UID 120:*') as $uid) {
    $message = MimeParser::parse($imap->fetch($uid));        // subject, from, to, cc, text, html, attachments, …
}
$imap->logout();
```

Certificates are always verified and credentials are sent only after TLS (unless `encryption` is `'none'`).
`make test` switches to the `array` transport: read what was sent from
`Container::getInstance()->get(ArrayTransport::class)->messages()`.

### Installable web app (PWA)

Enable it in `config/local.php` and generate the manifest and icons:

```php
'pwa' => [
    'enabled'    => true,
    'name'       => 'My Application',
    'short_name' => 'MyApp',
    'icon'       => 'app/Assets/img/logo.png',   // square image, at least 512×512 px
],
```

```bash
make pwa:build            # writes public/manifest.webmanifest and public/pwa/* (needs the PHP GD extension)
make pwa:build ARGS=--force   # after changing the icon or colors
```

`frasm_head()` then links the manifest and registers the service worker, so browsers offer to install
the site. On iPhone, Web Push notifications only work in an installed web app.

A part of the site can be a separate app with its own name, icon and colors – pages within its scope
offer that app instead of the main one:

```php
'pwa' => [
    // ... the main app as above
    'apps' => [
        'smarthome' => [
            'name'        => 'SmartHome',
            'scope'       => '/smarthome',               // no trailing slash: covers /smarthome itself
            'theme_color' => '#0b0e14',
            'icon'        => 'app/Assets/img/home.svg',   // SVG icons need rsvg-convert (librsvg2-bin)
        ],
    ],
],
```

`make pwa:build` writes each app to `public/pwa/<name>/`. Every app registers the service worker with its own
scope, so push subscriptions made in an app belong to it and Android shows their notifications as notifications
of that app. Android does not install two apps with nested scopes (`/` and `/smarthome`) on one device: install
one of them, or serve independent apps from separate subdomains.

An app on its own [subdomain](#subdomains) names it instead of a scope. Pages of that domain then offer this
app (its scope defaults to `/`), and as a separate origin it installs next to the main app:

```php
'smarthome' => ['name' => 'SmartHome', 'domain' => 'smarthome', 'icon' => 'app/Assets/img/home.svg'],
```

Subscriptions remember the app they were made in, so notifications about a part of the site can go to that
app only: `PushTarget::user($id)->inApp('smarthome')` in code, `make push:send ARGS='"Doorbell" --user=1 --app=smarthome'`
from the command line (`--app=main` for the main app; without `inApp()` every subscription is notified).

### Scheduled tasks

Mark a controller action (or a method of a class in `app/Tasks`) with `#[Schedule]`:

```php
use Core\Scheduling\Schedule;

#[Post('/reports/sync')]
#[Authorize('admin')]
#[Schedule(everyMinutes: 15)]                 // or: #[Schedule(cron: '0 7 * * 1-5')]
public function sync(Request $request, ReportSync $sync): Response
{
    $sync->run();
    return $request->isScheduled() ? Response::noContent() : Response::redirect('/reports');
}
```

The action then runs automatically and stays available at its endpoint. Manual and automatic runs share a
lock, so they never overlap; a manual run also resets the interval.

The scheduler is off by default. Enable it in `config/local.php` (or install with `--scheduler`):

```php
'scheduler' => ['enabled' => true],
```

The installer then adds the cron entry for you on every install and `make update` (and removes it when
the scheduler is disabled); to apply a change right away, run `make schedule:cron`. `make schedule:list`
shows every task with its last run, status and trigger.

Failing tasks can e-mail the administrators. An alert goes out once the last success of a task is older than
`grace` minutes – a nightly task alerts at its first failure, one running every five minutes only after an hour
of failures, so a short outage of a remote service stays quiet – and one more when the task works again:

```php
'scheduler' => ['enabled' => true, 'alerts' => [
    'to'      => ['admin@example.com'],
    'account' => 'robot',      // mail account (see E-mail), default the default one
    'grace'   => 60,
]],
```

Throw from a task to make its run fail (an error it only logs stays unnoticed). The texts can be translated in
`app/Lang/<locale>/frasm.php` (`task_failed_subject`, `task_failed_body`, `task_recovered_subject`,
`task_recovered_body`; placeholders `:app`, `:task`, `:error`, `:since`, `:host`).

#### Docker

In a container, PHP and cron do not share a system: the PHP container has no cron, the host has no PHP.
Let a long-running process start the runs instead of cron:

```php
'scheduler' => ['enabled' => true, 'runner' => 'daemon'],
```

With `runner` set to `daemon`, `schedule:cron` manages no cron entry (and removes an old one), so installs and
updates inside the container succeed without `crontab`. Run `php bin/frasm schedule:work` as its own service
from the web server's image:

```yaml
  scheduler:
    image: <web server image>        # same PHP, extensions and network as the web server
    init: true                       # PHP must not be PID 1: it would ignore SIGTERM
    stop_signal: SIGTERM             # php:*-apache images default to SIGWINCH
    user: www-data
    working_dir: /var/www/myapp
    command: php bin/frasm schedule:work
    volumes:
      - ./www:/var/www
    restart: unless-stopped
```

`schedule:work` starts `schedule:run` at the beginning of every minute as a separate process, exactly like
cron: new code is used right after a deployment without restarting the service, and a slow task does not
delay the others. Its errors appear in `docker logs`. The same command works as a systemd service, and in
development it runs scheduled tasks without touching your crontab.

### Job queue

```php
use Core\Queue\Queue;

Queue::push(new GenerateReportJob($reportId));
```

Process jobs with `make worker` while developing; in production run the worker from systemd or cron
(see [Automation](#automation)).

### Tests

Tests live in `app/Tests` – classes extending `Core\Testing\TestCase` in files named `…Test.php`, the namespace
following the directories (`app/Tests/Blog/MarkdownTest.php` → `App\Tests\Blog\MarkdownTest`). Every public
method starting with `test` is a test:

```php
final class ArticleTest extends TestCase
{
    public function testEditorCanPublish(): void
    {
        $editor = $this->createUser(['editor']);
        $client = $this->http('example.com')->actingAs($editor);

        $client->post('/articles', ['title' => 'Hello'])->assertRedirect('/articles');
        $client->get('/articles')->assertOk()->assertSee('Hello');
        $this->assertSame(1, (int)$this->db('blog')->selectValue('SELECT COUNT(*) FROM `articles`'));
    }
}
```

`$this->http()` sends requests to the application in-process (no web server): the session lives on between
requests, form posts carry the CSRF token and validation errors redirect back like in a browser. Failing requests
show the exception behind them. Assertions: `assertSame`, `assertEquals`, `assertTrue`, `assertCount`,
`assertStringContains`, `assertThrows`, … and on responses `assertOk`, `assertStatus`, `assertRedirect`,
`assertSee`, `assertHeader`.

Besides `get()`, `post()` (forms) and `json()`, `raw()` sends any body, e.g. binary chunks of an upload:
`$client->raw('POST', '/uploads/7?offset=0', $bytes)`. For a file response, `content()` returns the bytes the
response streams (only the requested range): `$client->get('/video', ['Range' => 'bytes=0-99'])->content()`.
Cookies the browser would store (preferences) are sent with `withCookie()`:
`$client->withCookie('view', 'tiles')->get('/files')`.

Tests never touch real data. Give every database connection a test database in `config/local.php`:

```php
'testing' => ['database' => ['connections' => [
    'mysql' => ['database' => 'test_app', 'username' => 'test_app', 'password' => '…'],
]]],
```

Connections without one are disabled during the tests, and the runner refuses a test database that equals the real
one. The test databases are migrated before every run; `$this->truncate('blog')` empties a connection.

```bash
make test                      # all tests
make test ARGS=blog            # tests whose "Blog\ClassTest::testMethod" contains "blog"
make test ARGS="--fresh --stop"  # wipe and migrate the test databases first, stop at the first failure
```

### Several databases

Give each part of the application its own database and database user – a flaw in one part then cannot
reach the data of another. Name the connections in `config/database.php` (credentials in `config/local.php`):

```php
'default' => 'frasm',                 // framework tables: users, tokens, queue, …
'connections' => [
    'frasm' => ['database' => 'frasm', 'username' => 'app_frasm', 'password' => '…'],
    'blog'  => ['database' => 'blog',  'username' => 'app_blog',  'password' => '…'],
],
```

```php
$posts = DB::connection('blog')->select('SELECT * FROM `blog_posts`');   // DB::getInstance() = default
```

Migrations of a connection go to `database/migrations/<connection>/` (those directly in `database/migrations/`
belong to the default connection) and every database tracks its own migrations, so it can be backed up and
restored on its own. `make migrate` runs all connections; `db:rollback`, `db:wipe` and `db:reset` take
`ARGS=--connection=blog`. Validation rules name the connection as `unique:blog.blog_posts,slug`.
Transactions and foreign keys stay within one connection. `transactional()` calls nest: an inner one joins the
outer transaction, which commits everything or rolls everything back.

## Command-line interface

Everything is available through `make`. The most common tasks have short names; any other Frasm command
is run as `make <command>`, with its arguments in `ARGS`:

```bash
make help                                        # shortcuts and all commands
make migrate                                     # shortcut for db:migrate
make db:rollback                                 # any Frasm command
make token:create ARGS="home-assistant --days=365"
make db:reset ARGS=--help                        # help for one command
```

| Command | Purpose |
|---|---|
| `serve` | Development server |
| `migrate`, `seed`, `fresh`, `db:rollback`, `db:reset` | Database schema and administrator account |
| `routes`, `cache`, `route:clear` | Route table and route cache |
| `worker`, `queue:stats`, `queue:failed`, `queue:retry` | Job queue |
| `schedule:list`, `schedule:run`, `schedule:cron`, `schedule:work` | Scheduled tasks (`#[Schedule]`), their cron entry, or the daemon replacing it |
| `push:vapid`, `push:send` | Web Push |
| `mail:test` | Send a test e-mail (`ARGS=you@example.com`) |
| `pwa:build` | Web app manifests and icons (main app and `pwa.apps`) |
| `key:generate`, `token:create` | Application key, API tokens |
| `user:create`, `user:password`, `user:roles` | User accounts: create, set the password (asked without echo, or `--generate`), change roles |
| `logs:archive`, `prune` | Maintenance |
| `test` | Application tests in `app/Tests` against the test databases |

Your own commands go to `app/Commands/*Command.php` and are picked up automatically.

Commands run with umask `0002`: files and folders they create stay writable for the group, so the web server can
change them when it writes through the group (e.g. a disk mounted with a fixed `uid`/`gid`).

### Automation

`make` is meant for people. Cron jobs and services call the CLI directly with `php bin/frasm <command>`:
`make` may not be installed on a server, and a systemd service must receive signals itself to stop cleanly.

```text
# crontab -u www-data -e
* * * * *   cd /var/www/myapp && php bin/frasm queue:work --once --max-time=55
30 3 * * *  cd /var/www/myapp && php bin/frasm prune
```

A systemd unit for a permanently running queue worker is shown in [docs/raspberry-pi.md](docs/raspberry-pi.md#queue-worker-as-a-systemd-service).

## Production checklist

- `'debug' => false` in `config/local.php`
- `make cache` after every deployment
- A queue worker (systemd service or cron) if you use the queue or queued push notifications
- `php bin/frasm prune` daily from cron (see [Automation](#automation))

Running on a Raspberry Pi? See [docs/raspberry-pi.md](docs/raspberry-pi.md) for SD-card friendly settings.

## Contributing

This repository is the distribution point of Frasm. Issues and pull requests are not accepted.

## License

Frasm is source-available under the [Frasm License](LICENSE): you may use it, also commercially, to build
and run your applications and redistribute it unchanged, but you may not modify the framework itself.
Your application code, the Hello world template and the configuration files are yours to change freely.

## Calendar files

`Core\Calendar\IcsReader::occurrences($ics, $from, $to, $zone)` reads the events of an iCalendar file within a period:
repetitions (RRULE DAILY/WEEKLY/MONTHLY/YEARLY with INTERVAL, COUNT, UNTIL, BYDAY incl. `1MO`/`-1FR`, BYMONTHDAY,
BYMONTH), EXDATE, moved or cancelled repetitions (RECURRENCE-ID), all-day, UTC, floating and TZID times (IANA and the
common Windows names). Each result has `uid`, `summary`, `start`, `end` (exclusive), `all_day` and `transparent`
(marked "free"); cancelled events are left out.

## Passkeys

Sign-in with a passkey (WebAuthn: fingerprint, face or the PIN of the device) is on with `auth.passkeys.enabled`.
`auth.passkeys.rp_id` sets the domain the keys belong to (the registrable domain shares them by all subdomains;
default the host), `rp_name` the name the device shows (default `app.name`). `frasm_head()` then loads
`public/js/frasm-passkey.js`: `Frasm.passkey.register(name)` adds a key of the signed-in user,
`Frasm.passkey.login({remember, returnTo})` signs in (resolves to the address to go to), `remove(id)`;
`Core\Auth\Passkeys::forUser($id)` lists the keys for a profile page. Keys are discoverable (no user name typed),
user verification is required, ES256 and RS256 are accepted, the counter of a key going back refuses it as cloned. An
SSO client reads the account from its server at the sign-in and refuses it when the access ended there.


## Telephone numbers

Every `<input type="tel">` gets a picker of the country with its calling code (`public/js/frasm-phone.js`, part of
`frasm_head()`; intl-tel-input is loaded from cdnjs only on pages with such a field) and is sent in the international
form, `+420777123456`; a number that is not valid stops the form with a message at the field. `data-country="sk"`
sets the default country of a field (otherwise `<meta name="frasm-phone-country">`, otherwise `cz`),
`data-frasm-phone="off"` leaves a field plain.

The server normalizes what comes anyway (typed without JavaScript, imported data): the rule `phone` validates a number
and `Core\Support\Phone::normalize('777 123 456')` gives `+420777123456` (spaces, dashes, dots and parentheses go,
`00` becomes `+`, nine digits get `app.phone_country_code`, default `420`; `''` for an empty input, `null` for a text
that is not a number). `Phone::format()` writes Czech and Slovak numbers in threes (`+420 777 123 456`),
`Phone::href()` gives a `tel:` link.

## Czech alphabetical order

`Core\Support\Collation::compare($a, $b)` (for `usort()`) and `Collation::sort($texts)` order texts by the Czech
alphabet without the intl extension: `č`, `ř`, `š`, `ž` and `ch` are letters of their own (`Cukr` < `Čáp`, `Hrad` <
`Chata`), other accents count as the plain letter (`Údaje` < `Záznamník`), case decides last and runs of digits compare
as numbers (`foto 2` < `foto 10`). In SQL use `COLLATE utf8mb4_czech_ci`.
