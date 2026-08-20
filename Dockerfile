# phpMyAdmin for Railway.
#
# The published image is fine as-is for `docker run`, but three things it does are
# wrong on a platform that recreates the container on every deploy:
#
#   * it generates a fresh `blowfish_secret` whenever /etc/phpmyadmin is empty, so
#     every deploy invalidates every signed-in session;
#   * it hardcodes `AllowNoPassword = true` for every configured server;
#   * it ships no phpMyAdmin configuration storage, which is what backs bookmarks,
#     query history, table designer, tracking and two-factor authentication — and
#     creating it needs SQL, which a template deploy has no way to run by hand.
#
# Everything below is that gap. The application itself is upstream's, untouched.
FROM phpmyadmin:apache

# mod_headers: Railway's edge terminates TLS and adds no HSTS of its own.
# mod_php requires the prefork MPM; recent php:*-apache builds ship mpm_event
# enabled alongside it, which aborts Apache with AH00534. The entrypoint repeats
# this at container start, because the build's /etc/apache2 is not authoritative.
RUN set -eux; \
    a2enmod headers; \
    a2dismod -f mpm_event mpm_worker || true; \
    a2enmod mpm_prefork; \
    apache2ctl -t

# The base image bakes SESSION_SAVE_PATH=/sessions and UPLOAD_LIMIT=2048K, so
# "is the variable set?" is useless as a test of operator intent — point the baked
# names at this image's own defaults instead and let a Railway variable override.
ENV PMA_DATA_DIR=/pma \
    SESSION_SAVE_PATH=/pma/sessions \
    PMA_UPLOADDIR=/pma/upload \
    PMA_SAVEDIR=/pma/save \
    UPLOAD_LIMIT=256M \
    MEMORY_LIMIT=1024M \
    HIDE_PHP_VERSION=1 \
    PMA_PMADB=phpmyadmin \
    PMA_CONTROLUSER=pma \
    PMA_QUERYHISTORYDB=1 \
    PMA_QUERYHISTORYMAX=100 \
    PMA_BOOTSTRAP_RETRIES=40 \
    PMA_BOOTSTRAP_RETRY_DELAY=5

COPY conf.d/zz-railway.php      /etc/phpmyadmin/conf.d/zz-railway.php
COPY apache/zz-railway.conf     /etc/apache2/conf-enabled/zz-railway.conf
COPY railway-healthz.php        /var/www/html/railway-healthz.php
COPY pma-bootstrap.php          /usr/local/lib/pma-bootstrap.php
COPY railway-entrypoint.sh      /usr/local/bin/railway-entrypoint.sh

RUN set -eux; \
    chmod 0755 /usr/local/bin/railway-entrypoint.sh; \
    chmod 0444 /var/www/html/railway-healthz.php \
               /usr/local/lib/pma-bootstrap.php \
               /etc/phpmyadmin/conf.d/zz-railway.php \
               /etc/apache2/conf-enabled/zz-railway.conf; \
    bash -n /usr/local/bin/railway-entrypoint.sh; \
    php -l /usr/local/lib/pma-bootstrap.php; \
    php -l /var/www/html/railway-healthz.php; \
    php -l /etc/phpmyadmin/conf.d/zz-railway.php; \
    apache2ctl -t

# Upstream ends on USER root so its own entrypoint can chgrp the secret file;
# ours additionally chowns the Railway volume before Apache drops to www-data.
USER root
ENTRYPOINT ["/usr/local/bin/railway-entrypoint.sh"]
CMD ["apache2-foreground"]
