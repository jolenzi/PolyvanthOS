<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// expect: 3 6 9
// expect: 1 24 true
// expect: 5 true

declare(strict_types=1);

namespace Test;

final class Objects
{
    public static function main(): void
    {
        $a = new Counter(3);
        $b = new Counter();
        $a->link($b);
        Host::int($a->bump());
        Host::str(' ');
        Host::int($a->bump());
        Host::str(' ');
        Host::int($a->bump());
        Host::nl();

        Host::int($b->bump());
        Host::str(' ');
        Host::int(Counter::SIZE);
        Host::str(' ');
        Host::str($a->next === $b ? 'true' : 'false');
        Host::nl();

        $view = Counter::at(Mem::addr($b));
        $view->count = 4;
        Host::int($b->bump());
        Host::str(' ');
        Host::str($view === $b && $b->next === null ? 'true' : 'false');
        Host::nl();
    }
}
