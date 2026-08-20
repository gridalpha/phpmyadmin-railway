# phpMyAdmin on Railway

A thin, auditable wrapper around the official [`phpmyadmin`](https://hub.docker.com/_/phpmyadmin)
image. The application is upstream's, untouched; this repository only closes the
gaps between that image and a platform that recreates the container on every deploy
and has nobody available to run a setup step by hand.

## What the wrapper adds

| Gap in the stock image | What happens here |
|---|---|
| A fresh `blowfish_secret` is generated whenever `/etc/phpmyadmin` is empty — i.e. on every deploy — logging every signed-in browser out | The secret comes from `PMA_BLOWFISH_SECRET`, or is generated once and kept on the volume |
| No phpMyAdmin configuration storage, and creating it means running `sql/create_tables.sql` by hand | `pma-bootstrap.php` creates the database, the scoped control user and the tables at container start, idempotently |
| `AllowNoPassword = true` is hardcoded for every configured server | Forced to `false` |
| `php:8.3-apache` currently ships `mpm_event` alongside the `mpm_prefork` that `mod_php` needs, aborting Apache with `AH00534` | Normalised at build **and** at container start |
| Apache listens on 80; Railway supplies `PORT` | `APACHE_PORT` derived from `PORT` |
| No health endpoint | `/healthz`, which connects to the configured database rather than merely proving Apache is up |
| Railway's edge adds no HSTS, and phpMyAdmin sets every other security header but that one | `Strict-Transport-Security` added by Apache |
| Every client looks like Railway's rotating proxy address | `REMOTE_ADDR` taken from the leftmost `X-Forwarded-For` entry, which Railway's edge controls |
| No reCAPTCHA or IP allow-list knob | `PMA_CAPTCHA_*` and `PMA_ALLOWDENY_*`, both off by default |

Configuration storage is what backs bookmarks, query history, the table designer,
change tracking, saved searches, export templates and **two-factor authentication** —
the last of which is the reason it is worth provisioning on an admin panel that is
reachable from the internet.

## Variables

Nothing is required beyond a database to point at. Everything below has a working
default; set one only if you mean to change it.

| Variable | Default | Purpose |
|---|---|---|
| `PMA_HOST` / `PMA_PORT` | — | The MySQL or MariaDB server phpMyAdmin manages |
| `PMA_BOOTSTRAP_USER` / `PMA_BOOTSTRAP_PASSWORD` | — | Administrative credential used **only** to create the configuration storage, then removed from the environment |
| `PMA_CONTROLPASS` | — | Password for the `pma` control user, created and kept in step on every boot |
| `PMA_PMADB` / `PMA_CONTROLUSER` | `phpmyadmin` / `pma` | Names of the configuration storage database and its user |
| `PMA_BLOWFISH_SECRET` | generated onto the volume | Cookie encryption key; keep it stable or sessions reset |
| `PMA_ABSOLUTE_URI` | — | Public base URL; also what makes phpMyAdmin mark its cookies `Secure` |
| `UPLOAD_LIMIT` / `MEMORY_LIMIT` / `MAX_EXECUTION_TIME` | `256M` / `1024M` / `600` | Import and export limits |
| `PMA_DATA_DIR` | `/pma` | Volume root holding sessions, the upload and save directories, and the secret |
| `PMA_ARBITRARY` | unset | `1` lets anyone at the login form connect to **any** server they name; leave off |
| `PMA_CAPTCHA_PUBLIC_KEY` / `PMA_CAPTCHA_PRIVATE_KEY` | unset | reCAPTCHA on the login form |
| `PMA_ALLOWDENY_ORDER` / `PMA_ALLOWDENY_RULES` | unset | IP allow-list, e.g. `deny,allow` and `deny % from all,allow % from 203.0.113.4` |
| `PMA_BOOTSTRAP_RETRIES` / `PMA_BOOTSTRAP_RETRY_DELAY` | `40` / `5` | How long to wait for the database, which Railway does not start first |

Every other `PMA_*` variable the upstream image documents still works unchanged.

## Layout

    Dockerfile                    FROM phpmyadmin:apache, plus the wrapper
    railway-entrypoint.sh         MPM, port, volume, secret, bootstrap, then upstream's entrypoint
    pma-bootstrap.php             configuration storage provisioning (idempotent)
    conf.d/zz-railway.php         phpMyAdmin settings, loaded last
    apache/zz-railway.conf        HSTS and the /healthz alias
    railway-healthz.php           health endpoint

## Licence

phpMyAdmin is GPL-2.0-only. This wrapper is published under the same terms.
