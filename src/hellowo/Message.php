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

    public function getRetry(): int
    {
        return $this->retry;
    }
}
