<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Arch\X86_64;

/** CPU instructions. The methods marked #[Native] are in native/x86_64.h. */
#[Header('x86_64.h')]
final class CPU
{
    #[Native]
    public static function hlt(): void
    {
    }
}
