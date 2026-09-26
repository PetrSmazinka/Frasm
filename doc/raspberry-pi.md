# Provoz na Raspberry Pi (šetření SD karty)

MicroSD karty mají omezený počet zápisových cyklů. Web, který při každém požadavku zapisuje logy, session soubory a čítače do databáze, dokáže levnou kartu opotřebovat během měsíců. Frasm proto většinu zápisů omezuje sám a zbytek jde přesunout do RAM (tmpfs).

---

## Co framework dělá automaticky

| Oblast | Chování |
|---|---|
| Logy | Záznamy se sbírají v paměti a zapíšou se **jedním zápisem na požadavek** (`logging.buffered`). Úroveň `critical` a vyšší se zapisuje hned. |
| Rate limiting | Tabulka `frasm_rate_limits` používá engine **MEMORY**, takže čítače vůbec nezapisují na disk. Po restartu MariaDB se vynulují. |
| API tokeny | `last_used_at` se aktualizuje nejvýš jednou za minutu. |
| Session | Požadavky bez session cookie (API, M2M, Bearer token) session nevytváří. |
| Cache rout | Zapisuje se jen při změně kontrolerů, jinak se pouze čte (a drží ji OPcache v paměti). |

---

## Doporučené nastavení: volatilní data v RAM

### 1. Adresáře v tmpfs (`/run` je tmpfs)

`/etc/tmpfiles.d/frasm.conf` – vytvoří adresáře při každém startu se správnými právy:

```text
d /run/frasm            0775 www-data www-data -
d /run/frasm/logs       2775 www-data www-data -
d /run/frasm/sessions   0700 www-data www-data -
d /run/frasm/cache      2775 www-data www-data -
```

Aplikovat bez restartu: `sudo systemd-tmpfiles --create /etc/tmpfiles.d/frasm.conf`

### 2. Konfigurace (`config/local.php`)

```php
return [
    'logging' => [
        'path'         => '/run/frasm/logs',                 // zápis do RAM
        'archive_path' => '/var/www/frasm/storage/logs',     // trvalé úložiště
    ],
    'session' => [
        'save_path' => '/run/frasm/sessions',  // po rebootu se ztratí, remember-me uživatele přihlásí znovu
    ],
    'routing' => [
        'cache_path' => '/run/frasm/cache/routes.php',  // po rebootu se sama znovu vytvoří
    ],
];
```

### 3. Cron (spouštět jako `www-data`, aby soubory měly stejného vlastníka jako web)

`sudo crontab -u www-data -e`:

```text
# Přesun logů z RAM na disk (při výpadku napájení se ztratí nejvýš poslední hodina)
0 * * * *   cd /var/www/frasm && php bin/logs.php archive >/dev/null
# Úklid expirovaných tokenů
30 3 * * *  cd /var/www/frasm && php bin/prune-tokens.php >/dev/null
# Fronta úloh (pokud neběží jako služba, viz níže)
* * * * *   cd /var/www/frasm && php bin/queue.php work --once --max-time=55 >/dev/null
```

---

## Worker fronty jako systemd služba

Pro okamžité zpracování (push notifikace přes `PushManager::queue()`) je lepší trvale běžící worker místo cronu.

`/etc/systemd/system/frasm-queue.service`:

```ini
[Unit]
Description=Frasm queue worker
After=network-online.target mariadb.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/frasm
ExecStart=/usr/bin/php bin/queue.php work --max-time=3600
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now frasm-queue
```

Worker se po `max-time` sekundách sám ukončí a systemd ho spustí znovu (načte tak nový kód po deployi). Na `SIGTERM` dokončí rozpracovanou úlohu.

---

## Doporučení pro OS a MariaDB (mimo framework)

* **`noatime`** u kořenového oddílu v `/etc/fstab` – čtení souborů pak nezapisuje čas přístupu.
* **Systémové logy do RAM:** balíček [log2ram](https://github.com/azlux/log2ram) nebo `Storage=volatile` v `/etc/systemd/journald.conf`.
* **MariaDB** (`/etc/mysql/mariadb.conf.d/99-sdcard.cnf`):

  ```ini
  [mysqld]
  # Zápis redo logu na disk jednou za sekundu místo při každém COMMIT.
  # Při výpadku napájení lze přijít o poslední ~1 s transakcí.
  innodb_flush_log_at_trx_commit = 2
  ```

* Pokud to jde, dejte databázi (případně celý systém) na **USB SSD** – je výrazně odolnější než microSD.

---

## Odkazy

* Architektura frameworku: `.claude/system-description.md`
