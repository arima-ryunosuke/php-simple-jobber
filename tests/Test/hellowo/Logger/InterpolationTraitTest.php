<?php

namespace ryunosuke\Test\hellowo\Logger;

use ryunosuke\hellowo\Logger\InterpolationTrait;
use ryunosuke\Test\AbstractTestCase;

class InterpolationTraitTest extends AbstractTestCase
{
    use InterpolationTrait;

    function test_interpolate()
    {
        that($this)->interpolate('{null}, {double}+{double}, {string}, {array}, {array.a}, {array.b}, {object}, {object.c}, {stringable}, {undefined}', [
            'null'       => null,
            'double'     => 3.14,
            'string'     => 'S',
            'array'      => [
                'a' => 'A',
            ],
            'array.b'    => 'B',
            'object'     => (object) ['c' => 'C'],
            'stringable' => new class { public function __toString(): string { return 'Stringgable'; } },
        ])->is('NULL, 3.14+3.14, S, {array}, A, B, {object}, C, Stringgable, {undefined}');
    }
}
