<?php

declare(strict_types=1);

namespace Cmdb;

final class Config
{
    public static function path(): string
    {
        $custom = getenv('CMDB_CONFIG');
        if ($custom !== false && $custom !== '') {
            if (!preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $custom)) {
                throw new ApiError(503, 'A CMDB_CONFIG abszolút fájlútvonal legyen.');
            }
            return str_replace('\\', '/', $custom);
        }
        $project = dirname(__DIR__);
        $parent = dirname($project);
        // Under XAMPP/Apache keep secrets outside the shared document root too.
        if (in_array(strtolower(basename($parent)), ['htdocs', 'www', 'html', 'wwwroot'], true)) {
            $parent = dirname($parent);
        }
        return str_replace('\\', '/', $parent.'/'.basename($project).'-private/config.json');
    }

    public static function directory(): string
    {
        return dirname(self::path());
    }

    public static function exists(): bool
    {
        clearstatcache(true, self::path());
        return file_exists(self::path());
    }

    public static function read(): ?array
    {
        if (!self::exists()) {
            return null;
        }
        try {
            $config = json_decode((string)file_get_contents(self::path()), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiError(503, 'A meglévő privát konfiguráció nem olvasható. A telepítő biztonsági okból nem írja felül.');
        }
        foreach (['dsn', 'db_user', 'db_password', 'storage'] as $key) {
            if (!is_array($config) || !array_key_exists($key, $config) || !is_string($config[$key])) {
                throw new ApiError(503, 'Hiányos privát konfiguráció. Javítsd a config.example.json alapján; a telepítő nem írja felül.');
            }
        }
        return $config;
    }

    private static function resolved(string $path): string
    {
        $tail = [];
        while (!file_exists($path) && !is_link($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                throw new ApiError(503, 'A privát mappa útvonala nem oldható fel.');
            }
            array_unshift($tail, basename($path));
            $path = $parent;
        }
        $real = realpath($path);
        if ($real === false) {
            throw new ApiError(503, 'A privát mappa útvonala nem oldható fel.');
        }
        $value = rtrim(str_replace('\\', '/', $real), '/').($tail ? '/'.implode('/', $tail) : '');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
    }

    public static function ensurePrivateDirectory(string $path): void
    {
        if (!preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $path) || preg_match('~(^|[/\\\\])\.\.([/\\\\]|$)~', $path)) {
            throw new ApiError(503, 'A privát tárolóhoz abszolút, webrooton kívüli útvonal szükséges.');
        }
        $target = self::resolved($path);
        $roots = [dirname(__DIR__), $_SERVER['DOCUMENT_ROOT'] ?? ''];
        $parent = dirname(dirname(__DIR__));
        if (in_array(strtolower(basename($parent)), ['htdocs', 'www', 'html', 'wwwroot'], true)) {
            $roots[] = $parent;
        }
        foreach (array_filter($roots) as $root) {
            $root = self::resolved($root);
            if ($target === $root || str_starts_with($target, $root.'/')) {
                throw new ApiError(503, 'A privát tároló nem lehet a projektben vagy a webszerver publikus könyvtárában. Állítsd be a CMDB_CONFIG változót.');
            }
        }
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new ApiError(503, 'A privát tároló nem hozható létre. Adj írási jogot a PHP futtatójának vagy állítsd be a CMDB_CONFIG változót.');
        }
        if (!is_writable($path)) {
            throw new ApiError(503, 'A privát tároló nem írható a PHP futtatója számára.');
        }
    }

    public static function applicationKey(): string
    {
        self::ensurePrivateDirectory(self::directory());
        $path = self::directory().'/application.key';
        if (!file_exists($path)) {
            $handle = @fopen($path, 'x+b');
            if (is_resource($handle)) {
                $key = random_bytes(32);
                if (fwrite($handle, $key) !== 32 || !fflush($handle)) {
                    fclose($handle);
                    @unlink($path);
                    throw new ApiError(503, 'Az alkalmazás titkosítási kulcsa nem menthető.');
                }
                @chmod($path, 0600);
                fclose($handle);
            }
        }
        for ($try = 0; $try < 5; $try++) {
            clearstatcache(true, $path);
            if (is_file($path) && !is_link($path)) {
                $key = file_get_contents($path);
                if (is_string($key) && strlen($key) === 32) {
                    return $key;
                }
            }
            usleep(50000);
        }
        throw new ApiError(503, 'Az alkalmazás titkosítási kulcsa hiányzik vagy sérült. Állítsd vissza a privát mentésből.');
    }
}
