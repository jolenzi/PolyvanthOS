<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

/** A mistake in the source being compiled. The message is shown as "file:line: error: ...". */
final class CompileError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $sourceFile = '', public readonly int $srcLine = 0)
    {
        parent::__construct($message);
    }

    public function describe(): string
    {
        $where = $this->sourceFile === '' ? 'polyc' : $this->sourceFile . ($this->srcLine > 0 ? ':' . $this->srcLine : '');
        return $where . ': error: ' . $this->getMessage();
    }
}
