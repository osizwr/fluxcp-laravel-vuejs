# Production Deployment

Written for whoever runs the server. It assumes Linux, Nginx and systemd; adapt
freely.

> **The panel is not production ready.** See the status note in the
> [README](../README.md) and the
> [migration matrix](FLUXCP_MIGRATION_MATRIX.md). This document exists so the
> deployment shape is settled, not as an invitation to put it in front of
> players yet.

---

## 1. Before anything else

**Back up the rAthena database.** This project does not create, alter or drop
rAthena's own tables — but that is a property of the code, not a guarantee about
your environment, and `panel:install-schema` does need `CREATE` the first time it
runs.

```bash
mysqldump --single-transaction --routines ragnarok > ragnarok-$(date +%F).sql
```

Verify the dump restores into a scratch database before continuing. An untested
backup is not a backup.

## 2. Requirements

| | |
| --- | --- |
| PHP | 8.3+ with `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `intl`, `zip` |
| Web server | Nginx or Apache |
| Database | MySQL 5.7+ or MariaDB 10.4+ |
| Node | 20+, on the build host only |
| Process supervisor | systemd, Supervisor, or Forge |

Node is only needed to build assets. Build on a CI host or locally and deploy
`public/build`; a production host does not need Node installed.

## 3. Two databases, two users

Create the panel's own database and a user for it:

```sql
CREATE DATABASE panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'panel'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON panel.* TO 'panel'@'localhost';
```

Give the panel a **separate, narrowly scoped user** on the rAthena database
rather than reusing the emulator's:

```sql
CREATE USER 'panel_ro'@'localhost' IDENTIFIED BY 'another-long-random-password';

GRANT SELECT, INSERT, UPDATE, DELETE ON ragnarok.* TO 'panel_ro'@'localhost';

-- Needed once, for panel:install-schema. Revoke it afterwards.
GRANT CREATE, INDEX ON ragnarok.* TO 'panel_ro'@'localhost';
```

Note what is **not** granted: no `DROP`, no `ALTER`. The panel never needs
either, and withholding them means a defect cannot destroy game data.

```sql
-- After panel:install-schema has run successfully:
REVOKE CREATE, INDEX ON ragnarok.* FROM 'panel_ro'@'localhost';
FLUSH PRIVILEGES;
```

## 4. Deploy

```bash
cd /var/www/panel

composer install --no-dev --optimize-autoloader
npm ci && npm run build        # or copy public/build from CI

cp .env.example .env
php artisan key:generate
# edit .env  — see section 5

php artisan migrate --force
php artisan panel:install-schema --pretend   # inspect
php artisan panel:install-schema

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Ownership and permissions — only two directories need to be writable:

```bash
chown -R deploy:www-data /var/www/panel
find /var/www/panel -type d -exec chmod 755 {} \;
find /var/www/panel -type f -exec chmod 644 {} \;
chmod -R 775 storage bootstrap/cache
chmod 640 .env && chown deploy:www-data .env
```

`.env` holds two sets of database credentials. It must not be world-readable, and
must never be inside a directory the web server serves.

## 5. Environment

Start from `.env.example`, which documents every option. The ones that matter in
production:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.example.com
APP_KEY=                        # php artisan key:generate

SESSION_DRIVER=database         # or redis
SESSION_SECURE_COOKIE=true      # requires HTTPS
SESSION_SAME_SITE=lax

QUEUE_CONNECTION=database       # or redis
CACHE_STORE=database            # or redis
```

**`APP_DEBUG=false` is not optional.** With it on, any unhandled exception
renders a stack trace that includes environment variables — both database
passwords among them.

Two rAthena settings must match your emulator exactly. Getting them wrong does
not produce an error; it produces accounts that cannot sign in:

```env
RATHENA_LOGIN_USE_MD5=false
RATHENA_LOGIN_CASE_SENSITIVE=false
```

## 6. Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name panel.example.com;

    root /var/www/panel/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/panel.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/panel.example.com/privkey.pem;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    charset utf-8;
    client_max_body_size 8m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Hashed filenames, so these are safe to cache indefinitely.
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Nothing outside public/ is served, but be explicit about dotfiles.
    location ~ /\.(?!well-known).* {
        deny all;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
}

server {
    listen 80;
    server_name panel.example.com;
    return 301 https://$host$request_uri;
}
```

Redirect HTTP to HTTPS, and set `SESSION_SECURE_COOKIE=true`. The session cookie
is the credential; sending it over plaintext hands it to anyone on the path.

## 7. Queue worker

Broadcasts are queued, so without a worker realtime updates never leave the
application.

`/etc/systemd/system/panel-queue.service`:

```ini
[Unit]
Description=Panel queue worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/panel
ExecStart=/usr/bin/php artisan queue:work --sleep=1 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

`--max-time=3600` makes the worker exit hourly so systemd restarts it, which
picks up new code and releases any slow memory growth.

## 8. Scheduler

The scheduler drives the status measurement and broadcast. Without it, status
only refreshes when a visitor requests it.

```cron
* * * * * cd /var/www/panel && php artisan schedule:run >> /dev/null 2>&1
```

## 9. Reverb

Optional. Without it the client polls and the panel works normally.

`/etc/systemd/system/panel-reverb.service`:

```ini
[Unit]
Description=Panel Reverb websocket server
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/panel
ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8080

[Install]
WantedBy=multi-user.target
```

Bind it to `127.0.0.1` and proxy it, rather than exposing the port directly, so
it is reached over TLS on the same origin as the panel:

```nginx
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 3600s;
}
```

Then, in `.env`:

```env
REVERB_HOST=panel.example.com
REVERB_PORT=443
REVERB_SCHEME=https
```

Generate your own `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET`.
Only the key reaches the browser; the secret must not.

```bash
systemctl daemon-reload
systemctl enable --now panel-queue panel-reverb
```

## 10. Firewall

```bash
ufw allow 22/tcp
ufw allow 80,443/tcp
ufw enable
```

Do not open 8080: Reverb is proxied. Do not expose MySQL. The game server's own
ports — 6900, 6121, 5121 — are rAthena's concern, and the panel only needs to be
able to *reach* them to probe status, not to expose them.

## 11. Logging and monitoring

```env
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
```

`LOG_LEVEL=warning` in production: `debug` logs every query and fills a disk.

Worth watching:

- `Refused a request to a route with no permission entry` — a configuration
  fault, logged at warning level precisely so a missing permission entry is
  diagnosable rather than a silent 403.
- `Could not record a sign-in attempt` — usually `panel:install-schema` has not
  been run.
- `/up` is a health endpoint suitable for an uptime check.

The panel writes no passwords to any log or table.

## 12. Backups

| What | Why |
| --- | --- |
| rAthena database | Game data. Back up on the emulator's schedule, not the panel's. |
| Panel database | Sessions are disposable, but `panel_credentials` is not: losing it makes every account fall back to rAthena's weaker credential on next sign-in. |
| `.env` | Two sets of credentials and `APP_KEY`. Store it in a secret manager, not in the repository. |

**Losing `APP_KEY` invalidates every session and every encrypted cookie.** Keep
it with the backup, separately from the database dump.

## 13. Updating

```bash
php artisan down --render=errors::503

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan panel:install-schema      # additive, safe to re-run

php artisan config:cache route:cache view:cache event:cache
systemctl restart panel-queue panel-reverb php8.3-fpm

php artisan up
```

Run `php artisan optimize:clear` if anything behaves as though it is reading
stale configuration — a cached config file is the usual cause of an `.env`
change appearing to have no effect.

## 14. Laravel Forge

Forge covers sections 6 to 10 without the hand-written units. It is not
required; nothing in this project depends on it.

- **Deploy script**: the commands in section 13, minus the `systemctl` lines.
- **Daemons**: one for `queue:work`, one for `reverb:start`.
- **Scheduler**: `schedule:run`, every minute.
- **Environment**: paste from `.env.example` and fill in.
- Enable Let's Encrypt, and set `SESSION_SECURE_COOKIE=true` afterwards.

Add the rAthena database as a second connection by setting the `RATHENA_*`
variables — Forge's database panel manages the panel's own database only.
