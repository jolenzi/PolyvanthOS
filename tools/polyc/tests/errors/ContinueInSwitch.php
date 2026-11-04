<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: must be directly inside a loop

declare(strict_types=1);

namespace Test;

final class ContinueInSwitch
{
    public static function main(): void
    {
        for ($i = 0; $i < 3; $i++) {
            switch ($i) {
                case 1:
                    continue;
            }
        }
    }
}
