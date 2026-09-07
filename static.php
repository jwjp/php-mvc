<?php

$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/')[0]);
$aliases = [
    'js' => 'js', 'css' => 'css', 'fonts' => 'fonts',
    'images' => 'images', 'html' => 'html',
];
$segments = explode('/', ltrim($path, '/'));
$alias = $aliases[$segments[0]] ?? null;
if ($alias === null && $path !== '/favicon.ico') {
    return false;
}

$base = realpath($path === '/favicon.ico' ? __DIR__ : __DIR__ . '/Variety/assets/' . $alias);
$invalidPath = preg_match('#[\x00-\x1f\x7f\\\\]#', $path) || preg_match('#(^|/)\.#', $path);
$file = $invalidPath ? false : ($path === '/favicon.ico'
    ? realpath(__DIR__ . '/favicon.ico')
    : realpath(__DIR__ . '/Variety/assets/' . $alias . '/' . implode('/', array_slice($segments, 1))));
$types = [
    'css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8',
    'html' => 'text/html; charset=UTF-8', 'png' => 'image/png', 'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    'ttf' => 'font/ttf', 'otf' => 'font/otf',
];
$type = $file === false ? null : ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null);
$valid = $file !== false && is_file($file) && $type !== null
    && $base !== false && str_starts_with($file, $base . DIRECTORY_SEPARATOR);

if (!$valid) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo 'Not Found';
    }
    return true;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD, OPTIONS');
    http_response_code($method === 'OPTIONS' ? 204 : 405);
    return true;
}
header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=3600');
if ($method === 'GET') {
    readfile($file);
}
return true;
