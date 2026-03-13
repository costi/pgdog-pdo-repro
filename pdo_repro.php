<?php

function env_or_default($name, $default)
{
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
}

$host = env_or_default('REPRO_HOST', 'postgres');
$port = env_or_default('REPRO_PORT', '5432');
$dbname = env_or_default('REPRO_DBNAME', 'app');
$user = env_or_default('REPRO_USER', 'app');
$password = env_or_default('REPRO_PASSWORD', 'app');
$mode = env_or_default('REPRO_MODE', 'query');
$worker = env_or_default('REPRO_WORKER', 'worker');
$loops = (int) env_or_default('REPRO_LOOPS', '1');

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbname);

function run_query_mode(PDO $pdo)
{
    $pdo->query('SET search_path TO public');
}

function run_prepare_mode(PDO $pdo)
{
    $stmt = $pdo->prepare('SELECT $1::int');
    $stmt->execute([1]);
    $stmt->fetchColumn();
}

echo 'dsn: ' . $dsn . PHP_EOL;
echo 'worker: ' . $worker . PHP_EOL;
echo 'mode: ' . $mode . PHP_EOL;
echo 'loops: ' . $loops . PHP_EOL;

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    if (defined('PDO::ATTR_EMULATE_PREPARES')) {
        try {
            echo 'emulate_prepares: ' . ($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) ? 'true' : 'false') . PHP_EOL;
        } catch (Throwable $e) {
            echo "emulate_prepares: unsupported\n";
        }
    }

    for ($i = 0; $i < $loops; $i++) {
        if ($mode === 'query') {
            run_query_mode($pdo);
        } elseif ($mode === 'prepare') {
            run_prepare_mode($pdo);
        } elseif ($mode === 'mixed') {
            run_query_mode($pdo);
            run_prepare_mode($pdo);
        } else {
            throw new InvalidArgumentException('unknown mode: ' . $mode);
        }
    }

    echo "result: success\n";
} catch (Throwable $e) {
    echo "result: error\n";
    echo 'message: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
