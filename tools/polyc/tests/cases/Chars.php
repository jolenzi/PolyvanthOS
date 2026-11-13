<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// expect: 97 10 true 7 97 b
// expect: 1 2 2 3 3 120

declare(strict_types=1);

namespace Test;

final class Chars
{
    private const NEWLINE = "\n";

    private static function code(u8 $c): u8
    {
        return $c;
    }

    private static function kind(u8 $c): int
    {
        switch ($c) {
            case 'a':
                return 1;
            case 'b':
            case self::NEWLINE:
                return 2;
            default:
                return 3;
        }
    }

    public static function main(): void
    {
        // A one-character string becomes its byte where a number is wanted.
        $c = u8(97);
        Host::int(self::code('a'));
        Host::str(' ');
        Host::int(self::code("\n"));
        Host::str(' ');
        Host::str($c === 'a' ? 'true' : 'false');
        Host::str(' ');
        Host::int('c' - $c + 5); // 99 - 97 + 5
        Host::str(' ');
        $flag = true;
        Host::int($flag ? 'a' : 0); // in a ternary with a number
        Host::str(' ');
        Host::str('b'); // and it is still a string where a string is wanted
        Host::nl();

        // In a switch, as a literal or as a constant.
        Host::int(self::kind('a'));
        Host::str(' ');
        Host::int(self::kind('b'));
        Host::str(' ');
        Host::int(self::kind("\n"));
        Host::str(' ');
        Host::int(self::kind('z'));
        Host::str(' ');

        // u8() accepts one, and compound assignment does too.
        $d = u8('0');
        $d += 3;
        Host::int($d - '0');
        Host::str(' ');
        $x = u8('x');
        Host::int($x);
        Host::nl();
    }
}
