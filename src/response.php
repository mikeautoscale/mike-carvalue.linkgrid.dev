<?php

declare(strict_types=1);

namespace CarValue;

/** Immutable JSON response (status + payload). */
final class Response
{
    public function __construct(
        public array $data,
        public int $status = 200,
    ) {
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self($data, $status);
    }

    /** Emit headers and body to the client. */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
