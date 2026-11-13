<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: '.=' is not supported

declare(strict_types=1);

namespace Test;

final class ConcatAssign
{
    public static function main(): void
    {
        $text = 'a';
        $text .= 'b';
        Host::str($text);
    }
}
