<?php

namespace Controller\Board;

use Controller\HttpException;
use Model\Board\UserModel;

/**
 * 세션 로그인 상태.
 *
 * Router::guard()가 요청마다 load()를 호출해 현재 회원을 읽고,
 * 컨트롤러는 user()나 require()로 그 값을 사용합니다.
 */
final class Auth
{
    private static ?array $user = null;

    /**
     * 세션의 회원 ID로 회원을 읽습니다. 탈퇴 등으로 회원이 없으면 로그인 상태를 지웁니다.
     */
    public static function load(array $environments): void
    {
        self::$user = null;
        $id = $_SESSION['userId'] ?? null;
        if (!is_int($id)) {
            return;
        }
        self::$user = (new UserModel($environments))->find($id);
        if (self::$user === null) {
            unset($_SESSION['userId']);
        }
    }

    /**
     * @return array{id: int, login_id: string, display_name: string}|null
     */
    public static function user(): ?array
    {
        return self::$user;
    }

    /**
     * 로그인하지 않았으면 401을 던집니다. Router::filter()가 로그인 화면으로 보냅니다.
     *
     * @return array{id: int, login_id: string, display_name: string}
     * @throws HttpException
     */
    public static function require(): array
    {
        return self::$user ?? throw new HttpException('로그인이 필요합니다.', 401);
    }

    /**
     * 글쓴이 본인인지 확인합니다. 다른 회원의 글은 403입니다.
     *
     * @throws HttpException
     */
    public static function requireOwner(array $record): array
    {
        $user = self::require();
        if ((int) $record['user_id'] !== $user['id']) {
            throw new HttpException('작성자만 수정하거나 삭제할 수 있습니다.', 403);
        }
        return $user;
    }

    public static function login(array $user): void
    {
        // 로그인 전 세션 ID를 재사용하지 못하게 새 ID를 발급하고, CSRF 토큰도 새로 만듭니다.
        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
        $_SESSION['userId'] = $user['id'];
        self::$user = $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }
}
