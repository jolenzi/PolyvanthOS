<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

/**
 * Compiles one method body, or one constant expression, to C.
 *
 * Integer rules, chosen so that C's own conversion rules never decide anything:
 *  - Arithmetic is done in 64 bits. The result is u64 if either side is u64, otherwise i64.
 *  - Storing into a narrower type (a u8 variable, a u32 parameter, ...) cuts the value down, the
 *    same way a C cast would. The generated code always writes that cast out.
 *  - Whole-number constants are written out again for each type they are used as.
 *  - Variables are not declared in PHP, so a variable gets the type of the first value stored in
 *    it. Use Cast::u64(0) and friends to pick another type. All variables are declared at the
 *    top of the C function.
 */
final class FunctionGen
{
    /** @var array<string,Type> */
    private array $locals = [];
    /** @var array<string,true> */
    private array $paramNames = [];
    /** @var list<string> */
    private array $buffers = [];
    /** @var list<string> */
    private array $lines = [];
    /** @var list<string> */
    private array $control = [];
    private int $indent = 1;

    public function __construct(
        private readonly CodeGen $gen,
        private readonly Program $program,
        private readonly ClassInfo $class,
        private readonly ?MethodInfo $method = null,
    ) {
    }

    // ---------------------------------------------------------------- entry points

    public function compileMethod(): string
    {
        $method = $this->method ?? throw new \LogicException('no method');
        foreach ($method->params as $p) {
            $this->locals[$p->name] = $p->variadic ? Type::array(Type::int(64, false), null) : $p->type;
            $this->paramNames[$p->name] = true;
        }
        $this->stmts($method->body ?? []);

        $decls = [];
        foreach ($this->locals as $name => $type) {
            if (!isset($this->paramNames[$name])) {
                $decls[] = '    ' . $this->gen->cType($type) . $this->local($name) . ' = ' . $this->zero($type) . ';';
            }
        }
        foreach ($this->buffers as $buffer) {
            $decls[] = '    ' . $buffer;
        }
        return $this->gen->signature($this->class, $method) . "\n{\n" . implode("\n", [...$decls, ...$this->lines]) . "\n}\n";
    }

    /** Compile an expression that is not inside a function: a constant or a static initializer. */
    public function constant(array $node): Ex
    {
        return $this->expr($node);
    }

    /** The C text of a static initializer for $type: a value, or a list in braces for an array. */
    public function initializer(array $node, Type $type): string
    {
        if ($type->kind === 'array') {
            if ($node['k'] !== 'arr') {
                $this->err('an array needs an array literal', $node);
            }
            if (count($node['items']) > $type->length) {
                $this->err('too many elements for the array', $node);
            }
            $items = array_map(fn (array $i): string => $this->coerce($this->expr($i), $type->elem, $i), $node['items']);
            return '{' . implode(', ', $items) . '}';
        }
        return $this->coerce($this->expr($node), $type, $node);
    }

    /** The type and element count of an array literal made of constants. */
    public function arrayLiteralType(array $node): Type
    {
        $items = array_map(fn (array $i): Ex => $this->expr($i), $node['items']);
        if ($items === []) {
            $this->err('an empty array constant has no type', $node);
        }
        $elem = $items[0]->t;
        foreach ($items as $item) {
            $elem = $this->unify($elem, $item->t, $node);
        }
        if (!in_array($elem->kind, ['int', 'string'], true)) {
            $this->err('array constants can hold whole numbers and strings', $node);
        }
        return Type::array($elem, count($items));
    }

    // ---------------------------------------------------------------- small helpers

    private function err(string $message, array $node): never
    {
        throw new CompileError($message, $this->class->file, $node['line'] ?? 0);
    }

    private function emit(string $line): void
    {
        $this->lines[] = str_repeat('    ', $this->indent) . $line;
    }

    private function local(string $name): string
    {
        return $this->gen->ident($name);
    }

    private function zero(Type $type): string
    {
        return match ($type->kind) {
            'int' => '0',
            'bool' => 'false',
            default => 'NULL',
        };
    }

    private function i64(): Type
    {
        return Type::int(64, true);
    }

    private function u64(): Type
    {
        return Type::int(64, false);
    }

    private function access(string $vis, ClassInfo $owner, string $what, array $node): void
    {
        if ($vis !== 'public' && $owner !== $this->class) {
            $this->err("$what is $vis", $node);
        }
    }

    private function findClass(string $name, array $node): ClassInfo
    {
        return $name === 'self' ? $this->class : $this->program->find($name, $this->class->file, $node['line'] ?? 0);
    }

    // ---------------------------------------------------------------- statements

    /** @param list<array<string,mixed>> $list */
    private function stmts(array $list): void
    {
        foreach ($list as $s) {
            $this->stmt($s);
        }
    }

    /** @param list<array<string,mixed>> $list */
    private function nested(array $list): void
    {
        $this->indent++;
        $this->stmts($list);
        $this->indent--;
    }

    /** @param array<string,mixed> $s */
    private function stmt(array $s): void
    {
        switch ($s['k']) {
            case 'expr':
                $this->emit($this->expr($s['e'], true)->c . ';');
                break;
            case 'block':
                $this->emit('{');
                $this->nested($s['body']);
                $this->emit('}');
                break;
            case 'if':
                $this->ifStmt($s);
                break;
            case 'while':
                $this->emit('while (' . $this->statementCond($s['cond']) . ') {');
                $this->loopBody($s['body']);
                $this->emit('}');
                break;
            case 'do':
                $this->emit('do {');
                $this->loopBody($s['body']);
                $this->emit('} while (' . $this->statementCond($s['cond']) . ');');
                break;
            case 'for':
                $this->forStmt($s);
                break;
            case 'switch':
                $this->switchStmt($s);
                break;
            case 'break':
                if ($this->control === []) {
                    $this->err("'break' outside a loop or switch", $s);
                }
                $this->emit('break;');
                break;
            case 'continue':
                if ($this->control === [] || end($this->control) === 'switch') {
                    $this->err("'continue' must be directly inside a loop (in PHP it acts like 'break' inside a switch)", $s);
                }
                $this->emit('continue;');
                break;
            case 'return':
                $this->returnStmt($s);
                break;
            default:
                $this->err("unsupported statement '{$s['k']}'", $s);
        }
    }

    /** @param list<array<string,mixed>> $body */
    private function loopBody(array $body): void
    {
        $this->control[] = 'loop';
        $this->nested($body);
        array_pop($this->control);
    }

    /** @param array<string,mixed> $s */
    private function ifStmt(array $s): void
    {
        $this->emit('if (' . $this->statementCond($s['cond']) . ') {');
        $this->nested($s['then']);
        while ($s['else'] !== null && count($s['else']) === 1 && $s['else'][0]['k'] === 'if') {
            $s = $s['else'][0];
            $this->emit('} else if (' . $this->statementCond($s['cond']) . ') {');
            $this->nested($s['then']);
        }
        if ($s['else'] !== null) {
            $this->emit('} else {');
            $this->nested($s['else']);
        }
        $this->emit('}');
    }

    /** @param array<string,mixed> $s */
    private function forStmt(array $s): void
    {
        $each = fn (array $list): string => implode(', ', array_map(fn (array $e): string => $this->expr($e, true)->c, $list));
        if (count($s['cond']) > 1) {
            $this->err("a 'for' loop can have only one condition", $s);
        }
        $init = $each($s['init']);
        $cond = $s['cond'] === [] ? '' : $this->statementCond($s['cond'][0]);
        $step = $each($s['step']);
        $this->emit("for ($init; $cond; $step) {");
        $this->loopBody($s['body']);
        $this->emit('}');
    }

    /** @param array<string,mixed> $s */
    private function switchStmt(array $s): void
    {
        $subject = $this->expr($s['subject']);
        if (!$subject->t->isInt()) {
            $this->err('switch needs a whole number, not ' . $subject->t->name(), $s['subject']);
        }
        $this->emit('switch (' . $this->convert($subject, $this->i64()) . ') {');
        $this->control[] = 'switch';
        foreach ($s['cases'] as $case) {
            foreach ($case['tests'] as $test) {
                if ($test === null) {
                    $this->emit('default:');
                    continue;
                }
                $e = $this->expr($test);
                if ($e->value === null) {
                    $this->err("a 'case' must be a constant whole number", $test);
                }
                $this->emit('case ' . $this->convert($e, $this->i64()) . ':');
            }
            $this->nested($case['body']);
        }
        array_pop($this->control);
        $this->emit('}');
    }

    /** @param array<string,mixed> $s */
    private function returnStmt(array $s): void
    {
        $ret = $this->method?->ret ?? Type::simple('void');
        if ($ret->kind === 'never') {
            $this->err("a function that returns 'never' cannot return", $s);
        }
        if ($s['e'] === null) {
            if ($ret->kind !== 'void') {
                $this->err('this function must return a value', $s);
            }
            $this->emit('return;');
            return;
        }
        if ($ret->kind === 'void') {
            $this->err("a 'void' function cannot return a value", $s);
        }
        $this->emit('return ' . $this->coerce($this->expr($s['e']), $ret, $s['e']) . ';');
    }

    // ---------------------------------------------------------------- conversions

    /** The C text for a whole number written as C type $to. */
    private function literalFor(int $v, bool $big, Type $to): string
    {
        if ($to->bits === 64) {
            if (!$to->signed) {
                return sprintf('0x%XUL', $v);
            }
            if ($big) {
                return sprintf('((int64_t)0x%XUL)', $v);
            }
            return match (true) {
                $v === PHP_INT_MIN => '(-9223372036854775807L - 1)',
                $v < 0 => '(-' . (-$v) . 'L)',
                default => $v . 'L',
            };
        }
        $min = $to->signed ? -(1 << ($to->bits - 1)) : 0;
        $max = $to->signed ? (1 << ($to->bits - 1)) - 1 : (1 << $to->bits) - 1;
        if (!$big && $v >= $min && $v <= $max) {
            return $v < 0 ? "($v)" : (string) $v;
        }
        return '((' . $to->c(fn ($c) => $c) . ')' . $this->literalFor($v, $big, $this->i64()) . ')';
    }

    /** Whole number to whole number. Always an explicit C cast, so no implicit conversion happens. */
    private function convert(Ex $e, Type $to): string
    {
        if ($e->value !== null) {
            return $this->literalFor($e->value, $e->big, $to);
        }
        if ($e->t->bits === $to->bits && $e->t->signed === $to->signed) {
            return $e->c;
        }
        return '(' . $to->c(fn ($c) => $c) . ')' . $e->c;
    }

    /** The type that arithmetic between two whole numbers is done in. */
    private function common(Ex $a, Ex $b): Type
    {
        return $a->t->isU64() || $b->t->isU64() ? $this->u64() : $this->i64();
    }

    /** One value for a variadic argument list: every argument is passed as a 64-bit word. */
    private function word(Ex $e, array $node): string
    {
        return match ($e->t->kind) {
            'int' => $e->value !== null ? '(uint64_t)' . $this->literalFor($e->value, $e->big, $this->i64()) : '(uint64_t)' . $e->c,
            'bool' => '(uint64_t)' . $e->c,
            'null' => '0',
            'string', 'class', 'array' => '(uint64_t)(uintptr_t)' . $e->c,
            default => $this->err('cannot pass ' . $e->t->name() . ' as a variadic argument', $node),
        };
    }

    /** C text for storing $e where $to is expected. Reports a mismatch as an error. */
    private function coerce(Ex $e, Type $to, array $node): string
    {
        if ($to->kind === 'mixed') {
            return $this->word($e, $node);
        }
        $from = $e->t;
        if ($to->isInt() && $from->isInt()) {
            return $this->convert($e, $to);
        }
        if ($to->kind === 'bool' && $from->kind === 'bool') {
            return $e->c;
        }
        if ($to->kind === 'string' && ($from->kind === 'string' || ($from->kind === 'null' && $to->nullable))) {
            return $e->c;
        }
        if ($to->kind === 'class') {
            if ($from->kind === 'null' && $to->nullable) {
                return 'NULL';
            }
            if ($from->kind === 'class' && $from->class === $to->class) {
                return $e->c;
            }
        }
        $this->err('cannot use ' . $from->name() . ' where ' . $to->name() . ' is expected', $node);
    }

    private function unify(Type $a, Type $b, array $node): Type
    {
        if ($a->isInt() && $b->isInt()) {
            return $a->isU64() || $b->isU64() ? $this->u64() : $this->i64();
        }
        if ($a->kind === 'null' && $b->isPointerLike()) {
            return $b->withNullable();
        }
        if ($b->kind === 'null' && $a->isPointerLike()) {
            return $a->withNullable();
        }
        if ($a->kind === $b->kind && $a->class === $b->class && $a->kind !== 'array') {
            return $a->nullable ? $a : $b;
        }
        $this->err("the two sides have different types: {$a->name()} and {$b->name()}", $node);
    }

    /** A condition for if, while and so on. */
    private function cond(array $node): string
    {
        $e = $this->expr($node);
        if (!in_array($e->t->kind, ['bool', 'int', 'class', 'string'], true)) {
            $this->err('cannot use ' . $e->t->name() . ' as a condition', $node);
        }
        return $e->c;
    }

    /** The condition for an if, while or for, without the parentheses the expression added. */
    private function statementCond(array $node): string
    {
        return $this->gen->unwrap($this->cond($node));
    }

    // ---------------------------------------------------------------- expressions

    /** @param array<string,mixed> $n */
    private function expr(array $n, bool $asStatement = false): Ex
    {
        return match ($n['k']) {
            'int' => new Ex($this->literalFor($n['v'], $n['big'], $n['big'] ? $this->u64() : $this->i64()), $n['big'] ? $this->u64() : $this->i64(), $n['v'], $n['big']),
            'str' => new Ex($this->gen->cString($n['v']), Type::simple('string')),
            'null' => new Ex('NULL', Type::simple('null')),
            'bool' => new Ex($n['v'] ? 'true' : 'false', Type::simple('bool')),
            'var' => $this->variable($n),
            'bin' => $this->binary($n),
            'un' => $this->unary($n),
            'tern' => $this->ternary($n),
            'assign' => $this->assign($n, $asStatement),
            'incdec' => $this->incdec($n, $asStatement),
            'call' => $this->call($n),
            'scall' => $this->staticCall($n),
            'mcall' => $this->methodCall($n),
            'sprop' => $this->staticProp($n),
            'cconst' => $this->classConst($n),
            'prop' => $this->property($n),
            'idx' => $this->index($n),
            'new' => $this->newObject($n),
            'arr' => $this->err('an array literal can only initialise a static property or a constant', $n),
            default => $this->err("unsupported expression '{$n['k']}'", $n),
        };
    }

    /** @param array<string,mixed> $n */
    private function variable(array $n): Ex
    {
        if ($n['name'] === 'this') {
            if ($this->method === null || $this->method->static) {
                $this->err('$this is only available in instance methods', $n);
            }
            return new Ex('this', Type::ofClass($this->class->fqcn));
        }
        $type = $this->locals[$n['name']] ?? $this->err("variable \${$n['name']} is used before it gets a value", $n);
        return new Ex($this->local($n['name']), $type);
    }

    /** @param array<string,mixed> $n */
    private function binary(array $n): Ex
    {
        $op = $n['op'];
        if ($op === '&&' || $op === '||') {
            return new Ex('(' . $this->cond($n['l']) . " $op " . $this->cond($n['r']) . ')', Type::simple('bool'));
        }
        $l = $this->expr($n['l']);
        $r = $this->expr($n['r']);
        if (in_array($op, ['==', '!=', '===', '!==', '<', '<=', '>', '>='], true)) {
            return $this->compare($op, $l, $r, $n);
        }
        if ($op === '/') {
            $this->err('use intdiv($a, $b) for division: PHP\'s / gives a float', $n);
        }
        if (!$l->t->isInt() || !$r->t->isInt()) {
            $this->err("'$op' needs whole numbers, not {$l->t->name()} and {$r->t->name()}", $n);
        }
        if ($op === '<<' || $op === '>>') {
            $type = $l->t->isU64() ? $this->u64() : $this->i64();
            return new Ex('(' . $this->convert($l, $type) . " $op " . $this->convert($r, $this->i64()) . ')', $type);
        }
        $type = $this->common($l, $r);
        return new Ex('(' . $this->convert($l, $type) . " $op " . $this->convert($r, $type) . ')', $type);
    }

    /** @param array<string,mixed> $n */
    private function compare(string $op, Ex $l, Ex $r, array $n): Ex
    {
        $c = match ($op) {
            '===' => '==', '!==' => '!=', default => $op,
        };
        $bool = Type::simple('bool');
        if ($l->t->isInt() && $r->t->isInt()) {
            $type = $this->common($l, $r);
            return new Ex('(' . $this->convert($l, $type) . " $c " . $this->convert($r, $type) . ')', $bool);
        }
        $equality = $c === '==' || $c === '!=';
        if ($equality && $l->t->kind === 'bool' && $r->t->kind === 'bool') {
            return new Ex("({$l->c} $c {$r->c})", $bool);
        }
        $sameClass = $l->t->isClass() && $r->t->isClass() && $l->t->class === $r->t->class;
        $nullCheck = ($l->t->kind === 'null' && $r->t->isPointerLike()) || ($r->t->kind === 'null' && $l->t->isPointerLike());
        if ($equality && ($sameClass || $nullCheck)) {
            return new Ex("({$l->c} $c {$r->c})", $bool);
        }
        $this->err("cannot compare {$l->t->name()} with {$r->t->name()}", $n);
    }

    /** @param array<string,mixed> $n */
    private function unary(array $n): Ex
    {
        if ($n['op'] === '!') {
            return new Ex('(!' . $this->cond($n['e']) . ')', Type::simple('bool'));
        }
        $e = $this->expr($n['e']);
        if (!$e->t->isInt()) {
            $this->err("'{$n['op']}' needs a whole number, not {$e->t->name()}", $n);
        }
        if ($n['op'] === '+') {
            return $e;
        }
        if ($e->value !== null && !$e->big) {
            $v = $n['op'] === '-' ? -$e->value : ~$e->value;
            return new Ex($this->literalFor($v, false, $this->i64()), $this->i64(), $v);
        }
        $type = $e->t->isU64() ? $this->u64() : $this->i64();
        return new Ex('(' . $n['op'] . $this->convert($e, $type) . ')', $type);
    }

    /** @param array<string,mixed> $n */
    private function ternary(array $n): Ex
    {
        $cond = $this->cond($n['c']);
        $a = $this->expr($n['a']);
        $b = $this->expr($n['b']);
        $type = $this->unify($a->t, $b->t, $n);
        $conv = fn (Ex $e): string => $type->isInt() ? $this->convert($e, $type) : ($e->t->kind === 'null' ? 'NULL' : $e->c);
        return new Ex("($cond ? {$conv($a)} : {$conv($b)})", $type);
    }

    /** @param array<string,mixed> $n */
    private function assign(array $n, bool $asStatement): Ex
    {
        $target = $n['t'];
        $value = $this->expr($n['e']);

        // The first assignment to a variable creates it, with the type of the value.
        if ($target['k'] === 'var' && $target['name'] !== 'this' && !isset($this->locals[$target['name']])) {
            if ($n['op'] !== '=') {
                $this->err("variable \${$target['name']} is used before it gets a value", $n);
            }
            if (!in_array($value->t->kind, ['int', 'bool', 'string', 'class'], true)) {
                $this->err("cannot create a variable from {$value->t->name()}: use a typed value such as Cast::u64(0) or a class", $n);
            }
            $this->locals[$target['name']] = $value->t;
        }
        $dest = $this->expr($target);
        $this->checkAssignable($target, $dest);

        if ($n['op'] === '=') {
            $c = "{$dest->c} = " . $this->coerce($value, $dest->t, $n['e']);
        } else {
            if (!$dest->t->isInt() || !$value->t->isInt()) {
                $this->err("'{$n['op']}' needs whole numbers", $n);
            }
            if ($n['op'] === '/=') {
                $this->err('use $x = intdiv($x, $y): PHP\'s / gives a float', $n);
            }
            $isShift = $n['op'] === '<<=' || $n['op'] === '>>=';
            $c = "{$dest->c} {$n['op']} " . $this->convert($value, $isShift ? $this->i64() : $this->common($dest, $value));
        }
        return new Ex($asStatement ? $c : "($c)", $dest->t);
    }

    /** @param array<string,mixed> $target */
    private function checkAssignable(array $target, Ex $dest): void
    {
        if ($dest->t->kind === 'array') {
            $this->err('whole arrays cannot be assigned, assign the elements', $target);
        }
        if ($target['k'] === 'idx') {
            $base = $target['base'];
            if ($base['k'] === 'cconst' || $this->expr($base)->t->kind === 'string') {
                $this->err('constants and strings cannot be changed', $target);
            }
            if ($dest->t->isClass()) {
                $this->err('an element that is an object cannot be replaced, assign its properties', $target);
            }
        }
        if ($target['k'] === 'sprop' && $dest->t->isClass() && str_starts_with($dest->c, '(&')) {
            $this->err('this static property holds a struct itself, assign its properties', $target);
        }
    }

    /** @param array<string,mixed> $n */
    private function incdec(array $n, bool $asStatement): Ex
    {
        $t = $this->expr($n['t']);
        if (!$t->t->isInt()) {
            $this->err("'{$n['op']}' needs a whole number", $n);
        }
        $this->checkAssignable($n['t'], $t);
        $c = $n['prefix'] ? $n['op'] . $t->c : $t->c . $n['op'];
        return new Ex($asStatement ? $c : "($c)", $t->t);
    }

    // ---------------------------------------------------------------- calls

    /** @param array<string,mixed> $n */
    private function call(array $n): Ex
    {
        $args = $n['args'];
        switch ($n['name']) {
            case 'ord':
                if (count($args) !== 1 || $args[0]['k'] !== 'str' || strlen($args[0]['v']) !== 1) {
                    $this->err('ord() needs one string literal of exactly one character', $n);
                }
                $v = ord($args[0]['v']);
                return new Ex((string) $v . 'L', $this->i64(), $v);
            case 'intdiv':
                if (count($args) !== 2) {
                    $this->err('intdiv() needs two arguments', $n);
                }
                $a = $this->expr($args[0]);
                $b = $this->expr($args[1]);
                if (!$a->t->isInt() || !$b->t->isInt()) {
                    $this->err('intdiv() needs whole numbers', $n);
                }
                if ($b->value === 0) {
                    $this->err('division by zero', $n);
                }
                $type = $this->common($a, $b);
                return new Ex('(' . $this->convert($a, $type) . ' / ' . $this->convert($b, $type) . ')', $type);
            case 'count':
                $a = count($args) === 1 ? $this->expr($args[0]) : $this->err('count() needs one argument', $n);
                if ($a->t->kind !== 'array') {
                    $this->err('count() needs an array', $n);
                }
                if ($a->t->length === null) {
                    return new Ex('((int64_t)' . $a->c . '_count)', $this->i64());
                }
                return new Ex($a->t->length . 'L', $this->i64(), $a->t->length);
        }
        $this->err("unknown function '{$n['name']}()': only ord, intdiv and count exist, everything else is a class method", $n);
    }

    /** @param array<string,mixed> $n */
    private function staticCall(array $n): Ex
    {
        $cls = $this->findClass($n['cls'], $n);
        $method = $cls->methods[$n['name']] ?? null;

        if ($method === null && $n['name'] === 'at' && $cls->isStruct()) {
            if (count($n['args']) !== 1) {
                $this->err('at() needs one argument: the address', $n);
            }
            $addr = $this->coerce($this->expr($n['args'][0]), $this->u64(), $n['args'][0]);
            return new Ex('((' . $this->gen->structName($cls->fqcn) . ' *)(uintptr_t)' . $addr . ')', Type::ofClass($cls->fqcn));
        }
        if ($method === null) {
            $this->err("class {$cls->fqcn} has no method {$n['name']}()", $n);
        }
        $this->access($method->vis, $cls, "method {$cls->short}::{$method->name}()", $n);
        if (isset($method->attrs['Intrinsic'])) {
            return $this->intrinsic($cls, $method, $n);
        }
        $self = null;
        if (!$method->static) {
            if ($this->method === null || $this->method->static || $cls !== $this->class) {
                $this->err("{$cls->short}::{$method->name}() is not static: call it on an object", $n);
            }
            $self = 'this';
        }
        return new Ex($method->cname . '(' . $this->callArgs($cls, $method, $n['args'], $self, $n) . ')', $method->ret);
    }

    /** @param array<string,mixed> $n */
    private function methodCall(array $n): Ex
    {
        $obj = $this->expr($n['obj']);
        if (!$obj->t->isClass()) {
            $this->err("cannot call a method on {$obj->t->name()}", $n);
        }
        $cls = $this->program->find($obj->t->class, $this->class->file, $n['line']);
        $method = $cls->methods[$n['name']] ?? $this->err("class {$cls->fqcn} has no method {$n['name']}()", $n);
        if ($method->static) {
            $this->err("{$cls->short}::{$method->name}() is static: call it as {$cls->short}::{$method->name}()", $n);
        }
        $this->access($method->vis, $cls, "method {$cls->short}::{$method->name}()", $n);
        return new Ex($method->cname . '(' . $this->callArgs($cls, $method, $n['args'], $obj->c, $n) . ')', $method->ret);
    }

    /**
     * The C argument list for a call. Variadic arguments become a count and an array of 64-bit
     * words: f("x", 1, 2) calls f("x", 2, (const uint64_t[]){1, 2}).
     *
     * @param list<array<string,mixed>> $nodes
     * @param array<string,mixed> $at
     */
    private function callArgs(ClassInfo $cls, MethodInfo $m, array $nodes, ?string $self, array $at): string
    {
        $out = $self === null ? [] : [$self];
        $fixed = array_values(array_filter($m->params, fn (ParamInfo $p): bool => !$p->variadic));
        $variadic = count($m->params) > count($fixed);
        if (count($nodes) > count($fixed) && !$variadic) {
            $this->err("{$cls->short}::{$m->name}() takes at most " . count($fixed) . ' arguments', $at);
        }
        foreach ($fixed as $i => $p) {
            if (isset($nodes[$i])) {
                $out[] = $this->coerce($this->expr($nodes[$i]), $p->type, $nodes[$i]);
            } elseif ($p->default !== null) {
                $out[] = $this->gen->defaultValue($cls, $p);
            } else {
                $this->err("{$cls->short}::{$m->name}() is missing the argument \${$p->name}", $at);
            }
        }
        if ($variadic) {
            $words = array_map(fn (array $a): string => $this->word($this->expr($a), $a), array_slice($nodes, count($fixed)));
            $out[] = (string) count($words);
            $out[] = $words === [] ? 'NULL' : '(const uint64_t[]){' . implode(', ', $words) . '}';
        }
        return implode(', ', $out);
    }

    /** @param array<string,mixed> $n */
    private function intrinsic(ClassInfo $cls, MethodInfo $m, array $n): Ex
    {
        $args = $n['args'];
        $key = $cls->short . '::' . $m->name;
        if (count($args) !== 1) {
            $this->err("$key() takes exactly one argument", $n);
        }

        if ($key === 'Mem::stack') {
            $size = $this->program->foldInt($args[0], $this->class);
            if ($size === null || $size <= 0) {
                $this->err('Mem::stack() needs a constant size in bytes', $n);
            }
            $name = 'stack_' . count($this->buffers);
            $this->buffers[] = 'uint8_t ' . $name . '[' . $size . '] __attribute__((aligned(16)));';
            return new Ex("(uint64_t)(uintptr_t)$name", $this->u64());
        }

        $a = $this->expr($args[0]);
        if ($key === 'Mem::addr') {
            if (!in_array($a->t->kind, ['class', 'array', 'string'], true)) {
                $this->err('Mem::addr() needs an object, an array or a string, not ' . $a->t->name(), $n);
            }
            return new Ex('(uint64_t)(uintptr_t)' . $a->c, $this->u64());
        }
        if ($cls->short === 'Cast') {
            if ($m->name === 'bool') {
                return new Ex('(' . $this->convert($a, $this->i64()) . ' != 0)', Type::simple('bool'));
            }
            if (!$a->t->isInt()) {
                $this->err("Cast::{$m->name}() needs a whole number, not {$a->t->name()}", $n);
            }
            $to = Type::named($m->name) ?? $this->err("unknown cast Cast::{$m->name}()", $n);
            return new Ex($this->convert($a, $to), $to, $a->value !== null ? $this->wrapValue($a->value, $to) : null);
        }
        $this->err("unknown intrinsic $key()", $n);
    }

    /** What a constant becomes after being cut down to $to, so a cast constant stays a constant. */
    private function wrapValue(int $v, Type $to): ?int
    {
        if ($to->bits === 64) {
            return $to->signed ? $v : null;
        }
        $v &= (1 << $to->bits) - 1;
        if ($to->signed && $v >= 1 << ($to->bits - 1)) {
            $v -= 1 << $to->bits;
        }
        return $v;
    }

    /** @param array<string,mixed> $n */
    private function newObject(array $n): Ex
    {
        if ($this->program->allocator === null) {
            $this->err("'new' needs an allocator: pass --allocator Class::method to polyc", $n);
        }
        $cls = $this->findClass($n['cls'], $n);
        if (!$cls->isStruct() && !isset($cls->methods['__construct'])) {
            $this->err("class {$cls->short} has no properties, so there is nothing to create", $n);
        }
        $cls->needsNew = true;
        $ctor = $cls->methods['__construct'] ?? null;
        $args = '';
        if ($ctor !== null) {
            $this->access($ctor->vis, $cls, 'the constructor', $n);
            $args = $this->callArgs($cls, $ctor, $n['args'], null, $n);
        } elseif ($n['args'] !== []) {
            $this->err("class {$cls->short} has no constructor, so it takes no arguments", $n);
        }
        return new Ex($this->gen->structName($cls->fqcn) . '_new(' . $args . ')', Type::ofClass($cls->fqcn, true));
    }

    // ---------------------------------------------------------------- members

    /** @param array<string,mixed> $n */
    private function staticProp(array $n): Ex
    {
        $cls = $this->findClass($n['cls'], $n);
        $prop = $cls->staticProps[$n['name']] ?? $this->err("class {$cls->fqcn} has no static property \${$n['name']}", $n);
        $this->access($prop->vis, $cls, "property {$cls->short}::\${$prop->name}", $n);
        $name = $cls->staticName($prop->name);
        // A static property of a struct type holds the struct itself, so its value is its address.
        $embedded = $prop->type->isClass() && !$prop->type->nullable;
        return new Ex($embedded ? "(&$name)" : $name, $prop->type);
    }

    /** @param array<string,mixed> $n */
    private function property(array $n): Ex
    {
        $obj = $this->expr($n['obj']);
        if (!$obj->t->isClass()) {
            $this->err("cannot read a property of {$obj->t->name()}", $n);
        }
        $cls = $this->program->find($obj->t->class, $this->class->file, $n['line']);
        $prop = $cls->props[$n['name']] ?? $this->err("class {$cls->fqcn} has no property \${$n['name']}", $n);
        $this->access($prop->vis, $cls, "property {$cls->short}::\${$prop->name}", $n);
        return new Ex($obj->c . '->' . $prop->name, $prop->type);
    }

    /** @param array<string,mixed> $n */
    private function classConst(array $n): Ex
    {
        $cls = $this->findClass($n['cls'], $n);
        if (!isset($cls->consts[$n['name']])) {
            if ($n['name'] === 'SIZE' && $cls->isStruct()) {
                return new Ex('((uint64_t)sizeof(' . $this->gen->structName($cls->fqcn) . '))', $this->u64());
            }
            $this->err("class {$cls->fqcn} has no constant {$n['name']}", $n);
        }
        $const = $this->gen->constant($cls, $n['name'], $n);
        $this->access($const->vis, $cls, "constant {$cls->short}::{$const->name}", $n);
        return new Ex($const->c, $const->type, $const->value, $const->big);
    }

    /** @param array<string,mixed> $n */
    private function index(array $n): Ex
    {
        $base = $this->expr($n['base']);
        $index = $this->expr($n['index']);
        if (!$index->t->isInt()) {
            $this->err('an array index must be a whole number', $n);
        }
        $i = $this->convert($index, $this->i64());
        if ($base->t->kind === 'string') {
            return new Ex("((uint8_t){$base->c}[$i])", Type::int(8, false));
        }
        if ($base->t->kind !== 'array') {
            $this->err('cannot index ' . $base->t->name(), $n);
        }
        if ($base->t->elem->isClass()) {
            return new Ex("(&{$base->c}[$i])", $base->t->elem);
        }
        return new Ex("{$base->c}[$i]", $base->t->elem);
    }
}
