<?php

namespace Controller;

final class Response
{
    /**
     * @param array<string, string> $headers 추가로 보낼 응답 헤더
     */
    private function __construct(
        public readonly string $body,
        public readonly int $status,
        public readonly string $contentType,
        public readonly array $headers = []
    ) {
        if ($status < 200 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP response status.');
        }
        foreach ($headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/D', $name) || preg_match('/[\r\n\0]/', $value)) {
                throw new \InvalidArgumentException('Invalid HTTP response header.');
            }
        }
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ), $status, 'application/json; charset=UTF-8');
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, 'text/html; charset=UTF-8');
    }

    /**
     * 같은 사이트 안의 경로로 이동합니다. 폼 처리 뒤에는 303으로 GET 화면을 다시 엽니다.
     */
    public static function redirect(string $path, int $status = 303): self
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new \InvalidArgumentException('Redirect status must be 301, 302, 303, 307 or 308.');
        }
        // 외부 주소로 보내지 않도록 "/"로 시작하는 사이트 내부 경로만 받습니다.
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            throw new \InvalidArgumentException('Redirect target must be a local path.');
        }
        return new self('', $status, 'text/html; charset=UTF-8', ['Location' => $path]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD' && !in_array($this->status, [204, 304], true)) {
            echo $this->body;
        }
    }
}
