<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

final class MethodInfo
{
    /**
     * @param list<ParamInfo> $params
     * @param list<array<string,mixed>>|null $body
     * @param array<string,list<array<string,mixed>>> $attrs
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $static,
        public readonly string $vis,
        public readonly array $params,
        public readonly Type $ret,
        public readonly ?array $body,
        public readonly array $attrs,
        public readonly int $line,
        public readonly string $cname,
        public readonly ?string $export,
    ) {
    }
}
