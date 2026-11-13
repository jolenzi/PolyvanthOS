<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// expect: n=42 big=18446744073709551615 small=200 neg=-5 word=4000000000
// expect: disk is true, other is false, 100% done
// expect: count=9 count=9 second=20 [(null)]
// expect: cost $5 \ "q" 42
// expect: disk
// expect: plain 7 ok

declare(strict_types=1);

namespace Test;

final class Format
{
    private static string $name = 'disk';
    private static ?string $nothing = null;
    private static Counter $counter;

    private static function second(int ...$values): void
    {
        Host::format("second={$values[1]}");
    }

    public static function main(): void
    {
        // Each variable gets the conversion that fits its type.
        $n = 42;
        $big = u64(0xFFFFFFFFFFFFFFFF);
        $small = u8(200);
        $negative = i8(-5);
        $word = u32(4000000000);
        Host::format("n=$n big=$big small=$small neg=$negative word=$word\n");

        // A bool is printed as text, and a % in the text stays a %.
        $name = self::$name;
        $yes = true;
        $no = false;
        Host::format("$name is $yes, other is $no, 100% done\n");

        // Properties, braces, array elements and null strings.
        $counter = self::$counter;
        $counter->count = 9;
        $none = self::$nothing;
        Host::format("count=$counter->count count={$counter->count} ");
        self::second(10, 20);
        Host::format(" [$none]\n");

        // Escapes work in the text around the variables.
        Host::format("cost \$5 \\ \"q\" $n\n");

        // A string with only a variable, and an ordinary printf format with its own arguments.
        Host::format("$name\n");
        Host::format("plain %d %s\n", 7, 'ok');
    }
}
