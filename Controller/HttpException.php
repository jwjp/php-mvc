<?php

namespace Controller;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(string $message, int $status = 400)
    {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException('HTTP error status must be between 400 and 599.');
        }
        parent::__construct($message, $status);
    }
}
