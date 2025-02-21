<?php

namespace ryunosuke\hellowo;

class Message
{
    private string $id;
    private string $contents;
    private int    $retry;

    public function __construct(string $id, string $contents, int $retry)
    {
        $this->id       = $id;
        $this->contents = $contents;
        $this->retry    = $retry;
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
}
