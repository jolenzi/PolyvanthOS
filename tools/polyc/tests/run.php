<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

/*
 * The polyc test runner. Run it with: php tools/polyc/tests/run.php
 *
 * cases/NAME.php      compiled, built with the host C compiler and run. Its output must match the
 *                     "// expect: ..." lines at the top of the file. Each defines Test\NAME::main().
 * errors/NAME.php     must be rejected, with the message in its "// error: ..." line.
 */

$here = __DIR__;
$polyc = dirname($here) . '/polyc';
$tmp = sys_get_temp_dir() . '/polyc-tests-' . getmypid();
mkdir($tmp, 0777, true);

function run(string $command): array
{
    $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}

/** The lines that follow "// $tag: " in a source file. */
function directives(string $file, string $tag): array
{
    preg_match_all('#^// ' . $tag . ': ?(.*)$#m', (string) file_get_contents($file), $m);
    return $m[1];
}

$cc = getenv('CC') ?: 'cc';
$failed = 0;
$total = 0;

foreach (glob($here . '/cases/*.php') as $file) {
    $name = basename($file, '.php');
    if (directives($file, 'expect') === []) {
        continue; // a helper class such as Host, not a test
    }
    $total++;
    $dir = "$tmp/$name";
    $cmd = sprintf('%s --psr4 Test=%s --allocator %s --out %s %s', escapeshellarg($polyc), escapeshellarg($here . '/cases'), escapeshellarg('Test\\Host::alloc'), escapeshellarg($dir), escapeshellarg($file));
    [$status, , $err] = run('php ' . $cmd);
    if ($status !== 0) {
        echo "FAIL $name: polyc failed\n$err";
        $failed++;
        continue;
    }
    file_put_contents("$dir/main.c", "#include \"polyc.h\"\nint main(void) { Test_main_placeholder(); return 0; }\n");
    $main = str_replace('Test_main_placeholder', $name . '_main', (string) file_get_contents("$dir/main.c"));
    file_put_contents("$dir/main.c", $main);
    $sources = glob("$dir/*.c");
    $build = sprintf('%s -std=c11 -Wall -Wextra -Werror -I%s -I%s -o %s %s', $cc, escapeshellarg($dir), escapeshellarg($here . '/native'), escapeshellarg("$dir/test"), implode(' ', array_map('escapeshellarg', $sources)));
    [$status, , $err] = run($build);
    if ($status !== 0) {
        echo "FAIL $name: the generated C does not compile\n$err";
        $failed++;
        continue;
    }
    [$status, $out, $err] = run(escapeshellarg("$dir/test"));
    $want = implode("\n", directives($file, 'expect')) . "\n";
    if ($status !== 0 || $out !== $want) {
        echo "FAIL $name\n--- expected\n$want--- got (exit $status)\n$out$err";
        $failed++;
        continue;
    }
    echo "ok   $name\n";
}

foreach (glob($here . '/errors/*.php') as $file) {
    $name = basename($file, '.php');
    $total++;
    $want = directives($file, 'error')[0] ?? '';
    $cmd = sprintf('php %s --psr4 Test=%s --out %s %s', escapeshellarg($polyc), escapeshellarg($here . '/cases'), escapeshellarg("$tmp/err-$name"), escapeshellarg($file));
    [$status, , $err] = run($cmd);
    if ($status !== 1 || !str_contains($err, $want)) {
        echo "FAIL $name: expected the error '$want'\n$err";
        $failed++;
        continue;
    }
    echo "ok   $name (rejected)\n";
}

run('rm -rf ' . escapeshellarg($tmp));
echo $failed === 0 ? "all $total compiler tests passed\n" : "$failed of $total compiler tests FAILED\n";
exit($failed === 0 ? 0 : 1);
