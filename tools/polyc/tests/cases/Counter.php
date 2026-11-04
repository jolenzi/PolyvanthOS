<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Test;

/** A struct class: it has properties, so it is a C struct and an object is a pointer to one. */
#[Size(24)]
final class Counter
{
    public u64 $count;
    public ?Counter $next = null;
    public u8 $step;

    public function __construct(int $step = 1)
    {
        $this->step = $step;
    }

    public function bump(): u64
    {
        $this->count += $this->step;
        return $this->count;
    }

    public function link(Counter $other): void
    {
        $this->next = $other;
    }
}
