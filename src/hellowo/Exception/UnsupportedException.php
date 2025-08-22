<?php

namespace ryunosuke\hellowo\Exception;

use LogicException;

class UnsupportedException extends LogicException
{
    use ExceptionTrait;
}
