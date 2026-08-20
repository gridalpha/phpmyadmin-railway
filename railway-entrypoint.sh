#!/bin/bash
# Railway wrapper around phpmyadmin's own /docker-entrypoint.sh.
set -eu

log() { printf 'railway-entrypoint: %s\n' "$*"; }

DATA_DIR="${PMA_DATA_DIR:-/pma}"

# ---------------------------------------------------------------- Apache MPM --
# php:*-apache currently ships mpm_event enabled next to the mpm_prefork that
# mod_php requires; Apache then aborts with AH00534 and the container restart-loops
# while the deployment still reads SUCCESS. Re-normalise at start, not at build.
if [ -e /etc/apache2/mods-enabled/mpm_event.load ] || [ -e /etc/apache2/mods-enabled/mpm_worker.load ]; then
    log 'more than one MPM enabled, forcing prefork'
    a2dismod -f mpm_event mpm_worker >/dev/null 2>&1 || true
    rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
    a2enmod mpm_prefork >/dev/null 2>&1 || true
fi
log "MPM in force: $(ls /etc/apache2/mods-enabled/ | grep '^mpm' | tr '\n' ' ')"

# ------------------------------------------------------------------- port -----
# phpMyAdmin's image reads APACHE_PORT; Railway supplies PORT and health-checks it.
export APACHE_PORT="${APACHE_PORT:-${PORT:-8080}}"
log "apache listening on ${APACHE_PORT}"

# ------------------------------------------------------------------- volume ---
mkdir -p "$DATA_DIR/sessions" "$DATA_DIR/upload" "$DATA_DIR/save" "$DATA_DIR/state"
chown -R www-data:www-data "$DATA_DIR"
chmod 1777 "$DATA_DIR/sessions"
chmod 0750 "$DATA_DIR/state"
log "data dir ${DATA_DIR} ($(df -Pm "$DATA_DIR" | awk 'NR==2 {print $2" MiB"}'))"

# ------------------------------------------------------- blowfish secret ------
# Upstream writes a random one whenever the file is absent, which on Railway is
# every deploy — so every signed-in browser is logged out by an unrelated redeploy.
# Prefer the operator's value, else keep one on the volume.
SECRET_FILE=/etc/phpmyadmin/config.secret.inc.php
if [ -n "${PMA_BLOWFISH_SECRET:-}" ]; then
    secret="$PMA_BLOWFISH_SECRET"
    secret_src='PMA_BLOWFISH_SECRET'
else
    stash="$DATA_DIR/state/blowfish.secret"
    if [ ! -s "$stash" ]; then
        head -c 64 /dev/urandom | base64 | tr -d '\n=+/' | cut -c1-32 > "$stash"
        chmod 0600 "$stash"
        chown www-data:www-data "$stash"
    fi
    secret="$(cat "$stash")"
    secret_src='volume'
fi
# base64 round-trip so a secret containing quotes or backslashes cannot break the file
printf '<?php\n$cfg[%s] = base64_decode(%s);\n' \
    "'blowfish_secret'" "'$(printf '%s' "$secret" | base64 | tr -d '\n')'" > "$SECRET_FILE"
chown root:www-data "$SECRET_FILE"
chmod 0640 "$SECRET_FILE"
log "blowfish secret from ${secret_src} (${#secret} chars)"
unset secret

# --------------------------------------------- phpMyAdmin configuration storage
# Bookmarks, query history, designer coordinates, tracking, saved searches and
# two-factor authentication all live in the pmadb. Creating it is SQL, and a
# template deploy has nobody to run SQL by hand — so the container does it.
if php /usr/local/lib/pma-bootstrap.php; then
    log 'configuration storage ready'
else
    log 'configuration storage unavailable, continuing without it'
    unset PMA_PMADB PMA_CONTROLUSER PMA_CONTROLPASS PMA_CONTROLHOST PMA_CONTROLPORT PMA_QUERYHISTORYDB
fi

# The admin credential was only ever needed for the step above. phpMyAdmin
# authenticates each user with their own MySQL login, so drop it from the
# environment Apache and PHP inherit.
unset PMA_BOOTSTRAP_USER PMA_BOOTSTRAP_PASSWORD

exec /docker-entrypoint.sh "$@"
