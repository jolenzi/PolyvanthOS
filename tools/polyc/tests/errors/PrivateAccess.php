<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: is private

declare(strict_types=1);

namespace Test;

final class PrivateAccess
{
    public static function main(): void
    {
        Hidden::secret();
    }
}
