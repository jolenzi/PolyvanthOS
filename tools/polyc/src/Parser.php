<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

/**
 * Turns the tokens from PHP's own tokenizer into a tree.
 *
 * Only the subset of PHP that polyc compiles is accepted. Everything else is an error with a
 * line number, so a feature that is not supported can never be silently miscompiled.
 *
 * The tree is plain arrays. Every node has 'k' (its kind) and 'line'. Expression kinds:
 * int str null bool var bin un assign incdec tern call scall mcall sprop cconst prop idx new arr.
 * Statement kinds: expr if while do for switch break continue return block.
 */
final class Parser
{
    private const BINARY = [
        '||' => 1, '&&' => 2, '|' => 3, '^' => 4, '&' => 5,
        '==' => 6, '!=' => 6, '===' => 6, '!==' => 6,
        '<' => 7, '<=' => 7, '>' => 7, '>=' => 7,
        '<<' => 8, '>>' => 8,
        '+' => 9, '-' => 9,
        '*' => 10, '/' => 10, '%' => 10,
    ];

    private const ASSIGN_OPS = ['=', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '<<=', '>>='];

    private const MODIFIERS = ['public', 'protected', 'private', 'static', 'final', 'readonly', 'abstract'];

    /** @var list<\PhpToken> */
    private array $tokens = [];
    private int $pos = 0;
    private string $namespace = '';
    /** @var array<string,string> alias => fully qualified name */
    private array $uses = [];

    public function __construct(private readonly string $source, private readonly string $file)
    {
    }

    /** @return array{namespace:string, classes:list<array<string,mixed>>} */
    public function parseFile(): array
    {
        foreach (\PhpToken::tokenize($this->source) as $token) {
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG])) {
                continue;
            }
            $this->tokens[] = $token;
        }

        $classes = [];
        while (!$this->atEnd()) {
            if ($this->isWord('declare')) {
                $this->skipDeclare();
            } elseif ($this->isWord('namespace')) {
                $this->next();
                $this->namespace = ltrim($this->nameToken(), '\\');
                $this->expect(';');
            } elseif ($this->isWord('use')) {
                $this->parseUse();
            } else {
                $classes[] = $this->parseClass();
            }
        }
        return ['namespace' => $this->namespace, 'classes' => $classes];
    }

    // ---------------------------------------------------------------- token helpers

    private function atEnd(): bool
    {
        return $this->pos >= count($this->tokens);
    }

    private function peek(int $ahead = 0): ?\PhpToken
    {
        return $this->tokens[$this->pos + $ahead] ?? null;
    }

    private function next(): \PhpToken
    {
        $token = $this->peek() ?? $this->fail('unexpected end of file');
        $this->pos++;
        return $token;
    }

    private function line(): int
    {
        $token = $this->peek() ?? ($this->tokens[count($this->tokens) - 1] ?? null);
        return $token === null ? 0 : $token->line;
    }

    private function fail(string $message, ?int $line = null): never
    {
        throw new CompileError($message, $this->file, $line ?? $this->line());
    }

    private function isOp(string $text, int $ahead = 0): bool
    {
        $token = $this->peek($ahead);
        return $token !== null && $token->text === $text;
    }

    private function isWord(string $word, int $ahead = 0): bool
    {
        $token = $this->peek($ahead);
        return $token !== null && strcasecmp($token->text, $word) === 0 && !$token->is(T_VARIABLE)
            && !$token->is(T_CONSTANT_ENCAPSED_STRING);
    }

    private function accept(string $text): bool
    {
        if ($this->isOp($text)) {
            $this->pos++;
            return true;
        }
        return false;
    }

    private function expect(string $text): void
    {
        if (!$this->accept($text)) {
            $found = $this->peek()?->text ?? 'end of file';
            $this->fail("expected '$text' but found '$found'");
        }
    }

    private function expectWord(string $word): void
    {
        if (!$this->isWord($word)) {
            $found = $this->peek()?->text ?? 'end of file';
            $this->fail("expected '$word' but found '$found'");
        }
        $this->pos++;
    }

    /** An identifier. Reserved words are fine here because PHP allows them as member names. */
    private function identifier(): string
    {
        $token = $this->next();
        if (!preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $token->text)) {
            $this->fail("expected a name but found '{$token->text}'", $token->line);
        }
        return $token->text;
    }

    private function nameToken(): string
    {
        $token = $this->next();
        if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_ARRAY, T_STATIC])) {
            $this->fail("expected a name but found '{$token->text}'", $token->line);
        }
        return $token->text;
    }

    // ---------------------------------------------------------------- file level

    private function skipDeclare(): void
    {
        $this->next();
        $this->expect('(');
        while (!$this->accept(')')) {
            $this->next();
        }
        $this->expect(';');
    }

    private function parseUse(): void
    {
        $this->next();
        if ($this->isWord('function') || $this->isWord('const')) {
            $this->fail("only 'use' for classes is supported");
        }
        do {
            $name = ltrim($this->nameToken(), '\\');
            $alias = substr($name, (int) strrpos('\\' . $name, '\\'));
            if ($this->isWord('as')) {
                $this->next();
                $alias = $this->identifier();
            }
            $this->uses[$alias] = $name;
        } while ($this->accept(','));
        $this->expect(';');
    }

    /** Resolve a class name written in the source to a fully qualified one (without a leading \). */
    private function resolveClass(string $name): string
    {
        if (strtolower($name) === 'self') {
            return 'self';
        }
        if ($name[0] === '\\') {
            return substr($name, 1);
        }
        $first = explode('\\', $name, 2);
        if (isset($this->uses[$first[0]])) {
            return $this->uses[$first[0]] . (isset($first[1]) ? '\\' . $first[1] : '');
        }
        return $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
    }

    // ---------------------------------------------------------------- attributes and types

    /** @return list<array{name:string,args:list<array<string,mixed>>,line:int}> */
    private function parseAttributes(): array
    {
        $attrs = [];
        while ($this->isOp('#[')) {
            $this->next();
            do {
                if ($this->isOp(']')) {
                    break;
                }
                $line = $this->line();
                $name = $this->nameToken();
                $args = $this->isOp('(') ? $this->parseArgs() : [];
                $attrs[] = ['name' => ltrim(substr($name, (int) strrpos('\\' . $name, '\\')), '\\'), 'args' => $args, 'line' => $line];
            } while ($this->accept(','));
            $this->expect(']');
        }
        return $attrs;
    }

    /** @return array{name:string,nullable:bool}|null */
    private function parseType(): ?array
    {
        $nullable = $this->accept('?');
        $token = $this->peek();
        if ($token === null || !$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_STATIC])) {
            if ($nullable) {
                $this->fail('expected a type after ?');
            }
            return null;
        }
        $this->next();
        $name = $token->text;
        if ($this->isOp('|')) {
            $this->fail('union types are not supported');
        }
        if (Type::named(strtolower($name)) !== null || strtolower($name) === 'array') {
            return ['name' => strtolower($name), 'nullable' => $nullable];
        }
        return ['name' => $this->resolveClass($name), 'nullable' => $nullable];
    }

    // ---------------------------------------------------------------- classes

    /** @return array<string,mixed> */
    private function parseClass(): array
    {
        $attrs = $this->parseAttributes();
        $line = $this->line();
        $final = false;
        while ($this->isWord('final') || $this->isWord('readonly')) {
            $final = $final || $this->isWord('final');
            $this->next();
        }
        if ($this->isWord('abstract') || $this->isWord('interface') || $this->isWord('trait') || $this->isWord('enum')) {
            $this->fail("'{$this->peek()->text}' is not supported, only classes");
        }
        $this->expectWord('class');
        $name = $this->identifier();
        if ($this->isWord('extends') || $this->isWord('implements')) {
            $this->fail('inheritance is not supported');
        }
        $this->expect('{');

        $class = [
            'name' => ($this->namespace === '' ? '' : $this->namespace . '\\') . $name,
            'short' => $name, 'attrs' => $attrs, 'final' => $final, 'line' => $line,
            'consts' => [], 'props' => [], 'methods' => [],
        ];
        while (!$this->accept('}')) {
            $this->parseMember($class);
        }
        return $class;
    }

    /** @param array<string,mixed> $class */
    private function parseMember(array &$class): void
    {
        $attrs = $this->parseAttributes();
        $line = $this->line();
        $mods = [];
        while (in_array(strtolower($this->peek()?->text ?? ''), self::MODIFIERS, true)) {
            $mods[] = strtolower($this->next()->text);
        }
        $vis = 'public';
        foreach (['public', 'protected', 'private'] as $v) {
            if (in_array($v, $mods, true)) {
                $vis = $v;
            }
        }
        $static = in_array('static', $mods, true);

        if ($this->isWord('const')) {
            $this->next();
            // "const int X = 1" or "const X = 1": a type is present when a name follows a name.
            if (!$this->isOp('=', 1)) {
                $this->parseType();
            }
            do {
                $cline = $this->line();
                $name = $this->identifier();
                $this->expect('=');
                $class['consts'][] = ['name' => $name, 'expr' => $this->parseExpr(), 'vis' => $vis, 'attrs' => $attrs, 'line' => $cline];
            } while ($this->accept(','));
            $this->expect(';');
            return;
        }

        if ($this->isWord('function')) {
            $this->next();
            $name = $this->identifier();
            $params = $this->parseParams();
            $ret = null;
            if ($this->accept(':')) {
                $ret = $this->parseType();
            }
            $body = null;
            if ($this->isOp('{')) {
                $body = $this->parseBlock();
            } else {
                $this->expect(';');
            }
            $class['methods'][] = [
                'name' => $name, 'static' => $static, 'vis' => $vis, 'params' => $params,
                'ret' => $ret, 'body' => $body, 'attrs' => $attrs, 'line' => $line,
            ];
            return;
        }

        $type = $this->parseType();
        do {
            $token = $this->next();
            if (!$token->is(T_VARIABLE)) {
                $this->fail("expected a property but found '{$token->text}'", $token->line);
            }
            $default = $this->accept('=') ? $this->parseExpr() : null;
            $class['props'][] = [
                'name' => substr($token->text, 1), 'type' => $type, 'static' => $static, 'vis' => $vis,
                'default' => $default, 'attrs' => $attrs, 'line' => $token->line,
            ];
        } while ($this->accept(','));
        $this->expect(';');
    }

    /** @return list<array<string,mixed>> */
    private function parseParams(): array
    {
        $this->expect('(');
        $params = [];
        while (!$this->accept(')')) {
            $this->parseAttributes();
            $line = $this->line();
            $type = $this->parseType();
            $variadic = $this->accept('...');
            $token = $this->next();
            if (!$token->is(T_VARIABLE)) {
                $this->fail("expected a parameter but found '{$token->text}'", $token->line);
            }
            $default = $this->accept('=') ? $this->parseExpr() : null;
            $params[] = ['name' => substr($token->text, 1), 'type' => $type, 'variadic' => $variadic, 'default' => $default, 'line' => $line];
            if (!$this->isOp(')')) {
                $this->expect(',');
            }
        }
        return $params;
    }

    // ---------------------------------------------------------------- statements

    /** @return list<array<string,mixed>> */
    private function parseBlock(): array
    {
        $this->expect('{');
        $stmts = [];
        while (!$this->accept('}')) {
            $stmts[] = $this->parseStatement();
        }
        return $stmts;
    }

    /** A statement body: a block, or one statement, as a list. @return list<array<string,mixed>> */
    private function parseBody(): array
    {
        if ($this->isOp('{')) {
            return $this->parseBlock();
        }
        return [$this->parseStatement()];
    }

    /** @return array<string,mixed> */
    private function parseStatement(): array
    {
        $line = $this->line();
        $token = $this->peek() ?? $this->fail('unexpected end of file');

        if ($token->text === '{') {
            return ['k' => 'block', 'body' => $this->parseBlock(), 'line' => $line];
        }
        if ($token->text === ';') {
            $this->next();
            return ['k' => 'block', 'body' => [], 'line' => $line];
        }
        if ($token->is(T_VARIABLE) || !preg_match('/^[A-Za-z_]/', $token->text) && !$token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            return $this->parseExprStatement($line);
        }

        switch (strtolower($token->text)) {
            case 'if':
                return $this->parseIf();
            case 'while':
                $this->next();
                $this->expect('(');
                $cond = $this->parseExpr();
                $this->expect(')');
                return ['k' => 'while', 'cond' => $cond, 'body' => $this->parseBody(), 'line' => $line];
            case 'do':
                $this->next();
                $body = $this->parseBody();
                $this->expectWord('while');
                $this->expect('(');
                $cond = $this->parseExpr();
                $this->expect(')');
                $this->expect(';');
                return ['k' => 'do', 'cond' => $cond, 'body' => $body, 'line' => $line];
            case 'for':
                return $this->parseFor();
            case 'switch':
                return $this->parseSwitch();
            case 'break':
            case 'continue':
                $this->next();
                if (!$this->isOp(';')) {
                    $this->fail("'" . strtolower($token->text) . "' with a level is not supported");
                }
                $this->expect(';');
                return ['k' => strtolower($token->text), 'line' => $line];
            case 'return':
                $this->next();
                $e = $this->isOp(';') ? null : $this->parseExpr();
                $this->expect(';');
                return ['k' => 'return', 'e' => $e, 'line' => $line];
            case 'foreach':
            case 'try':
            case 'throw':
            case 'echo':
            case 'goto':
            case 'unset':
            case 'global':
            case 'function':
                $this->fail("'" . strtolower($token->text) . "' is not supported");
        }
        return $this->parseExprStatement($line);
    }

    /** @return array<string,mixed> */
    private function parseExprStatement(int $line): array
    {
        $e = $this->parseExpr();
        $this->expect(';');
        return ['k' => 'expr', 'e' => $e, 'line' => $line];
    }

    /** @return array<string,mixed> */
    private function parseIf(): array
    {
        $line = $this->line();
        $this->next();
        $this->expect('(');
        $cond = $this->parseExpr();
        $this->expect(')');
        $then = $this->parseBody();
        $else = null;
        if ($this->isWord('elseif')) {
            $else = [$this->parseIf()];
        } elseif ($this->isWord('else')) {
            $this->next();
            $else = $this->isWord('if') ? [$this->parseIf()] : $this->parseBody();
        }
        return ['k' => 'if', 'cond' => $cond, 'then' => $then, 'else' => $else, 'line' => $line];
    }

    /** @return array<string,mixed> */
    private function parseFor(): array
    {
        $line = $this->line();
        $this->next();
        $this->expect('(');
        $parts = [];
        foreach ([';', ';', ')'] as $end) {
            $list = [];
            while (!$this->isOp($end)) {
                $list[] = $this->parseExpr();
                if (!$this->accept(',')) {
                    break;
                }
            }
            $this->expect($end);
            $parts[] = $list;
        }
        return ['k' => 'for', 'init' => $parts[0], 'cond' => $parts[1], 'step' => $parts[2], 'body' => $this->parseBody(), 'line' => $line];
    }

    /** @return array<string,mixed> */
    private function parseSwitch(): array
    {
        $line = $this->line();
        $this->next();
        $this->expect('(');
        $subject = $this->parseExpr();
        $this->expect(')');
        $this->expect('{');
        $cases = [];
        while (!$this->accept('}')) {
            $tests = [];
            $cline = $this->line();
            // Several labels in a row share one body: "case 1: case 2: ..." or "default:".
            while (true) {
                if ($this->isWord('case')) {
                    $this->next();
                    $tests[] = $this->parseExpr();
                } elseif ($this->isWord('default')) {
                    $this->next();
                    $tests[] = null;
                } else {
                    break;
                }
                if (!$this->accept(':')) {
                    $this->expect(';');
                }
            }
            if ($tests === []) {
                $this->fail("expected 'case' or 'default'");
            }
            $body = [];
            while (!$this->isWord('case') && !$this->isWord('default') && !$this->isOp('}')) {
                $body[] = $this->parseStatement();
            }
            $cases[] = ['tests' => $tests, 'body' => $body, 'line' => $cline];
        }
        return ['k' => 'switch', 'subject' => $subject, 'cases' => $cases, 'line' => $line];
    }

    // ---------------------------------------------------------------- expressions

    /** @return array<string,mixed> */
    public function parseExpr(): array
    {
        $left = $this->parseTernary();
        $token = $this->peek();
        if ($token !== null && in_array($token->text, self::ASSIGN_OPS, true) && !$token->is(T_VARIABLE)) {
            if (!in_array($left['k'], ['var', 'prop', 'sprop', 'idx'], true)) {
                $this->fail('cannot assign to this expression');
            }
            $this->next();
            return ['k' => 'assign', 'op' => $token->text, 't' => $left, 'e' => $this->parseExpr(), 'line' => $token->line];
        }
        return $left;
    }

    /** @return array<string,mixed> */
    private function parseTernary(): array
    {
        $cond = $this->parseBinary(1);
        if ($this->isOp('?')) {
            $line = $this->line();
            $this->next();
            if ($this->isOp(':')) {
                $this->fail("'?:' is not supported");
            }
            $a = $this->parseExpr();
            $this->expect(':');
            $b = $this->parseExpr();
            return ['k' => 'tern', 'c' => $cond, 'a' => $a, 'b' => $b, 'line' => $line];
        }
        return $cond;
    }

    /** @return array<string,mixed> */
    private function parseBinary(int $minPrec): array
    {
        $left = $this->parseUnary();
        while (true) {
            $token = $this->peek();
            if ($token === null || $token->is(T_VARIABLE) || !isset(self::BINARY[$token->text])) {
                break;
            }
            $prec = self::BINARY[$token->text];
            if ($prec < $minPrec) {
                break;
            }
            $this->next();
            $right = $this->parseBinary($prec + 1);
            $left = ['k' => 'bin', 'op' => $token->text, 'l' => $left, 'r' => $right, 'line' => $token->line];
        }
        return $left;
    }

    /** @return array<string,mixed> */
    private function parseUnary(): array
    {
        $token = $this->peek() ?? $this->fail('unexpected end of file');
        $line = $token->line;
        if ($token->is([T_INT_CAST, T_BOOL_CAST, T_STRING_CAST, T_DOUBLE_CAST, T_ARRAY_CAST, T_OBJECT_CAST])) {
            $this->fail("casts are not supported: use u8(), u64() and so on");
        }
        if (in_array($token->text, ['!', '-', '+', '~'], true) && !$token->is(T_VARIABLE)) {
            $this->next();
            return ['k' => 'un', 'op' => $token->text, 'e' => $this->parseUnary(), 'line' => $line];
        }
        if ($token->text === '++' || $token->text === '--') {
            $this->next();
            return ['k' => 'incdec', 'op' => $token->text, 'prefix' => true, 't' => $this->parseUnary(), 'line' => $line];
        }
        return $this->parsePostfix($this->parsePrimary());
    }

    /**
     * @param array<string,mixed> $e
     * @return array<string,mixed>
     */
    private function parsePostfix(array $e): array
    {
        while (true) {
            $token = $this->peek();
            if ($token === null) {
                return $e;
            }
            $line = $token->line;
            if ($token->text === '[') {
                $this->next();
                $index = $this->parseExpr();
                $this->expect(']');
                $e = ['k' => 'idx', 'base' => $e, 'index' => $index, 'line' => $line];
            } elseif ($token->text === '->') {
                $this->next();
                $name = $this->identifier();
                if ($this->isOp('(')) {
                    $e = ['k' => 'mcall', 'obj' => $e, 'name' => $name, 'args' => $this->parseArgs(), 'line' => $line];
                } else {
                    $e = ['k' => 'prop', 'obj' => $e, 'name' => $name, 'line' => $line];
                }
            } elseif ($token->text === '++' || $token->text === '--') {
                $this->next();
                $e = ['k' => 'incdec', 'op' => $token->text, 'prefix' => false, 't' => $e, 'line' => $line];
            } else {
                return $e;
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function parseArgs(): array
    {
        $this->expect('(');
        $args = [];
        while (!$this->accept(')')) {
            if ($this->isOp('...')) {
                $this->fail('argument unpacking is not supported');
            }
            if ($this->peek()?->is(T_STRING) && $this->isOp(':', 1) && !$this->isOp('::', 1)) {
                $this->fail('named arguments are not supported');
            }
            $args[] = $this->parseExpr();
            if (!$this->isOp(')')) {
                $this->expect(',');
            }
        }
        return $args;
    }

    /** @return array<string,mixed> */
    private function parsePrimary(): array
    {
        $token = $this->next();
        $line = $token->line;

        if ($token->is(T_VARIABLE)) {
            return ['k' => 'var', 'name' => substr($token->text, 1), 'line' => $line];
        }
        if ($token->is(T_LNUMBER) || $token->is(T_DNUMBER)) {
            return $this->intLiteral($token);
        }
        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            return ['k' => 'str', 'v' => $this->unquote($token), 'line' => $line];
        }
        if ($token->text === '"' || $token->is([T_START_HEREDOC, T_ENCAPSED_AND_WHITESPACE])) {
            $this->fail('string interpolation and heredocs are not supported, use plain strings', $line);
        }
        if ($token->text === '(') {
            $e = $this->parseExpr();
            $this->expect(')');
            return $e;
        }
        if ($token->text === '[') {
            return $this->parseArrayLiteral(']', $line);
        }
        if ($token->is(T_NEW)) {
            $name = $this->resolveClass($this->nameToken());
            $args = $this->isOp('(') ? $this->parseArgs() : [];
            return ['k' => 'new', 'cls' => $name, 'args' => $args, 'line' => $line];
        }
        if ($token->is(T_ARRAY) && $this->isOp('(')) {
            $this->next();
            return $this->parseArrayLiteral(')', $line);
        }
        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC])) {
            return $this->parseNamePrimary($token);
        }
        $this->fail("unexpected '{$token->text}'", $line);
    }

    /** @return array<string,mixed> */
    private function parseArrayLiteral(string $close, int $line): array
    {
        $items = [];
        while (!$this->accept($close)) {
            $item = $this->parseExpr();
            if ($this->isOp('=>')) {
                $this->fail('array keys are not supported, arrays are plain lists');
            }
            $items[] = $item;
            if (!$this->isOp($close)) {
                $this->expect(',');
            }
        }
        return ['k' => 'arr', 'items' => $items, 'line' => $line];
    }

    /** @return array<string,mixed> */
    private function parseNamePrimary(\PhpToken $token): array
    {
        $line = $token->line;
        $lower = strtolower($token->text);

        if ($this->isOp('::')) {
            $this->next();
            $cls = $lower === 'static' ? $this->fail("'static::' is not supported, use self:: or the class name") : $this->resolveClass($token->text);
            $next = $this->next();
            if ($next->is(T_VARIABLE)) {
                return ['k' => 'sprop', 'cls' => $cls, 'name' => substr($next->text, 1), 'line' => $line];
            }
            if (!preg_match('/^[A-Za-z_]/', $next->text)) {
                $this->fail("unexpected '{$next->text}'", $next->line);
            }
            if ($this->isOp('(')) {
                return ['k' => 'scall', 'cls' => $cls, 'name' => $next->text, 'args' => $this->parseArgs(), 'line' => $line];
            }
            return ['k' => 'cconst', 'cls' => $cls, 'name' => $next->text, 'line' => $line];
        }
        if ($lower === 'null') {
            return ['k' => 'null', 'line' => $line];
        }
        if ($lower === 'true' || $lower === 'false') {
            return ['k' => 'bool', 'v' => $lower === 'true', 'line' => $line];
        }
        if ($this->isOp('(')) {
            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $this->fail('only the built-in functions u8() to i64(), bool(), intdiv() and count() can be called');
            }
            return ['k' => 'call', 'name' => $lower, 'args' => $this->parseArgs(), 'line' => $line];
        }
        $this->fail("unknown constant '{$token->text}': constants belong to a class, write Class::NAME", $line);
    }

    // ---------------------------------------------------------------- literals

    /** @return array<string,mixed> */
    private function intLiteral(\PhpToken $token): array
    {
        $text = strtolower(str_replace('_', '', $token->text));
        $line = $token->line;
        if (str_starts_with($text, '0x')) {
            $digits = ltrim(substr($text, 2), '0');
            if (strlen($digits) > 16) {
                $this->fail("integer '{$token->text}' does not fit in 64 bits", $line);
            }
            $digits = str_pad($digits, 16, '0', STR_PAD_LEFT);
            $value = ((int) hexdec(substr($digits, 0, 8)) << 32) | (int) hexdec(substr($digits, 8));
            return ['k' => 'int', 'v' => $value, 'big' => $value < 0, 'line' => $line];
        }
        if (str_starts_with($text, '0b')) {
            $value = (int) bindec(substr($text, 2));
            return ['k' => 'int', 'v' => $value, 'big' => false, 'line' => $line];
        }
        if (str_contains($text, '.') || (str_contains($text, 'e') && !str_starts_with($text, '0x'))) {
            $this->fail('floating point numbers are not supported', $line);
        }
        if ($token->is(T_DNUMBER) || !ctype_digit($text)) {
            $this->fail("'{$token->text}' does not fit in a signed 64-bit integer, write it in hex", $line);
        }
        return ['k' => 'int', 'v' => (int) $text, 'big' => false, 'line' => $line];
    }

    /** UTF-8 bytes for a code point. (The mbstring extension is not required.) */
    private static function utf8(int $cp): string
    {
        return match (true) {
            $cp < 0x80 => chr($cp),
            $cp < 0x800 => chr(0xC0 | $cp >> 6) . chr(0x80 | $cp & 0x3F),
            $cp < 0x10000 => chr(0xE0 | $cp >> 12) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F),
            default => chr(0xF0 | $cp >> 18) . chr(0x80 | $cp >> 12 & 0x3F) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F),
        };
    }

    private function unquote(\PhpToken $token): string
    {
        $raw = substr($token->text, 1, -1);
        if ($token->text[0] === "'") {
            return strtr($raw, ["\\\\" => "\\", "\\'" => "'"]);
        }
        return (string) preg_replace_callback(
            '/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
            static function (array $m): string {
                if ($m[1] !== '') {
                    return ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'][$m[1]];
                }
                if (($m[2] ?? '') !== '') {
                    return chr(octdec($m[2]) & 0xFF);
                }
                if (($m[3] ?? '') !== '') {
                    return chr((int) hexdec($m[3]));
                }
                return self::utf8((int) hexdec($m[4] ?? '0'));
            },
            $raw,
        );
    }
}
