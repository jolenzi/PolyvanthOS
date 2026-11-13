<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// expect: 16 3 ff
// expect: b Debug 7 8 0
// expect: 5 6 true
// expect: 42 abcd
// expect: 3 4

declare(strict_types=1);

namespace Test;

final class Statics
{
    const SIZE = 1 << 4;
    const MASK = self::SIZE - 1;
    const DIGITS = '0123456789abcdef';
    #[Count(3)]
    const NAMES = ['Divide error', 'Debug', 'Reserved'];

    #[FixedArray('u8', self::SIZE)]
    private static array $buffer;
    #[FixedArray('u32', 3)]
    private static array $table = [7, 8];
    private static bool $flag = false;
    private static ?Counter $none = null;
    private static Counter $counter;
    #[FixedArray(Counter::class, 4)]
    private static array $counters;

    public static function main(): void
    {
        Host::int(count(self::$buffer));
        Host::str(' ');
        Host::int(count(self::NAMES));
        Host::str(' ');
        Host::hex(self::MASK * 17);
        Host::nl();

        Host::str(Cast::u8(self::DIGITS[11]) === 'b' ? 'b' : '?');
        Host::str(' ');
        Host::str(self::NAMES[1]);
        Host::str(' ');
        Host::int(self::$table[0]);
        Host::str(' ');
        Host::int(self::$table[1]);
        Host::str(' ');
        Host::int(self::$table[2]);
        Host::nl();

        self::$counter->step = 5;
        self::$counter->bump();
        Host::int(self::$counter->count);
        Host::str(' ');
        self::$counters[2]->step = 3;
        self::$counters[2]->bump();
        self::$counters[2]->bump();
        Host::int(self::$counters[2]->count);
        Host::str(' ');
        self::$flag = self::$none === null;
        Host::str(self::$flag ? 'true' : 'false');
        Host::nl();

        for ($i = 0; $i < self::SIZE; $i++) {
            self::$buffer[$i] = $i * 2;
        }
        Host::int(self::$buffer[15] + 12);
        Host::str(' ');
        $base = Mem::addr(self::$buffer);
        Mem::write8($base, 0x61);
        Mem::write8($base + 1, 0x62);
        Mem::write8($base + 2, 0x63);
        Mem::write8($base + 3, 0x64);
        for ($i = 0; $i < 4; $i++) {
            Host::str(self::letter(Mem::read8($base + $i)));
        }
        Host::nl();

        $scratch = Mem::stack(8);
        Mem::write8($scratch, 3);
        Mem::write8($scratch + 1, 4);
        Host::int(Mem::read8($scratch));
        Host::str(' ');
        Host::int(Mem::read8($scratch + 1));
        Host::nl();
    }

    private static function letter(u8 $c): string
    {
        switch ($c) {
            case 'a':
                return 'a';
            case 'b':
                return 'b';
            case 'c':
                return 'c';
        }
        return 'd';
    }
}
