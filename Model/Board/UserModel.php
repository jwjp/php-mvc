<?php

namespace Model\Board;

use Model\Model;
use PDOException;

/**
 * 회원 가입과 로그인 확인.
 *
 * 비밀번호는 password_hash()로 저장하고 password_verify()로 비교합니다.
 * QueryBuilder의 PASSWORD 옵션 대신 표준 함수를 직접 써서 흐름을 드러냅니다.
 */
class UserModel extends Model
{
    /** 없는 아이디로 로그인할 때도 같은 시간이 걸리도록 비교에 쓰는 해시입니다. */
    private const DUMMY_HASH = '$2y$10$eHmsQcwfhSZkI/gFB5Gnv.lgR0mYXuTsmljdSFVfF2gIPtSkNiolG';

    /**
     * 가입 입력값을 정리하고 필드별 오류 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public static function signupErrors(mixed &$loginId, mixed &$displayName, mixed $password, mixed $passwordConfirm): array
    {
        $errors = [];
        if (!is_string($loginId) || !preg_match('/^[a-z0-9_]{4,30}$/D', $loginId = strtolower(trim($loginId)))) {
            $errors['loginId'] = '아이디는 영문 소문자, 숫자, 밑줄로 4~30자를 입력하세요.';
        }
        if (($message = Text::check($displayName, '이름', 30)) !== null) {
            $errors['displayName'] = $message;
        }
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
            // bcrypt는 72바이트 이후를 무시하므로 더 긴 비밀번호는 받지 않습니다.
            $errors['password'] = '비밀번호는 8자 이상, 72바이트 이하로 입력하세요.';
        } elseif ($password !== $passwordConfirm) {
            $errors['passwordConfirm'] = '비밀번호 확인이 일치하지 않습니다.';
        }
        return $errors;
    }

    /**
     * 회원을 만들고 ID를 반환합니다. 이미 있는 아이디면 null입니다.
     */
    public function create(string $loginId, string $displayName, string $password): ?int
    {
        try {
            $id = $this->queryBuilder()->table('users')->insert([
                'login_id' => $loginId,
                'display_name' => $displayName,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ])->exec();
        } catch (PDOException $exception) {
            // 23000: UNIQUE 제약 위반. 동시에 같은 아이디로 가입해도 하나만 성공합니다.
            if ($exception->getCode() === '23000') {
                return null;
            }
            throw $exception;
        }
        return (int) $id;
    }

    /**
     * 아이디와 비밀번호가 맞으면 회원 정보를, 아니면 null을 반환합니다.
     * 어느 쪽이 틀렸는지는 알려 주지 않습니다.
     *
     * @return array{id: int, login_id: string, display_name: string}|null
     */
    public function verify(mixed $loginId, mixed $password): ?array
    {
        if (!is_string($loginId) || !is_string($password) || $password === ''
            || strlen($password) > 72 || str_contains($password, "\0")) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT id, login_id, display_name, password_hash FROM users WHERE login_id = :login_id');
        $statement->execute([':login_id' => strtolower(trim($loginId))]);
        $user = $statement->fetch();

        $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
        if ($user === false || !$valid) {
            return null;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->queryBuilder()->table('users')
                ->update(['password_hash' => password_hash($password, PASSWORD_DEFAULT)])
                ->where('id', '=', (int) $user['id'])
                ->exec();
        }
        unset($user['password_hash']);
        $user['id'] = (int) $user['id'];
        return $user;
    }

    /**
     * @return array{id: int, login_id: string, display_name: string}|null
     */
    public function find(int $id): ?array
    {
        $rows = $this->queryBuilder()->table('users')
            ->select('id', 'login_id', 'display_name')
            ->where('id', '=', $id)
            ->exec();
        if ($rows === []) {
            return null;
        }
        $rows[0]['id'] = (int) $rows[0]['id'];
        return $rows[0];
    }
}
