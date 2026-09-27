# Running Frasm on a Raspberry Pi

MicroSD cards survive a limited number of write cycles. A website that writes logs, session files and
counters on every request can wear out a cheap card within months. Frasm avoids most of these writes
on its own; the rest can be moved to RAM (tmpfs) with a few settings.

## What the framework already does

| Area | Behaviour |
|---|---|
| Logs | Entries are buffered in memory and written **once per request** (`logging.buffered`). `critical` and more severe entries are written immediately. |
| Rate limiting | `frasm_rate_limits` uses the **MEMORY** storage engine, so counters never touch the disk. They reset when MariaDB restarts. |
| API tokens | `last_used_at` is updated at most once a minute. |
| Sessions | Requests without a session cookie (APIs, machine-to-machine, Bearer tokens) never create a session. |
| Route cache | Written only when controllers change; afterwards it is read from OPcache memory. |

## Recommended: volatile data in RAM

### 1. Directories on tmpfs

`/run` is a tmpfs. Create `/etc/tmpfiles.d/frasm.conf` so the directories exist after every boot:

```text
d /run/frasm            0775 www-data www-data -
d /run/frasm/logs       2775 www-data www-data -
d /run/frasm/sessions   0700 www-data www-data -
d /run/frasm/cache      2775 www-data www-data -
```

Apply it without rebooting: `sudo systemd-tmpfiles --create /etc/tmpfiles.d/frasm.conf`

### 2. Configuration (`config/local.php`)

```php
return [
    'logging' => [
        'path'         => '/run/frasm/logs',              // written to RAM
        'archive_path' => '/var/www/app/storage/logs',    // persistent copy
    ],
    'session' => [
        'save_path' => '/run/frasm/sessions',  // lost on reboot; "remember me" signs users back in
    ],
    'routing' => [
        'cache_path' => '/run/frasm/cache/routes.php',  // rebuilt automatically after a reboot
    ],
];
```

### 3. Cron (as `www-data`, so files keep the same owner as the web server)

`sudo crontab -u www-data -e`:

```text
# Copy logs from RAM to disk (a power loss loses at most the last hour)
0 * * * *   cd /var/www/app && php bin/frasm logs:archive >/dev/null
# Delete expired tokens and rate limit counters
30 3 * * *  cd /var/www/app && php bin/frasm prune >/dev/null
# Job queue, if the worker does not run as a service (see below)
* * * * *   cd /var/www/app && php bin/frasm queue:work --once --max-time=55 >/dev/null
```

## Queue worker as a systemd service

For immediate processing (for example push notifications sent with `PushManager::queue()`), run the
worker permanently instead of from cron.

`/etc/systemd/system/frasm-queue.service`:

```ini
[Unit]
Description=Frasm queue worker
After=network-online.target mariadb.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php bin/frasm queue:work --max-time=3600
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now frasm-queue
```

The worker exits after `--max-time` seconds and systemd starts it again, which also picks up new code
after a deployment. On `SIGTERM` it finishes the current job before exiting.

## Operating system and MariaDB

* Mount the root filesystem with **`noatime`** in `/etc/fstab`, so reading a file does not write its access time.
* Keep system logs in RAM with [log2ram](https://github.com/azlux/log2ram), or set `Storage=volatile` in `/etc/systemd/journald.conf`.
* MariaDB (`/etc/mysql/mariadb.conf.d/99-sdcard.cnf`):

  ```ini
  [mysqld]
  # Flush the redo log once per second instead of on every COMMIT.
  # A power loss can lose the last ~1 s of transactions.
  innodb_flush_log_at_trx_commit = 2
  ```

* If possible, put the database (or the whole system) on a **USB SSD**; it is far more durable than microSD.
