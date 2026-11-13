<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// expect: abc
// expect: hello kernel!
// expect: page size 4096, mask 4095
// expect: v2.0 n=3 big 18446744073709551615 neg -5

declare(strict_types=1);

namespace Test;

final class Concat
{
    const NAME = 'kernel';
    const GREETING = 'hello ' . self::NAME;
    const SIZE = 4096;
    const MASK = self::SIZE - 1;

    private static string $banner = 'v' . 2 . '.' . 0;

    public static function main(): void
    {
        Host::str('a' . 'b' . 'c');
        Host::nl();
        Host::str(self::GREETING . '!');
        Host::nl();
        Host::str('page size ' . self::SIZE . ', mask ' . self::MASK);
        Host::nl();
        Host::str(self::$banner);
        Host::str(' ');
        Host::str('n=' . 1 + 2); // in PHP 8 the + comes first, so this is 'n=' . 3
        Host::str(' ');
        Host::str('big ' . 0xFFFFFFFFFFFFFFFF);
        Host::str(' ');
        Host::str('neg ' . -5);
        Host::nl();
    }
}
