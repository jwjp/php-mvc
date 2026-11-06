<?php

namespace Model\Board;

use Model\Model;

/**
 * 일정 시간 안의 시도 횟수를 셉니다. 로그인 실패, 메일 요청 제한에 사용합니다.
 *
 * 아이디·IP 원문 대신 해시만 저장하고, 시간 비교는 DB의 NOW()로 통일합니다.
 */
class RateLimiter extends Model
{
    private static function hash(string $bucket, string $key): string
    {
        return hash('sha256', $bucket . "\0" . $key);
    }

    public function tooMany(string $bucket, string $key, int $max, int $minutes): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM rate_limits
             WHERE bucket = :bucket AND key_hash = :key_hash AND created_at > NOW() - INTERVAL :minutes MINUTE'
        );
        $statement->bindValue(':bucket', $bucket);
        $statement->bindValue(':key_hash', self::hash($bucket, $key));
        $statement->bindValue(':minutes', $minutes, \PDO::PARAM_INT);
        $statement->execute();
        return (int) $statement->fetchColumn() >= $max;
    }

    public function hit(string $bucket, string $key): void
    {
        $this->pdo->prepare('INSERT INTO rate_limits (bucket, key_hash) VALUES (:bucket, :key_hash)')
            ->execute([':bucket' => $bucket, ':key_hash' => self::hash($bucket, $key)]);
        // 하루 지난 기록은 조금씩 지웁니다. 별도 cron 없이 테이블이 계속 커지지 않게 합니다.
        $this->pdo->exec('DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL 1 DAY LIMIT 100');
    }

    public function clear(string $bucket, string $key): void
    {
        $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket = :bucket AND key_hash = :key_hash')
            ->execute([':bucket' => $bucket, ':key_hash' => self::hash($bucket, $key)]);
    }
}
