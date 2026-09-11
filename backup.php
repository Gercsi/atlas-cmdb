<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/vendor/autoload.php';
$a = new Cmdb\App();
$dir = $a->config['storage'].'/backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
Cmdb\Config::ensurePrivateDirectory($dir);
preg_match('/dbname=([^;]+)/', $a->config['dsn'], $databaseMatch);
preg_match('/host=([^;]+)/', $a->config['dsn'], $hostMatch);
preg_match('/port=([0-9]+)/', $a->config['dsn'], $portMatch);
$database = $databaseMatch[1] ?? throw new RuntimeException('Database name missing.');
$host = $hostMatch[1] ?? '127.0.0.1';
$port = $portMatch[1] ?? '3306';
$escape = fn(string $s) => str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $s);
$cnf = $dir.'/client.cnf';
file_put_contents($cnf, "[client]\nhost=\"".$escape($host)."\"\nport=$port\nuser=\"".$escape($a->config['db_user'])."\"\npassword=\"".$escape($a->config['db_password'])."\"\n");
@chmod($cnf, 0600);
try {
    $binary = getenv('CMDB_MYSQLDUMP') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/mysql/bin/mysqldump.exe' : 'mysqldump');
    // Atlas has no stored routines or triggers. Do not request global mysql
    // privileges from the installer-created, database-scoped runtime account.
    $proc = proc_open([$binary, '--defaults-extra-file='.$cnf, '--single-transaction', '--skip-triggers', '--no-tablespaces', '--result-file='.$dir.'/database.sql', $database], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Cannot start mysqldump.');
    fclose($pipes[0]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc) !== 0) throw new RuntimeException('Backup failed. Check mysqldump, server availability and database permissions.');
    if (!copy(Cmdb\Config::path(), $dir.'/config.json')) throw new RuntimeException('Cannot back up private configuration.');
    @chmod($dir.'/config.json', 0600);
    $applicationKey = Cmdb\Config::directory().'/application.key';
    if (is_file($applicationKey)) { if (!copy($applicationKey, $dir.'/application.key')) throw new RuntimeException('Cannot back up application encryption key.'); @chmod($dir.'/application.key', 0600); }
    foreach (glob($a->config['storage'].'/*.xlsx') as $file) if (!copy($file, $dir.'/'.basename($file))) throw new RuntimeException('Cannot back up import file.');
    file_put_contents($dir.'/manifest.json', json_encode(['created_at' => gmdate('c'), 'database' => $database, 'sha256' => hash_file('sha256', $dir.'/database.sql'), 'includes' => ['database.sql', 'config.json', 'application.key-if-present', 'quarantined-xlsx'], 'excludes' => ['sessions', 'temporary-exports', 'logs', 'custom-routines-and-triggers']], JSON_PRETTY_PRINT));
    echo "Backup: $dir\n";
} finally {
    if (is_file($cnf)) unlink($cnf);
}
