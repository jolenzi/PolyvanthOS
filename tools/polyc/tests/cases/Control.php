<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// expect: sum=45 evens=20 do=3
// expect: 0:zero 1:small 2:small 3:small 9:other 65:letter
// expect: 5 inner break ok
// expect: 1 2 3 5

declare(strict_types=1);

namespace Test;

final class Control
{
    public static function describe(int $n): string
    {
        switch ($n) {
            case 0:
                return 'zero';
            case 1:
            case 2:
            case 3:
                return 'small';
            case 'A':
                return 'letter';
            default:
                return 'other';
        }
    }

    private static function show(int $n): void
    {
        Host::int($n);
        Host::str(':');
        Host::str(self::describe($n));
        Host::str(' ');
    }

    public static function main(): void
    {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += $i;
        }
        $evens = 0;
        $j = 0;
        while (true) {
            $j++;
            if ($j > 8) {
                break;
            }
            if ($j % 2 === 1) {
                continue;
            }
            $evens += $j;
        }
        $d = 0;
        do {
            $d++;
        } while ($d < 3);
        Host::str('sum=');
        Host::int($sum);
        Host::str(' evens=');
        Host::int($evens);
        Host::str(' do=');
        Host::int($d);
        Host::nl();

        for ($n = 0; $n < 4; $n++) {
            self::show($n);
        }
        self::show(9);
        Host::int(65);
        Host::str(':');
        Host::str(self::describe(65));
        Host::nl();

        $k = 0;
        for ($a = 0; $a < 3; $a++) {
            for ($b = 0; $b < 3; $b++) {
                if ($b === 2) {
                    break;
                }
                $k++;
            }
        }
        Host::int($k - 1);
        Host::str(' inner break ok');
        Host::nl();

        $t = 0;
        for ($m = 1, $t = 0; $m <= 5; $m++, $t += 0) {
            if ($m === 4) {
                continue;
            }
            Host::int($m);
            if ($m < 3 || $m === 5) {
                Host::str($m === 5 ? '' : ' ');
            } else {
                Host::str(' ');
            }
        }
        Host::nl();
    }
}
