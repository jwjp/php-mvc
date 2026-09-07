<?php

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

define('APP_ROOT', __DIR__);
define('APP_CONFIG_PATH', getenv('PHP_MVC_CONFIG') ?: APP_ROOT . '/.sample.ini');
$appDebug = false;

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $exception) use (&$appDebug): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $status = $exception instanceof Controller\HttpException ? $exception->getCode() : 500;
    if ($status >= 500) {
        error_log(get_class($exception) . ': ' . $exception->getMessage()
            . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    }
    http_response_code($status);
    header_remove('Content-Length');
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode([
            'message' => !$appDebug && $status >= 500
                ? '서버 처리 중 문제가 발생했습니다.'
                : $exception->getMessage(),
            'code' => $status,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");

if (require APP_ROOT . '/static.php') {
    return;
}

$composerAutoload = APP_ROOT . '/Variety/resources/libs/vendor/autoload.php';
if (!is_file($composerAutoload)) {
    throw new RuntimeException('Run composer --working-dir=Variety/resources/libs install.');
}
require $composerAutoload;

$environments = is_file(APP_CONFIG_PATH) ? @parse_ini_file(APP_CONFIG_PATH, true, INI_SCANNER_TYPED) : false;
if ($environments === false) {
    throw new RuntimeException('Copy .sample.ini.example to .sample.ini and configure the database.');
}
$appDebug = filter_var($environments['APP']['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
$timezone = $environments['APP']['TIMEZONE'] ?? 'UTC';
if (!is_string($timezone) || !@date_default_timezone_set($timezone)) {
    throw new RuntimeException('APP.TIMEZONE is not a valid timezone.');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name('php-mvc');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'secure' => filter_var($environments['APP']['SESSION_SECURE'] ?? false, FILTER_VALIDATE_BOOL)
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
]);
session_start();
header('Cache-Control: no-store');

new Controller\Router($environments);
