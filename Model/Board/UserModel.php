<?php

namespace Model\Board;

use Model\Model;
use PDO;
use PDOException;

/**
 * 회원 가입, 로그인 확인, 이메일 인증, 비밀번호 변경.
 *
 * 비밀번호는 password_hash()로 저장하고 password_verify()로 비교합니다.
 * QueryBuilder의 PASSWORD 옵션 대신 표준 함수를 직접 써서 흐름을 드러냅니다.
 */
class UserModel extends Model
{
    public const VERIFY_EMAIL = 'verify_email';
    public const RESET_PASSWORD = 'reset_password';

    /** 없는 아이디로 로그인할 때도 같은 시간이 걸리도록 비교에 쓰는 해시입니다. */
    private const DUMMY_HASH = '$2y$10$eHmsQcwfhSZkI/gFB5Gnv.lgR0mYXuTsmljdSFVfF2gIPtSkNiolG';

    private const COLUMNS = 'id, login_id, display_name, email, email_verified_at, session_version';

    /**
     * 비밀번호 규칙을 검사합니다. bcrypt는 72바이트 이후를 무시하므로 더 긴 비밀번호는 받지 않습니다.
     *
     * @return array<string, string>
     */
    public static function passwordErrors(mixed $password, mixed $passwordConfirm, string $field = 'password'): array
    {
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
            return [$field => '비밀번호는 8자 이상, 72바이트 이하로 입력하세요.'];
        }
        if ($password !== $passwordConfirm) {
            return [$field . 'Confirm' => '비밀번호 확인이 일치하지 않습니다.'];
        }
        return [];
    }

    /**
     * 이메일을 소문자로 정리하고 형식을 검사합니다.
     */
    public static function emailError(mixed &$email): ?string
    {
        if (!is_string($email)) {
            return '이메일을 입력하세요.';
        }
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '올바른 이메일 주소를 입력하세요.';
        }
        return null;
    }

    /**
     * 가입 입력값을 정리하고 필드별 오류 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public static function signupErrors(mixed &$loginId, mixed &$displayName, mixed &$email, mixed $password, mixed $passwordConfirm): array
    {
        $errors = [];
        if (!is_string($loginId) || !preg_match('/^[a-z0-9_]{4,30}$/D', $loginId = strtolower(trim($loginId)))) {
            $errors['loginId'] = '아이디는 영문 소문자, 숫자, 밑줄로 4~30자를 입력하세요.';
        }
        if (($message = Text::check($displayName, '이름', 30)) !== null) {
            $errors['displayName'] = $message;
        }
        if (($message = self::emailError($email)) !== null) {
            $errors['email'] = $message;
        }
        return $errors + self::passwordErrors($password, $passwordConfirm);
    }

    /**
     * 회원을 만들고 ID를 반환합니다. 아이디나 이메일이 이미 있으면 해당 필드 이름('loginId', 'email')을 반환합니다.
     */
    public function create(string $loginId, string $displayName, string $email, string $password): int|string
    {
        try {
            $id = $this->queryBuilder()->table('users')->insert([
                'login_id' => $loginId,
                'display_name' => $displayName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ])->exec();
        } catch (PDOException $exception) {
            // 23000: UNIQUE 제약 위반. 동시에 같은 값으로 가입해도 하나만 성공합니다.
            if ($exception->getCode() === '23000') {
                return str_contains($exception->getMessage(), 'uq_users_email') ? 'email' : 'loginId';
            }
            throw $exception;
        }
        return (int) $id;
    }

    /**
     * 아이디와 비밀번호가 맞으면 회원 정보를, 아니면 null을 반환합니다.
     * 어느 쪽이 틀렸는지는 알려 주지 않습니다.
     */
    public function verify(mixed $loginId, mixed $password): ?array
    {
        if (!is_string($loginId) || !is_string($password) || $password === ''
            || strlen($password) > 72 || str_contains($password, "\0")) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ', password_hash FROM users WHERE login_id = :login_id');
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
        return self::normalize($user);
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        $user = $statement->fetch();
        return $user === false ? null : self::normalize($user);
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE email = :email');
        $statement->execute([':email' => $email]);
        $user = $statement->fetch();
        return $user === false ? null : self::normalize($user);
    }

    /**
     * 비밀번호를 바꾸고 session_version을 올려 다른 기기의 로그인을 끊습니다.
     * 남아 있는 비밀번호 재설정 링크도 함께 무효로 합니다.
     */
    public function changePassword(int $id, string $password): void
    {
        $this->queryBuilder()->transaction(function () use ($id, $password): void {
            $this->pdo->prepare('UPDATE users SET password_hash = :hash, session_version = session_version + 1 WHERE id = :id')
                ->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
            $this->pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE user_id = :id AND purpose = :purpose AND used_at IS NULL')
                ->execute([':id' => $id, ':purpose' => self::RESET_PASSWORD]);
        });
    }

    public function markEmailVerified(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id')
            ->execute([':id' => $id]);
    }

    /**
     * 메일로 보낼 일회용 토큰을 만듭니다. 같은 용도의 이전 토큰은 무효로 합니다.
     * DB에는 SHA-256 해시만 저장하므로 DB가 유출돼도 링크를 만들 수 없습니다.
     */
    public function issueToken(int $userId, string $purpose, int $minutes): string
    {
        $token = bin2hex(random_bytes(32));
        $this->queryBuilder()->transaction(function () use ($userId, $purpose, $minutes, $token): void {
            $this->pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE user_id = :id AND purpose = :purpose AND used_at IS NULL')
                ->execute([':id' => $userId, ':purpose' => $purpose]);
            $insert = $this->pdo->prepare(
                'INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at)
                 VALUES (:id, :purpose, :hash, NOW() + INTERVAL :minutes MINUTE)'
            );
            $insert->bindValue(':id', $userId, PDO::PARAM_INT);
            $insert->bindValue(':purpose', $purpose);
            $insert->bindValue(':hash', hash('sha256', $token));
            $insert->bindValue(':minutes', $minutes, PDO::PARAM_INT);
            $insert->execute();
        });
        return $token;
    }

    /**
     * 최근 몇 초 안에 같은 용도의 토큰을 보냈는지 확인합니다. 메일 재요청 간격을 제한합니다.
     */
    public function sentRecently(int $userId, string $purpose, int $seconds): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM auth_tokens WHERE user_id = :id AND purpose = :purpose AND created_at > NOW() - INTERVAL :seconds SECOND LIMIT 1'
        );
        $statement->bindValue(':id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':purpose', $purpose);
        $statement->bindValue(':seconds', $seconds, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchColumn() !== false;
    }

    /**
     * 사용하지 않았고 만료되지 않은 토큰이면 회원 ID를 반환합니다. 토큰을 쓰지는 않습니다.
     */
    public function tokenUser(string $purpose, mixed $token): ?int
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT user_id FROM auth_tokens
             WHERE token_hash = :hash AND purpose = :purpose AND used_at IS NULL AND expires_at > NOW()'
        );
        $statement->execute([':hash' => hash('sha256', $token), ':purpose' => $purpose]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * 토큰을 사용 처리하고 회원 ID를 반환합니다. 동시에 두 번 써도 한 번만 성공합니다.
     */
    public function consumeToken(string $purpose, mixed $token): ?int
    {
        $userId = $this->tokenUser($purpose, $token);
        if ($userId === null) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'UPDATE auth_tokens SET used_at = NOW()
             WHERE token_hash = :hash AND purpose = :purpose AND used_at IS NULL AND expires_at > NOW()'
        );
        $statement->execute([':hash' => hash('sha256', $token), ':purpose' => $purpose]);
        return $statement->rowCount() === 1 ? $userId : null;
    }

    private static function normalize(array $user): array
    {
        $user['id'] = (int) $user['id'];
        $user['session_version'] = (int) $user['session_version'];
        return $user;
    }
}
