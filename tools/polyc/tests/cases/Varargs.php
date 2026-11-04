<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// expect: 3 args: 10 -1 7
// expect: 0 args:
// expect: sum=6 first=1

declare(strict_types=1);

namespace Test;

final class Varargs
{
    private static function show(string $label, mixed ...$args): void
    {
        Host::int(count($args));
        Host::str(' ');
        Host::str($label);
        for ($i = 0; $i < count($args); $i++) {
            Host::str(' ');
            Host::int(Cast::i32($args[$i]));
        }
        Host::nl();
    }

    private static function sum(int ...$numbers): int
    {
        $total = 0;
        for ($i = 0; $i < count($numbers); $i++) {
            $total += $numbers[$i];
        }
        return $total;
    }

    public static function main(): void
    {
        self::show('args:', 10, -1, 7);
        self::show('args:');
        Host::str('sum=');
        Host::int(self::sum(1, 2, 3));
        Host::str(' first=');
        Host::int(self::sum(1));
        Host::nl();
    }
}
