<?php

namespace Controller\Board;

/**
 * 인증·재설정 메일을 보냅니다.
 *
 * MAIL.TRANSPORT = log 이면 LOG_PATH 파일에 메일을 추가하고(개발용),
 * mail 이면 PHP mail()로 보냅니다. 운영에서는 서버의 sendmail 설정이 필요합니다.
 */
final class Mailer
{
    public function __construct(private readonly array $environments)
    {
    }

    /**
     * 메일 본문에 넣을 절대 URL을 만듭니다.
     * 요청의 Host 헤더로 만들면 공격자가 링크 주소를 바꿀 수 있으므로 설정값만 사용합니다.
     */
    public function url(string $path): string
    {
        $base = $this->environments['APP']['BASE_URL'] ?? null;
        if (!is_string($base) || !preg_match('#^https?://[^/\s]+(/[^\s]*)?$#D', $base)) {
            throw new \RuntimeException('Set APP.BASE_URL to the absolute site URL to send e-mail links.');
        }
        return rtrim($base, '/') . $path;
    }

    public function send(string $to, string $subject, string $body): void
    {
        $mail = $this->environments['MAIL'] ?? [];
        $from = $mail['FROM'] ?? null;
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false
            || !is_string($from) || $from === '' || preg_match('/[\r\n]/', $from . $subject)) {
            throw new \InvalidArgumentException('Invalid e-mail recipient, sender or subject.');
        }

        $transport = $mail['TRANSPORT'] ?? 'log';
        if ($transport === 'log') {
            $this->appendToLog($mail['LOG_PATH'] ?? 'mail.log', $from, $to, $subject, $body);
            return;
        }
        if ($transport !== 'mail') {
            throw new \RuntimeException('MAIL.TRANSPORT must be log or mail.');
        }

        $headers = [
            'From' => $from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ];
        $sent = mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'B'), chunk_split(base64_encode($body)), $headers);
        if (!$sent) {
            throw new \RuntimeException('PHP mail() could not send the message.');
        }
    }

    private function appendToLog(string $path, string $from, string $to, string $subject, string $body): void
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $path)) {
            $path = $root . '/' . $path;
        }
        $message = "=== " . date('c') . " ===\nFrom: {$from}\nTo: {$to}\nSubject: {$subject}\n\n{$body}\n\n";
        if (file_put_contents($path, $message, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write MAIL.LOG_PATH.');
        }
    }
}
