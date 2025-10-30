<?php
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', TRUE);

session_name('php-mvc');
session_start();

// PHP 에러를 exception 핸들러에서 처리
set_error_handler(/** @throws ErrorException */ static function($errNo, $errStr, $errFile, $errLine) {
    // error_reporting 상태에 따라서 에러 표시 처리
    if (!(error_reporting() & $errNo)) {
        return false;
    }

    throw new ErrorException($errStr, $errNo, 0, $errFile, $errLine);
});

set_exception_handler(/** @throws JsonException */ static function(Throwable $exception) {
    ob_clean();

    header('Content-type: application/json');
    http_response_code($exception->getCode() ?: 500);
    exit(json_encode([
        'message' => $exception->getMessage(),
        'code' => $exception->getCode() ?: 500,
        // 'file' => $exception->getFile() ?: 500,
        // 'line' => $exception->getLine() ?: 500
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
});

// 클래스 인스턴스가 생성 될 때, namespace 및 class 이름 받아서 인클루딩
// namespace 값은 폴더 구조에 맞게 지정
spl_autoload_register(/** @throws Exception */ static function($class) {
    $class = str_replace('\\', '/', $class);
    if (is_file($class . '.php')) {
        include_once $class . '.php';
    } else {
        // 폴더 구조에 맞지 않는 클래스 인스턴스가 생성되면
        // libs 폴더에서 찾아보기
        $libsPath = 'Variety/resources/libs/';
        $libClass = strtolower($class);

        if (is_file($libsPath . $libClass . '/' . $libClass . '.php')) {
            include_once $libsPath . $libClass . '/' . $libClass . '.php';
        } else {
            throw new RuntimeException('\'' . $_SERVER['REQUEST_METHOD'] . ' ' . parse_url($_SERVER['REQUEST_URI'])['path'] . '\' Not Exists.', 404);
        }
    }
});

// Composer 불러오기
include_once 'Variety/resources/libs/vendor/autoload.php';

// 라우터 실행
new Controller\Router();