<?php

namespace Controller;

final class Response
{
    private function __construct(
        public readonly string $body,
        public readonly int $status,
        public readonly string $contentType
    ) {
        if ($status < 200 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP response status.');
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

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD' && !in_array($this->status, [204, 304], true)) {
            echo $this->body;
        }
    }
}
