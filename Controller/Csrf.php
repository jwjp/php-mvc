<?php

namespace Controller;

final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \LogicException('Start the session before using CSRF protection.');
        }
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function verify(mixed $token): void
    {
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site'
            || !is_string($token)
            || !hash_equals(self::token(), $token)) {
            throw new HttpException('CSRF 토큰이 유효하지 않습니다. 페이지를 새로고침한 뒤 다시 시도하세요.', 403);
        }
    }
}
