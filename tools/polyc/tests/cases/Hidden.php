<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Test;

/** Used by the PrivateAccess error test. */
final class Hidden
{
    private static function secret(): void
    {
    }
}
