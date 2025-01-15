<?php

namespace ryunosuke\hellowo;

class Message
{
    private string $id;
    private string $contents;

    public function __construct(string $id, string $contents)
    {
        $this->id       = $id;
        $this->contents = $contents;
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
}
