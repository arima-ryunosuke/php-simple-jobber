<?php

namespace ryunosuke\hellowo;

class Message
{
    private $original;

    private string $id;
    private string $contents;

    public function __construct($original, string $id, string $contents)
    {
        $this->original = $original;
        $this->id       = $id;
        $this->contents = $contents;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function getOriginal()
    {
        return $this->original;
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
