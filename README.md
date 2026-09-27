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
- **Authentication** – sessions, "remember me", Bearer API tokens for machine-to-machine access.
- **Validation** – declarative rules (`'required|email|max:255'`), form errors redirected back automatically.
- **HTML over the wire** – navigation without page reloads, live components, Server-Sent Events.
- **Web Push** – VAPID and payload encryption implemented natively, channels, action buttons.
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

### Apache

Point the document root to `public/`; the bundled `.htaccess` routes every request to `public/index.php`.

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
Your `app/`, `database/`, `storage/` and `config/local.php` are never touched. Missing configuration
files are added; changed defaults of existing ones are only reported.

## Project structure

```text
myapp/
├── app/                  your code: Controllers, Models, Views, Components, Commands
├── config/               configuration; config/local.php holds secrets and per-server overrides
├── database/migrations/  your database migrations
├── public/               document root (index.php, assets)
├── storage/              logs and caches (writable by the web server)
├── core/                 the framework – do not edit, replaced on update
└── bin/frasm             command-line interface
```

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

### Views and navigation

Views are plain PHP templates in `app/Views`. Put `<?= frasm_head() ?>` in the `<head>` of your layout:
links and forms then load without a full page reload, with a progress bar and working back/forward
buttons. Use `data-frasm-nav="false"` to opt a link out, or `data-frasm-target="#id"` to replace just one element.

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

### Web Push notifications

```bash
make push:vapid    # prints the keys for config/local.php
```

Set a default icon for all notifications in `config/local.php` (`'push' => ['icon' => '/icon.png']`), then send
from code:

```php
// Immediately, or through the queue worker
$push->send(new PushMessage('Door opened', 'Front door at 21:04', url: '/cameras'), PushTarget::user($id));
$push->queue(new PushMessage('Daily report', ttl: 3600), PushTarget::channel('reports'));

// Picture and action buttons: "url" opens a page, "post" calls your endpoint in the background
$push->send(new PushMessage(
    'Gate open',
    'The gate has been open for 10 minutes.',
    options: ['image' => '/img/gate.jpg'],
    actions: [
        ['action' => 'camera', 'title' => 'Camera', 'url' => '/camera'],
        ['action' => 'close', 'title' => 'Close', 'post' => '/api/gate/close'],
    ],
), PushTarget::user($id));
```

or from the command line:

```bash
make push:send ARGS='"Gate open" --user=1 --image=/img/gate.jpg --action="camera|Camera|/camera" --action="close|Close|post:/api/gate/close"'
```

A `post` action reaches your controller with a signed token instead of a CSRF token; the pressed button is
available as `$request->getAttribute('push_action')`. Action buttons and large images are shown by
Chromium-based browsers; others display the plain notification.

In the browser, call `Frasm.push.subscribe()` from a button click.

### Installable web app (PWA)

Enable it in `config/local.php` and generate the manifest and icons:

```php
'pwa' => [
    'enabled'    => true,
    'name'       => 'My Application',
    'short_name' => 'MyApp',
    'icon'       => 'public/logo.png',   // square image, at least 512×512 px
],
```

```bash
make pwa:build            # writes public/manifest.webmanifest and public/pwa/* (needs the PHP GD extension)
make pwa:build ARGS=--force   # after changing the icon or colors
```

`frasm_head()` then links the manifest and registers the service worker, so browsers offer to install
the site. On iPhone, Web Push notifications only work in an installed web app.

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

### Job queue

```php
use Core\Queue\Queue;

Queue::push(new GenerateReportJob($reportId));
```

Process jobs with `make worker` while developing; in production run the worker from systemd or cron
(see [Automation](#automation)).

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
| `schedule:list`, `schedule:run`, `schedule:cron` | Scheduled tasks (`#[Schedule]`) and their cron entry |
| `push:vapid`, `push:send` | Web Push |
| `pwa:build` | Web app manifest and icons |
| `key:generate`, `token:create` | Application key, API tokens |
| `user:password` | Set a user's password (asked without echo, or `--generate`) |
| `logs:archive`, `prune` | Maintenance |

Your own commands go to `app/Commands/*Command.php` and are picked up automatically.

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
