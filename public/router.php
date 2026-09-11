<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api.php' || str_starts_with($path, '/api/')) {
    require __DIR__.'/api.php';
    return true;
}
if ($path !== '/' && is_file(__DIR__.$path) && !str_ends_with($path, '.php')) {
    return false;
}
readfile(__DIR__.'/index.html');
