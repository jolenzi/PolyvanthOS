<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

/** A property, static or not. */
final class PropInfo
{
    /**
     * @param array<string,mixed>|null $default
     * @param array<string,list<array<string,mixed>>> $attrs
     */
    public function __construct(
        public readonly string $name,
        public readonly Type $type,
        public readonly bool $static,
        public readonly string $vis,
        public readonly ?array $default,
        public readonly array $attrs,
        public readonly int $line,
    ) {
    }
}
