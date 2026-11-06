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
     * 세션의 회원 ID로 회원을 읽습니다.
     * 회원이 없거나 비밀번호가 바뀌어 session_version이 다르면 로그인 상태를 지웁니다.
     */
    public static function load(array $environments): void
    {
        self::$user = null;
        $id = $_SESSION['userId'] ?? null;
        if (!is_int($id)) {
            return;
        }
        $user = (new UserModel($environments))->find($id);
        if ($user === null || $user['session_version'] !== ($_SESSION['sessionVersion'] ?? null)) {
            unset($_SESSION['userId'], $_SESSION['sessionVersion']);
            return;
        }
        self::$user = $user;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    /**
     * 로그인하지 않았으면 401을 던집니다. Router::filter()가 로그인 화면으로 보냅니다.
     *
     * @throws HttpException
     */
    public static function require(): array
    {
        return self::$user ?? throw new HttpException('로그인이 필요합니다.', 401);
    }

    /**
     * 글과 댓글을 쓰려면 이메일 인증이 필요합니다.
     *
     * @throws HttpException
     */
    public static function requireVerified(): array
    {
        $user = self::require();
        if ($user['email_verified_at'] === null) {
            throw new HttpException('이메일 인증을 마친 뒤 글과 댓글을 쓸 수 있습니다. 내 정보에서 인증 메일을 다시 받을 수 있습니다.', 403);
        }
        return $user;
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
        $_SESSION['sessionVersion'] = $user['session_version'];
        self::$user = $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }

    /**
     * 시도 횟수 제한에 쓰는 클라이언트 IP입니다.
     * 프록시 뒤에서는 웹서버가 REMOTE_ADDR을 실제 IP로 바꾸도록 설정하세요(X-Forwarded-For를 직접 믿지 않습니다).
     */
    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
