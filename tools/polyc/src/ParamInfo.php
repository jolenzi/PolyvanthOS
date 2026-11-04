<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

final class ParamInfo
{
    /** @param array<string,mixed>|null $default */
    public function __construct(
        public readonly string $name,
        public readonly Type $type,
        public readonly bool $variadic,
        public readonly ?array $default,
    ) {
    }
}
