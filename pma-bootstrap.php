<?php
/**
 * Create phpMyAdmin's configuration storage (the "pmadb") on the target server.
 *
 * phpMyAdmin ships sql/create_tables.sql and tells the administrator to run it.
 * A Railway template deploy has no administrator at that moment, so this runs at
 * container start instead: idempotent, safe on every redeploy, and a no-op when
 * the operator points PMA_HOST at a server they administer themselves.
 *
 * Exit 0 = configuration storage is usable. Non-zero = caller should unset the
 * pmadb variables and let phpMyAdmin run without it.
 */

declare(strict_types=1);

const SCHEMA_FILE = '/var/www/html/sql/create_tables.sql';

function out(string $msg): void
{
    fwrite(STDERR, 'pma-bootstrap: ' . $msg . "\n");
}

function env(string $name, string $default = ''): string
{
    $value = getenv($name);

    return $value === false ? $default : trim($value);
}

function quoteIdentifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

$host        = env('PMA_HOST');
$port        = (int) (env('PMA_PORT', '3306') ?: '3306');
$adminUser   = env('PMA_BOOTSTRAP_USER');
$adminPass   = env('PMA_BOOTSTRAP_PASSWORD');
$pmadb       = env('PMA_PMADB');
$controlUser = env('PMA_CONTROLUSER');
$controlPass = env('PMA_CONTROLPASS');
$controlHost = env('PMA_CONTROLHOST', $host);
$controlPort = (int) (env('PMA_CONTROLPORT', (string) $port) ?: (string) $port);
$retries     = max(1, (int) (env('PMA_BOOTSTRAP_RETRIES', '40') ?: '40'));
$delay       = max(1, (int) (env('PMA_BOOTSTRAP_RETRY_DELAY', '5') ?: '5'));

foreach (['PMA_HOST' => $host, 'PMA_PMADB' => $pmadb, 'PMA_CONTROLUSER' => $controlUser,
          'PMA_CONTROLPASS' => $controlPass, 'PMA_BOOTSTRAP_USER' => $adminUser,
          'PMA_BOOTSTRAP_PASSWORD' => $adminPass] as $name => $value) {
    if ($value === '') {
        out($name . ' is empty, skipping configuration storage setup');
        exit(1);
    }
}

if (! is_readable(SCHEMA_FILE)) {
    out('missing ' . SCHEMA_FILE);
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);

/* Railway has no service ordering, so the database is frequently still booting. */
$conn = null;
for ($attempt = 1; $attempt <= $retries; $attempt++) {
    $candidate = @new mysqli($host, $adminUser, $adminPass, '', $port);
    if ($candidate->connect_errno === 0) {
        $conn = $candidate;
        out("connected to {$host}:{$port} as {$adminUser} (attempt {$attempt})");
        break;
    }
    if ($attempt === 1 || $attempt % 5 === 0) {
        out("waiting for {$host}:{$port} ({$candidate->connect_error})");
    }
    sleep($delay);
}

if ($conn === null) {
    out("gave up after {$retries} attempts");
    exit(1);
}

$conn->set_charset('utf8mb4');

$pmadbQuoted = quoteIdentifier($pmadb);
$userLiteral = "'" . $conn->real_escape_string($controlUser) . "'@'%'";
$passLiteral = "'" . $conn->real_escape_string($controlPass) . "'";

$statements = [
    "CREATE DATABASE IF NOT EXISTS {$pmadbQuoted} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin",
    "CREATE USER IF NOT EXISTS {$userLiteral} IDENTIFIED BY {$passLiteral}",
    /* Keep the stored password in step with the variable, so rotating it works. */
    "ALTER USER {$userLiteral} IDENTIFIED BY {$passLiteral}",
    /* phpMyAdmin's documented control-user grant: the pmadb and nothing else. */
    "GRANT SELECT, INSERT, UPDATE, DELETE, ALTER ON {$pmadbQuoted}.* TO {$userLiteral}",
];

foreach ($statements as $sql) {
    if (! $conn->query($sql)) {
        out('failed: ' . preg_replace('/IDENTIFIED BY .*/', 'IDENTIFIED BY <redacted>', $sql)
            . ' -> ' . $conn->error);
        exit(1);
    }
}

if (! $conn->select_db($pmadb)) {
    out("cannot use {$pmadb}: " . $conn->error);
    exit(1);
}

/*
 * create_tables.sql opens with its own CREATE DATABASE/USE for the stock name and
 * still asks for utf8mb3, which MySQL 8+ deprecates. Strip the first two statements
 * (the database is already selected) and take the modern charset.
 */
$schema = (string) file_get_contents(SCHEMA_FILE);
$schema = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $schema) ?? '';
$schema = str_replace(
    ['CHARACTER SET utf8 ', 'utf8_bin', 'utf8_general_ci'],
    ['CHARACTER SET utf8mb4 ', 'utf8mb4_bin', 'utf8mb4_general_ci'],
    $schema
);

$failures = [];
if ($conn->multi_query($schema)) {
    do {
        if ($conn->errno !== 0) {
            $failures[] = $conn->error;
        }
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    if ($conn->errno !== 0) {
        $failures[] = $conn->error;
    }
} else {
    $failures[] = $conn->error;
}

if ($failures !== []) {
    out('schema load reported: ' . implode(' | ', array_unique($failures)));
}

/* Expect exactly what this release's schema file declares, not a frozen number. */
$expected = preg_match_all('/CREATE TABLE IF NOT EXISTS `pma__/i', $schema);

$row = $conn->query(
    'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = '
    . "'" . $conn->real_escape_string($pmadb) . "' AND table_name LIKE 'pma\\_\\_%'"
)?->fetch_assoc();
$tableCount = (int) ($row['n'] ?? 0);
$conn->close();

if ($expected < 1 || $tableCount < $expected) {
    out("{$tableCount} of {$expected} pma__ tables present in {$pmadb}");
    exit(1);
}

/* Prove the grant rather than trusting it: connect as the control user itself. */
$check = @new mysqli($controlHost, $controlUser, $controlPass, $pmadb, $controlPort);
if ($check->connect_errno !== 0) {
    out("control user cannot connect: {$check->connect_error}");
    exit(1);
}
$probe = $check->query('SELECT COUNT(*) AS n FROM pma__bookmark');
$check->close();

if ($probe === false) {
    out('control user cannot read the configuration storage');
    exit(1);
}

out("{$pmadb} holds all {$tableCount} pma__ tables, readable by {$controlUser}");
exit(0);
