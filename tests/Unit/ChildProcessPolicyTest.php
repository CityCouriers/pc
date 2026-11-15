<?php

declare(strict_types=1);

/**
 * Polityka procesów potomnych: żaden proces uruchamiany przez testy ani przez kod pakietu nie dziedziczy stdin Pesta.
 * Msys bash z odziedziczonym pty mintty urywa bieg Pesta z kodem 0 (zob. Tests\Support\Child), a CI na Linuksie i konsole
 * PowerShell/cmd tego nie pokazują, więc pilnujemy tego statycznie.
 *
 * @param string $source kod PHP
 *
 * @return list<string> naruszenia (puste, gdy kod jest zgodny)
 */
function childProcessViolations(string $source): array
{
    // Tokenizer, nie regex: komentarze i napisy (też z `/*` albo nazwą funkcji w środku) nie mylą skanera.
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $found = [];
    $needsClose = false;
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || ($tokens[$index + 1] ?? null) !== '(') {
            continue;
        }
        $previous = $tokens[$index - 1] ?? null;
        $isCall = !is_array($previous) || !in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
        $name = strtolower($token[1]);
        if ($isCall && in_array($name, ['shell_exec', 'exec', 'passthru', 'system', 'popen', 'pcntl_exec'], true)) {
            $found[] = 'powłoka bez kontroli stdin (shell_exec, exec, passthru, system, popen)';
        }
        if ($isCall && $name === 'proc_open') {
            $stdin = childProcessStdin($tokens, $index + 1);
            if ($stdin === null) {
                $found[] = 'proc_open bez deskryptora 0 (stdin)';
            } elseif ($stdin === 'file') {
                // Wtyczka Pest Mutate podmienia wrapper strumieni protokołu `file` w procesie mutanta (#73).
                $found[] = 'proc_open ze stdin przez protokół file (użyj rurki zamykanej zaraz po uruchomieniu)';
            } else {
                $needsClose = true;
            }
        }
    }
    if ($needsClose && !childProcessClosesStdin($tokens)) {
        $found[] = 'proc_open ze stdin z rurki bez zamknięcia $pipes[0] (fclose albo EmptyInput::close)';
    }

    return $found;
}

/**
 * Deskryptor stdin w nawiasach wywołania (od otwierającego): `null`, gdy nie ma klucza `0 =>`; `file` albo `pipe`, gdy
 * wartością jest tablica z tym protokołem; w pozostałych przypadkach (zmienna, wywołanie) `other`.
 *
 * @param list<array{int, string, int}|string> $tokens
 */
function childProcessStdin(array $tokens, int $open): ?string
{
    $depth = 0;
    for ($i = $open; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        $depth += $token === '(' || $token === '[' ? 1 : 0;
        $depth -= $token === ')' || $token === ']' ? 1 : 0;
        if (is_array($token) && $token[0] === T_LNUMBER && $token[1] === '0' && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_ARROW) {
            $protocol = $tokens[$i + 3] ?? null;

            return ($tokens[$i + 2] ?? null) === '[' && is_array($protocol) ? trim($protocol[1], '\'"') : 'other';
        }
        if ($depth === 0) {
            break;
        }
    }

    return null;
}

/**
 * Czy plik zamyka rurkę stdin: `fclose($pipes[0])` albo `->close($pipes)` (EmptyInput).
 *
 * @param list<array{int, string, int}|string> $tokens
 */
function childProcessClosesStdin(array $tokens): bool
{
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || ($tokens[$index + 1] ?? null) !== '(' || !is_array($tokens[$index + 2] ?? null) || $tokens[$index + 2][1] !== '$pipes') {
            continue;
        }
        $previous = $tokens[$index - 1] ?? null;
        $isMethod = is_array($previous) && $previous[0] === T_OBJECT_OPERATOR;
        if ($isMethod && $token[1] === 'close' && ($tokens[$index + 3] ?? null) === ')') {
            return true;
        }
        if (!$isMethod && strtolower($token[1]) === 'fclose' && ($tokens[$index + 3] ?? null) === '[' && ($tokens[$index + 4][1] ?? null) === '0' && ($tokens[$index + 5] ?? null) === ']') {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function childProcessFiles(string $directory): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && !str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()), '/Fixtures/')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

it('każdy proc_open w src, tests i scripts/mutation ma jawny stdin, a testy nie używają shell_exec ani exec', function (): void {
    $root = dirname(__DIR__, 2);
    $violations = [];
    foreach ([...childProcessFiles($root . '/src'), ...childProcessFiles($root . '/tests'), ...childProcessFiles($root . '/scripts/mutation')] as $path) {
        $isPolicy = str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $path), 'tests/Unit/ChildProcessPolicyTest.php');
        if ($isPolicy) {
            continue;
        }
        foreach (childProcessViolations((string) file_get_contents($path)) as $violation) {
            $violations[] = substr($path, strlen($root) + 1) . ': ' . $violation;
        }
    }

    expect($violations)->toBe([]);
});

it('kontrola ujemna: skaner wykrywa proc_open bez stdin i powłokę bez kontroli', function (string $source, int $count): void {
    expect(count(childProcessViolations($source)))->toBe($count);
})->with([
    'proc_open tylko ze stdout i stderr' => ["<?php\n\$p = proc_open(\$c, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], \$pipes);\n", 1],
    'stdin przez protokół file' => ["<?php\n\$p = proc_open(\$c, [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w']], \$pipes);\n", 1],
    'stdin z rurki zamknięty fclose' => ["<?php\n\$p = proc_open(\$c, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], \$pipes);\nfclose(\$pipes[0]);\n", 0],
    'stdin z rurki bez zamknięcia' => ["<?php\n\$p = proc_open(\$c, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], \$pipes);\nfclose(\$pipes[1]);\n", 1],
    'stdin z rurki zamknięty metodą close' => ["<?php\n\$p = proc_open(\$c, [0 => \$input->descriptor(), 1 => ['pipe', 'w']], \$pipes);\n\$input->close(\$pipes);\n", 0],
    'stdin z deskryptora bez zamknięcia' => ["<?php\n\$p = proc_open(\$c, [0 => \$input, 1 => ['pipe', 'w']], \$pipes);\n", 1],
    'inna metoda close nie liczy się' => ["<?php\n\$p = proc_open(\$c, [0 => \$input, 1 => ['pipe', 'w']], \$pipes);\n\$input->close(\$other);\n", 1],
    'fclose innej rurki nie liczy się' => ["<?php\n\$p = proc_open(\$c, [0 => ['pipe', 'r']], \$pipes);\nfclose(\$pipes[2]);\n", 1],
    'metoda fclose nie liczy się' => ["<?php\n\$p = proc_open(\$c, [0 => ['pipe', 'r']], \$pipes);\n\$x->fclose(\$pipes[0]);\n", 1],
    'shell_exec' => ["<?php\n\$x = shell_exec('git status');\n", 1],
    'exec' => ["<?php\nexec('git status', \$o);\n", 1],
    'komentarz nie liczy się' => ["<?php\n// proc_open(\$c, [1 => 1]) i shell_exec('x')\n", 0],
]);
