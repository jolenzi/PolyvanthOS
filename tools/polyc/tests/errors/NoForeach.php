<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: 'foreach' is not supported

declare(strict_types=1);

namespace Test;

final class NoForeach
{
    public static function main(): void
    {
        foreach ([1, 2] as $x) {
        }
    }
}
