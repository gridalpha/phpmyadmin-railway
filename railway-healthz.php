<?php
/**
 * Liveness probe for Railway, reached as /healthz via an Apache alias.
 *
 * Health-checking the dependency rather than the container: phpMyAdmin with an
 * unreachable database serves its login form perfectly and fails at the first
 * click, so "Apache answered" is not the interesting question.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$host = getenv('PMA_CONTROLHOST') ?: (getenv('PMA_HOST') ?: '');
$port = (int) (getenv('PMA_CONTROLPORT') ?: (getenv('PMA_PORT') ?: '3306'));
$user = (string) (getenv('PMA_CONTROLUSER') ?: '');
$pass = (string) (getenv('PMA_CONTROLPASS') ?: '');
$db = (string) (getenv('PMA_PMADB') ?: '');

if ($host === '' || $user === '' || $db === '') {
    /* No configuration storage configured: the app alone is the whole service. */
    echo "ok\n";
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('mysqli.default_socket', '');
$conn = @new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_errno !== 0) {
    http_response_code(503);
    echo "database unreachable\n";
    exit;
}

$ok = $conn->query('SELECT 1') !== false;
$conn->close();

if (! $ok) {
    http_response_code(503);
    echo "database not answering\n";
    exit;
}

echo "ok\n";
