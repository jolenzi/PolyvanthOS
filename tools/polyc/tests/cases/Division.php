<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// expect: 3 -3 3 4
// expect: 9223372036854775807 14 2

declare(strict_types=1);

namespace Test;

final class Division
{
    public static function main(): void
    {
        $a = 7;
        $b = -7;
        $c = u64(7);
        Host::int($a / 2);
        Host::str(' ');
        Host::int($b / 2); // truncates toward zero, like intdiv
        Host::str(' ');
        Host::uint($c / 2);
        Host::str(' ');
        Host::int(intdiv(9, 2)); // the old spelling still works
        Host::nl();

        $big = u64(0xFFFFFFFFFFFFFFFF);
        Host::uint($big / 2); // unsigned: not -1 / 2
        Host::str(' ');
        $x = 100;
        $x /= 7;
        Host::int($x);
        Host::str(' ');
        $y = u8(250);
        $y /= 100;
        Host::int($y);
        Host::nl();
    }
}
