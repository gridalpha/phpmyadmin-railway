<?php
/**
 * Loaded by the image's /etc/phpmyadmin/config.inc.php after every $cfg default and
 * every PMA_* environment variable it understands, so this file has the last word.
 */

declare(strict_types=1);

/*
 * Railway's edge overwrites a client-supplied X-Forwarded-For and appends its own
 * entry, so the leftmost value is the real client and is safe to trust. Without
 * this every visitor looks like the rotating CGNAT proxy address, which silently
 * defeats $cfg['Servers'][$i]['AllowDeny'] rules and the failed-login log.
 */
if (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $railwayClientIp = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    if (filter_var($railwayClientIp, FILTER_VALIDATE_IP) !== false) {
        $_SERVER['REMOTE_ADDR'] = $railwayClientIp;
    }
    unset($railwayClientIp);
}

/*
 * The image sets AllowNoPassword = true on every server it configures, which lets
 * an empty password through the login form. Nothing on Railway needs that.
 */
foreach (array_keys($cfg['Servers'] ?? []) as $railwayServerId) {
    $cfg['Servers'][$railwayServerId]['AllowNoPassword'] = false;
}
unset($railwayServerId);

/* Never offer to mail a stack trace out of someone else's deployment. */
$cfg['SendErrorReports'] = 'never';

/* The instance is on the public internet; do not advertise the PHP build. */
$cfg['ShowPhpInfo'] = false;
$cfg['ShowServerInfo'] = false;

/* Keep a signed-in tab alive for a working day, re-authenticating after that. */
$cfg['LoginCookieValidity'] = 28800;
$cfg['LoginCookieRecall'] = true;

/* Warn before a query rewrites a whole table with no WHERE clause. */
$cfg['Confirm'] = true;

/*
 * Optional hardening the image exposes no variable for. Both default to off, so a
 * click-Deploy install is unaffected; both are the documented answer to the one
 * real risk of a public phpMyAdmin — an unlimited login form.
 */
$railwayCaptchaPublic = trim((string) getenv('PMA_CAPTCHA_PUBLIC_KEY'));
$railwayCaptchaPrivate = trim((string) getenv('PMA_CAPTCHA_PRIVATE_KEY'));
if ($railwayCaptchaPublic !== '' && $railwayCaptchaPrivate !== '') {
    $cfg['CaptchaLoginPublicKey'] = $railwayCaptchaPublic;
    $cfg['CaptchaLoginPrivateKey'] = $railwayCaptchaPrivate;
}
unset($railwayCaptchaPublic, $railwayCaptchaPrivate);

$railwayAllowDenyOrder = trim((string) getenv('PMA_ALLOWDENY_ORDER'));
if ($railwayAllowDenyOrder !== '') {
    $railwayAllowDenyRules = trim((string) getenv('PMA_ALLOWDENY_RULES'));
    foreach (array_keys($cfg['Servers'] ?? []) as $railwayServerId) {
        $cfg['Servers'][$railwayServerId]['AllowDeny']['order'] = $railwayAllowDenyOrder;
        $cfg['Servers'][$railwayServerId]['AllowDeny']['rules'] = $railwayAllowDenyRules === ''
            ? []
            : array_map('trim', explode(',', $railwayAllowDenyRules));
    }
    unset($railwayAllowDenyRules, $railwayServerId);
}
unset($railwayAllowDenyOrder);
