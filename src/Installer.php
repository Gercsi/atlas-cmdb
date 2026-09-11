<?php

declare(strict_types=1);

namespace Cmdb;

use PDO;

final class Installer
{
    public static function requirements(): array
    {
        $extensions = ['pdo_mysql', 'mbstring', 'dom', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'gd', 'fileinfo', 'openssl'];
        $missing = array_values(array_filter($extensions, fn($extension) => !extension_loaded($extension)));
        return ['php_version' => PHP_VERSION, 'missing_extensions' => $missing, 'ready' => PHP_VERSION_ID >= 80200 && !$missing];
    }

    public static function status(): array
    {
        return [
            'requirements' => self::requirements(),
            'config_path' => Config::path(),
            'defaults' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'atlas'],
        ];
    }

    public static function install(array $input): array
    {
        if (Config::exists()) {
            throw new ApiError(409, 'Az alkalmazás már konfigurálva van. A meglévő adatbázist nem módosítottuk.');
        }
        if (!self::requirements()['ready']) {
            throw new ApiError(422, 'A telepítéshez PHP 8.2+ és az összes felsorolt PHP-bővítmény szükséges.');
        }
        $host = $input['host'] ?? '';
        $port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $database = $input['database'] ?? '';
        $admin = $input['admin_user'] ?? '';
        $password = $input['admin_password'] ?? '';
        $fields = [];
        if (!is_string($host) || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $fields['host'] = 'Ebben a helyi kiadásban csak helyi MySQL/MariaDB szerver választható.';
        }
        if ($port === false) {
            $fields['port'] = '1 és 65535 közötti port szükséges.';
        }
        if (!is_string($database) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,47}$/D', $database) || in_array(strtolower($database), ['mysql', 'sys', 'performance_schema', 'information_schema'], true)) {
            $fields['database'] = 'Új adatbázisnév szükséges: betűvel kezdődő, legfeljebb 48 betű, szám vagy aláhúzás.';
        }
        if (!is_string($admin) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $admin)) {
            $fields['admin_user'] = 'Add meg a telepítésre jogosult SQL-felhasználó nevét.';
        }
        if (!is_string($password) || strlen($password) > 1024 || str_contains($password, "\0")) {
            $fields['admin_password'] = 'Érvénytelen SQL-jelszó.';
        }
        if ($fields) {
            throw new ApiError(422, 'Ellenőrizd a telepítési mezőket.', $fields);
        }
        Config::ensurePrivateDirectory(Config::directory());
        $lock = @fopen(Config::directory().'/installation.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ApiError(409, 'Egy másik telepítés már folyamatban van. Várj, majd frissítsd az oldalt.');
        }
        $stage = 'connection';
        $createdDatabase = false;
        $temporaryConfig = null;
        try {
            if (Config::exists()) {
                throw new ApiError(409, 'A telepítés időközben befejeződött. Frissítsd az oldalt.');
            }
            $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5];
            $db = new PDO($dsn, $admin, $password, $options);
            $stage = 'database';
            // Deliberately no IF NOT EXISTS: even an existing empty DB belongs to
            // its owner. Installation is never an import, reset, or overwrite.
            $db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $createdDatabase = true;
            $db->exec("USE `$database`");
            $stage = 'schema';
            Schema::migrate($db);
            $stage = 'account';
            $runtimeUser = 'atlas_'.bin2hex(random_bytes(8));
            $runtimePassword = bin2hex(random_bytes(32));
            // Both localhost and a literal loopback endpoint are local accounts;
            // the server resolves the client host, not the DSN spelling.
            $account = $db->quote($runtimeUser)."@'localhost'";
            $db->exec('CREATE USER '.$account.' IDENTIFIED BY '.$db->quote($runtimePassword));
            // Underscores in DB-level GRANT names are patterns unless escaped.
            $grantName = str_replace('_', '\\_', $database);
            $db->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON `$grantName`.* TO $account");
            $applicationDsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
            $stage = 'verify';
            $runtime = new PDO($applicationDsn, $runtimeUser, $runtimePassword, $options);
            if ((int)$runtime->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
                throw new ApiError(409, 'A létrehozott adatbázis nem üres. A konfigurációt nem aktiváltuk.');
            }
            foreach (Schema::TYPES as $table) {
                if ((int)$runtime->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== 0) {
                    throw new ApiError(409, 'A létrehozott nyilvántartások nem üresek. A konfigurációt nem aktiváltuk.');
                }
            }
            $config = [
                'dsn' => $applicationDsn, 'db_user' => $runtimeUser, 'db_password' => $runtimePassword,
                'storage' => Config::directory(), 'source' => null,
                'export_retention_hours' => 24, 'import_retention_days' => 90, 'audit_retention_days' => 365,
            ];
            $stage = 'config';
            $temporaryConfig = tempnam(Config::directory(), '.install-');
            if ($temporaryConfig === false) {
                throw new ApiError(503, 'A privát konfiguráció nem írható. Az új adatbázis megmaradt; meglévő adatbázist nem módosítottunk.');
            }
            @chmod($temporaryConfig, 0600);
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporaryConfig, $json, LOCK_EX) !== strlen($json)) {
                throw new ApiError(503, 'A privát konfiguráció mentése nem sikerült.');
            }
            if (Config::exists() || !rename($temporaryConfig, Config::path())) {
                throw new ApiError(409, 'A konfigurációt nem lehetett aktiválni. Frissítsd az oldalt és ellenőrizd a privát mappát.');
            }
            $temporaryConfig = null;
            return ['success' => true, 'installation_required' => false, 'setup_required' => true];
        } catch (\PDOException $e) {
            // Never log SQL strings, DSNs, submitted passwords or account secrets.
            error_log('CMDB installer stage='.$stage.' sqlstate='.$e->getCode());
            if ($stage === 'database' && (int)($e->errorInfo[1] ?? 0) === 1007) {
                throw new ApiError(409, 'Ez az adatbázis már létezik. Semmit nem módosítottunk benne. Válassz új adatbázisnevet.', ['database' => 'Már létező adatbázis nem használható az üres telepítéshez.']);
            }
            $message = match ($stage) {
                'connection' => 'Nem sikerült kapcsolódni. Ellenőrizd a futó MySQL/MariaDB szolgáltatást, a portot és az SQL belépési adatokat.',
                'database' => 'Az SQL-felhasználó nem tud adatbázist létrehozni. CREATE jogosultság szükséges.',
                'account', 'verify' => 'A külön alkalmazásfiók létrehozása vagy ellenőrzése nem sikerült. CREATE USER és a céladatbázisra adható jogosultságok szükségesek.',
                default => 'Az adatbázisséma létrehozása nem sikerült. Ellenőrizd a MariaDB/MySQL kompatibilitását és az SQL-jogosultságokat.',
            };
            if ($createdDatabase) {
                $message .= ' A most létrehozott, esetleg részleges adatbázist biztonságból megtartottuk. Új próbához válassz másik nevet, vagy az SQL-adminisztrátor ellenőrizze ezt a telepítést.';
            }
            throw new ApiError(422, $message);
        } finally {
            if (is_string($temporaryConfig) && is_file($temporaryConfig)) {
                unlink($temporaryConfig);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
