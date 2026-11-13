<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// expect: 7 -1 18446744073709551615
// expect: 4294967296 255 0 -128
// expect: 1 0 1
// expect: 9223372036854775808 -9223372036854775808
// expect: 5 2 true

declare(strict_types=1);

namespace Test;

final class Arith
{
    const BIG = 0xFFFFFFFFFFFFFFFF;
    const SHIFTED = 1 << 32;

    public static function main(): void
    {
        $a = 3;
        $b = u64(4);
        Host::int($a + $b);
        Host::str(' ');
        Host::int($a - 4);
        Host::str(' ');
        Host::uint(self::BIG);
        Host::nl();

        $c = u32(0xFFFFFFFF);
        Host::uint(self::SHIFTED);
        Host::str(' ');
        Host::int(u8($c));
        Host::str(' ');
        Host::int(u8(256));
        Host::str(' ');
        Host::int(i8(0x80));
        Host::nl();

        Host::int((5 & 3) | (4 ^ 4));
        Host::str(' ');
        Host::int(1 / 2);
        Host::str(' ');
        Host::int(7 % 3);
        Host::nl();

        $big = u64(0x8000000000000000);
        Host::uint($big);
        Host::str(' ');
        Host::int(-9223372036854775807 - 1);
        Host::nl();

        $x = 10;
        $x -= 5;
        $y = $x / 2;
        Host::int($x);
        Host::str(' ');
        Host::int($y);
        Host::str(' ');
        Host::str(($x > 3 && $y < 3) ? 'true' : 'false');
        Host::nl();
    }
}
