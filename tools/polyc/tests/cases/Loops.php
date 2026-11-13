<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// expect: 100 0=one;1=two;2=three;
// expect: 97;98;99; 5 0:120;1:121;
// expect: 10 0
// expect: 1;2;3;
// expect: 1234 321 5 010,111,212,
// expect: 210 1357 40

declare(strict_types=1);

namespace Test;

final class Loops
{
    #[Count(3)]
    private const NAMES = ['one', 'two', 'three'];

    #[FixedArray('u32', 4)]
    private static array $numbers = [10, 20, 30, 40];

    #[FixedArray(Counter::class, 3)]
    private static array $counters;

    private static function sum(int ...$values): int
    {
        $total = 0;
        foreach ($values as $value) {
            $total += $value;
        }
        return $total;
    }

    private static function length(string $text): int
    {
        $length = 0;
        foreach ($text as $character) {
            $length += $character > 0 ? 1 : 0;
        }
        return $length;
    }

    public static function main(): void
    {
        // An array of numbers, then a constant array of strings with the index as the key.
        $total = 0;
        foreach (self::$numbers as $number) {
            $total += $number;
        }
        Host::int($total);
        Host::str(' ');
        foreach (self::NAMES as $index => $name) {
            Host::int($index);
            Host::str('=');
            Host::str($name);
            Host::str(';');
        }
        Host::nl();

        // A string is walked up to its zero byte; a variadic parameter is an array.
        foreach ('abc' as $letter) {
            Host::int($letter);
            Host::str(';');
        }
        Host::str(' ');
        Host::int(self::length('hello'));
        Host::str(' ');
        foreach ('xy' as $at => $letter) {
            Host::int($at);
            Host::str(':');
            Host::int($letter);
            Host::str(';');
        }
        Host::nl();
        Host::int(self::sum(1, 2, 3, 4));
        Host::str(' ');
        Host::int(self::sum());
        Host::nl();

        // An array of structs gives a pointer to each element, so the elements can be changed.
        foreach (self::$counters as $position => $counter) {
            $counter->step = $position + 1;
            $counter->bump();
        }
        foreach (self::$counters as $counter) {
            Host::int($counter->count);
            Host::str(';');
        }
        Host::nl();

        // range() includes both ends and counts down when the first is larger, as in PHP.
        foreach (range(1, 4) as $i) {
            Host::int($i);
        }
        Host::str(' ');
        foreach (range(3, 1) as $i) {
            Host::int($i);
        }
        Host::str(' ');
        foreach (range(5, 5) as $i) {
            Host::int($i);
        }
        Host::str(' ');
        foreach (range(10, 12) as $k => $v) {
            Host::int($k);
            Host::int($v);
            Host::str(',');
        }
        Host::nl();

        // Counting down with unsigned numbers must stop at 0 and not wrap around.
        $down = u64(0);
        foreach (range(u64(2), 0) as $j) {
            $down = $down * 10 + $j;
        }
        Host::uint($down);
        Host::str(' ');

        // continue and break work as in any loop.
        $seen = 0;
        foreach (range(1, 10) as $i) {
            if ($i % 2 === 0) {
                continue;
            }
            if ($i > 7) {
                break;
            }
            $seen = $seen * 10 + $i;
        }
        Host::int($seen);
        Host::str(' ');
        $partial = 0;
        foreach (self::$numbers as $number) {
            if ($number === 20) {
                continue;
            }
            if ($number === 40) {
                break;
            }
            $partial += $number;
        }
        Host::int($partial);
        Host::nl();
    }
}
