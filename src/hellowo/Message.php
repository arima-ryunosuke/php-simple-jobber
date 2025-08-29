<?php

namespace ryunosuke\hellowo;

class Message
{
    private string $id;
    private string $contents;
    private int    $retry;
    private int    $timeout;

    public function __construct(string $id, string $contents, int $retry, int $timeout)
    {
        $this->id       = $id;
        $this->contents = $contents;
        $this->retry    = $retry;
        $this->timeout  = $timeout;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getContents(): string
    {
        return $this->contents;
    }

    public function getJsonContents(int $flags = JSON_THROW_ON_ERROR | JSON_OBJECT_AS_ARRAY) /*:mixed*/
    {
        return json_decode($this->contents, null, 2147483647, $flags);
    }

    public function getRetry(): int
    {
        return $this->retry;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }
}
