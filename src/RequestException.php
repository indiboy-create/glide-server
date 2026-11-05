<?php

declare(strict_types=1);

namespace Youvision\GlideServer;

class RequestException extends \RuntimeException
{
    private int $statusCode;

    public function __construct(int $statusCode, string $message = '')
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
