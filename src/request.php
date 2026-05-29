<?php

declare(strict_types=1);

namespace CarValue;

/** Minimal read-only HTTP request wrapper around query parameters. */
final class Request
{
    public function __construct(private array $query)
    {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET);
    }

    /** Raw query parameter, or $default when absent. */
    public function query(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }
}
