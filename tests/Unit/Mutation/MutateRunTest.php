<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Mutate;
use CityCouriers\Pc\Dev\Mutation\Redactor;
use CityCouriers\Pc\Tests\Support\GitRepo;

// Cały bieg `composer mutate` w procesie testu (pokrycie liczy się tylko w nim): tymczasowe repo z udawanym
// vendor/bin/pest, który zamiast Pesta wypisuje log i dziennik ze scenariusza oraz zapisuje, jak go wywołano.

const MUTATE_FAKE_PEST = <<<'PHP'
    <?php
    // Udawany Pest: rodzaj biegu po argumentach (kontrola tożsamościowa nie ma żadnych), wynik ze scenario.json.
    $root = dirname(__DIR__, 2);
    $kind = count($argv) === 1 ? 'identity' : (in_array('--configuration=scripts/mutation/control/phpunit.xml', $argv, true) ? 'control' : 'main');
    $copy = (string) getenv('PEST_MUTATION_FILE');
    $call = [
        'kind' => $kind,
        'argv' => array_slice($argv, 1),
        'cwd' => getcwd(),
        // Tylko zmienne i ustawienia, które skrypt ustawia temu rodzajowi biegu (resztę dziedziczy z otoczenia).
        'env' => array_map(getenv(...), array_combine($names = $kind === 'identity' ? ['PEST_MUTATION_TESTING', 'PEST_MUTATION_FILE', 'COLUMNS'] : ['PC_MUTATION_JOURNAL', 'COLUMNS'], $names)),
        'ini' => array_map(get_cfg_var(...), array_combine($names = $kind === 'identity' ? ['xdebug.mode'] : ['memory_limit', 'pcov.directory', 'xdebug.mode'], $names)),
        'copy' => $kind === 'identity' && is_file($copy) ? file_get_contents($copy) : null,
    ];
    file_put_contents($root . '/calls.jsonl', json_encode($call, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    $run = json_decode((string) file_get_contents($root . '/scenario.json'), true)[$kind];
    if ($run['journal'] !== []) {
        file_put_contents((string) getenv('PC_MUTATION_JOURNAL'), implode("\n", $run['journal']) . "\n", FILE_APPEND);
    }
    echo $run['stdout'];
    fwrite(STDERR, $run['stderr']);
    // Pliki usunięte w trakcie biegu (np. log, który skrypt ma potem przeczytać).
    foreach ($run['delete'] ?? [] as $file) {
        unlink($file);
    }
    exit($run['exit']);
    PHP;

const MUTATE_UPSTREAM = "<?php // oryginał wtyczki\n";

/**
 * Zbiera wszystko, co trafia na STDOUT (komunikaty skryptu i echo procesów), zamiast wypisywać.
 */
final class MutateOutputFilter extends php_user_filter
{
    public static string $text = '';

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while (($bucket = stream_bucket_make_writeable($in)) !== null) {
            self::$text .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}

/**
 * @param list<string> $arguments
 *
 * @return array{int, string} kod wyjścia i wszystko wypisane na STDOUT
 */
function mutateMain(Mutate $mutate, array $arguments): array
{
    if (!in_array('mutate-output', stream_get_filters(), true)) {
        stream_filter_register('mutate-output', MutateOutputFilter::class);
    }
    MutateOutputFilter::$text = '';
    $filter = stream_filter_append(STDOUT, 'mutate-output', STREAM_FILTER_WRITE);
    try {
        $code = $mutate->main($arguments);
    } finally {
        if (is_resource($filter)) {
            stream_filter_remove($filter);
        }
    }

    return [$code, MutateOutputFilter::$text];
}

function mutateRepo(): GitRepo
{
    $repo = new GitRepo();
    $repo->commit([
        '.gitignore' => "build/\nscenario.json\ncalls.jsonl\n",
        'src/A.php' => "<?php\n\nfinal class A\n{\n}\n",
        'src/B.php' => "<?php\n\nfinal class B\n{\n}\n",
        'src/Sub/C.php' => "<?php\n\nfinal class C\n{\n}\n",
        'vendor/bin/pest' => MUTATE_FAKE_PEST,
        'vendor/composer/installed.json' => '{"packages": []}',
        'vendor/pestphp/pest-plugin-mutate/src/MutationTest.php' => MUTATE_UPSTREAM,
        'scripts/mutation/bez-mutantow.json' => '{"version": 1, "pliki": {}}',
    ]);

    return $repo;
}

/**
 * Mutate z udawanym pcov i łatką: zgodną (domyślnie) albo ze skrótem innego oryginału.
 */
function mutateScript(GitRepo $repo, bool $echo = true, string $upstream = MUTATE_UPSTREAM): Mutate
{
    return new Mutate(
        $repo->path,
        $echo,
        static fn(string $name): string|false => $name === 'pcov' ? '9.9.9' : false,
        [$repo->path . '/scripts/mutation/patch/MutationTest.php', hash('sha256', $upstream)],
    );
}

function mutateGenerated(string $id, string $file): string
{
    return (string) json_encode(['event' => 'generated', 'id' => $id, 'file' => $file, 'mutator' => 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 'start_line' => 3, 'end_line' => 3], JSON_UNESCAPED_SLASHES);
}

function mutateResult(string $id, string $status, ?int $exitCode, string $summary): string
{
    return (string) json_encode(['event' => 'result', 'id' => $id, 'status' => $status, 'exit_code' => $exitCode, 'patterns' => 2, 'filter' => 'testy', 'duration' => 0.2, 'summary' => $summary]);
}

/**
 * Kontrola zerowa i dodatnia, która przechodzi: mutant klasy Przezycie żyje, mutant klasy Zabicie ginie od testu.
 *
 * @return array{journal: list<string>, stdout: string, stderr: string, exit: int}
 */
function mutateControl(string $root, string $survivorStatus = 'untested', int $survivorExitCode = 0): array
{
    return [
        'journal' => [
            mutateGenerated('c1', $root . '/scripts/mutation/control/src/Przezycie.php'),
            mutateGenerated('c2', $root . '/scripts/mutation/control/src/Zabicie.php'),
            mutateResult('c1', $survivorStatus, $survivorExitCode, '2 passed (2 assertions)'),
            mutateResult('c2', 'tested', 1, '1 failed, 1 passed (2 assertions)'),
        ],
        'stdout' => "  2 Mutations for 2 Files created\n  at {$root}\\scripts\\mutation\\control\\src\\Przezycie.php\n  Mutations: 1 untested, 1 tested\n",
        'stderr' => '',
        'exit' => 0,
    ];
}

/**
 * @param array<string, array{journal: list<string>, stdout: string, stderr: string, exit: int, delete?: list<string>}> $scenario
 */
function mutateScenario(GitRepo $repo, array $scenario): void
{
    file_put_contents($repo->path . '/scenario.json', json_encode($scenario, JSON_UNESCAPED_SLASHES));
}

/**
 * @return list<array<mixed>>
 */
function mutateCalls(GitRepo $repo): array
{
    $calls = [];
    foreach (file($repo->path . '/calls.jsonl', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $calls[] = (array) json_decode($line, true);
    }

    return $calls;
}

/**
 * @return array<mixed>
 */
function mutateManifest(string $out): array
{
    return (array) json_decode((string) file_get_contents($out . '/manifest.json'), true);
}

function mutatePhp(): string
{
    return (new Redactor('/nie-ma', PHP_BINARY, null))->redact(PHP_BINARY);
}

/**
 * Nieistniejący katalog w katalogu tymczasowym (np. katalog domowy, którego nikt nie czyta).
 */
function mutateMissingDirectory(string $name): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . bin2hex(random_bytes(4));
}

/**
 * Uruchamia z podmienionymi zmiennymi środowiska (null: zmienna usunięta) i przywraca poprzednie.
 *
 * @template T
 *
 * @param array<string, string|null> $variables
 * @param Closure(): T $run
 *
 * @return T
 */
function mutateWithEnvironment(array $variables, Closure $run): mixed
{
    $previous = [];
    foreach ($variables as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($value === null ? $name : $name . '=' . $value);
    }
    try {
        return $run();
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }
}

/**
 * Wynik razem z ostrzeżeniami PHP zebranymi zamiast zgłoszenia (celowo brakujący plik albo katalog).
 *
 * @template T
 *
 * @param Closure(): T $run
 *
 * @return array{T, list<string>}
 */
function mutateWarnings(Closure $run): array
{
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        return [$run(), $warnings];
    } finally {
        restore_error_handler();
    }
}

it('bieg przyjęty: kontrole, bieg mutacji, manifest z poleceniem i środowiskiem, logi bez ścieżek z maszyny, kod 0', function (): void {
    $repo = mutateRepo();
    try {
        $root = $repo->path;
        $out = $root . '/build/m/a';
        // Katalog domowy z USERPROFILE ma pierwszeństwo przed HOME (Windows ma oba).
        $profile = mutateMissingDirectory('pc-profil-');
        $home = mutateMissingDirectory('pc-dom-');
        mutateScenario($repo, [
            'control' => mutateControl($root),
            'identity' => ['journal' => [], 'stdout' => "  Tests:    5 passed (9 assertions)\n  {$root}\\src\\A.php\n", 'stderr' => '', 'exit' => 0],
            'main' => [
                'journal' => [
                    mutateGenerated('a1', $root . '\\src\\A.php'),
                    mutateGenerated('b2', $root . '/src/B.php'),
                    mutateGenerated('f6', $root . '/src/Sub/C.php'),
                    mutateResult('a1', 'tested', 1, '1 failed, 4 passed (9 assertions)'),
                    mutateResult('b2', 'tested', 1, '1 failed, 4 passed (9 assertions)'),
                    mutateResult('f6', 'tested', 1, '2 failed, 3 passed (9 assertions)'),
                ],
                'stdout' => "  Tests:    5 passed (9 assertions)\n  3 Mutations for 3 Files created\n  Mutations: 3 tested\n",
                'stderr' => "  ostrzeżenie z {$root}/src/B.php w {$profile}\\AppData, nie w {$home}\n",
                'exit' => 0,
            ],
        ]);
        // Stare dzienniki z poprzedniego biegu: skrypt zaczyna każdy dziennik od zera.
        mkdir($out, 0o777, true);
        file_put_contents($out . '/journal.jsonl', "stary wpis\n");
        file_put_contents($out . '/control.jsonl', "stary wpis\n");
        $head = $repo->git('rev-parse', 'HEAD');

        [$code, $output] = mutateWithEnvironment(['USERPROFILE' => $profile, 'HOME' => $home], static fn(): array => mutateMain(mutateScript($repo), ['--path=src', '--out=build/m/a']));
        $manifest = mutateManifest($out);
        $calls = mutateCalls($repo);
        $php = mutatePhp();

        expect($code)->toBe(0)
            ->and($manifest['werdykt'])->toBe('przyjęty')
            ->and($manifest['powody'])->toBe([])
            ->and($manifest['uwagi'])->toBe([])
            ->and($manifest['zakres'])->toBe('src')
            ->and($manifest['polecenie'])->toBe($php . ' -d memory_limit=2G -d pcov.enabled=1 -d pcov.directory=./src -d xdebug.mode=off ./vendor/bin/pest --mutate --everything --path=src --mutator=SetDefault,SetLaravel,SetVisibility,EqualityIdenticalToEqual,EqualityNotIdenticalToNotEqual --no-cache --parallel --processes=8')
            ->and($manifest['srodowisko'])->toMatchArray(['sha' => $head, 'zmiany_w_drzewie' => 0, 'sterownik_pokrycia' => 'pcov 9.9.9'])
            ->and($manifest['liczniki'])->toBe(['tested' => 3])
            ->and(array_column((array) $manifest['cele'], 'plik'))->toBe(['src/A.php', 'src/B.php', 'src/Sub/C.php'])
            ->and(array_column((array) $manifest['mutanty'], 'plik'))->toBe(['src/A.php', 'src/B.php', 'src/Sub/C.php'])
            ->and((string) file_get_contents($out . '/manifest.json'))->toBe(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        foreach (['run.log', 'journal.jsonl', 'control.log', 'control.jsonl', 'identity.log', 'manifest.json'] as $file) {
            $content = (string) file_get_contents($out . '/' . $file);
            foreach ([$root, str_replace('\\', '/', $root), str_replace(['\\', '/'], '\\\\', $root)] as $variant) {
                expect(stripos($content, $variant))->toBeFalse();
            }
        }
        expect((string) file_get_contents($out . '/run.log'))->toBe("  Tests:    5 passed (9 assertions)\n  3 Mutations for 3 Files created\n  Mutations: 3 tested\n  ostrzeżenie z ./src/B.php w ~\\AppData, nie w {$home}\n")
            ->and((string) file_get_contents($out . '/control.log'))->toContain("  at .\\scripts\\mutation\\control\\src\\Przezycie.php\n")
            ->and((string) file_get_contents($out . '/journal.jsonl'))->toContain('"file":".\\\\src\\\\A.php"')->toContain('"file":"./src/B.php"')
            ->and(str_contains((string) file_get_contents($out . '/journal.jsonl'), 'stary'))->toBeFalse()
            ->and((string) file_get_contents($out . '/control.jsonl'))->toContain('"file":"./scripts/mutation/control/src/Przezycie.php"')
            ->and(str_contains((string) file_get_contents($out . '/control.jsonl'), 'stary'))->toBeFalse()
            ->and((string) file_get_contents($out . '/identity.log'))->toBe("  Tests:    5 passed (9 assertions)\n  .\\src\\A.php\n")
            ->and(file_exists($out . '/identity-source.php'))->toBeFalse();

        // Kontrola tożsamościowa po biegu: porównuje liczbę testów z zestawem początkowym biegu mutacji.
        expect(array_column($calls, 'kind'))->toBe(['control', 'main', 'identity'])
            ->and($calls[0]['argv'])->toBe(['--mutate', '--everything', '--configuration=scripts/mutation/control/phpunit.xml', '--path=scripts/mutation/control/src', '--mutator=ArithmeticPlusToMinus', '--no-cache', '--parallel', '--processes=8'])
            ->and($calls[0]['env'])->toBe(['PC_MUTATION_JOURNAL' => $out . '/control.jsonl', 'COLUMNS' => '300'])
            // Parser INI zamienia „off” na pusty tekst.
            ->and($calls[0]['ini'])->toBe(['memory_limit' => '2G', 'pcov.directory' => $root . '/scripts/mutation/control', 'xdebug.mode' => ''])
            ->and($calls[2]['argv'])->toBe([])
            ->and($calls[2]['env'])->toBe(['PEST_MUTATION_TESTING' => realpath($root . '/src/A.php'), 'PEST_MUTATION_FILE' => realpath($out) . DIRECTORY_SEPARATOR . 'identity-source.php', 'COLUMNS' => '300'])
            ->and($calls[2]['ini'])->toBe(['xdebug.mode' => ''])
            ->and($calls[2]['copy'])->toBe("<?php\n\nfinal class A\n{\n}\n")
            ->and($calls[1]['env'])->toBe(['PC_MUTATION_JOURNAL' => $out . '/journal.jsonl', 'COLUMNS' => '300'])
            ->and($calls[1]['ini'])->toBe(['memory_limit' => '2G', 'pcov.directory' => $root . '/src', 'xdebug.mode' => ''])
            ->and(array_column($calls, 'cwd'))->toBe(array_fill(0, 3, realpath($root)));

        expect($output)->toContain("\n== Kontrola zerowa i dodatnia (scripts/mutation/control)\n\n")
            ->toContain("  2 Mutations for 2 Files created\n  at {$root}\\scripts")
            ->toContain("\n== Bieg mutacji: src\n\n  Tests:    5 passed (9 assertions)\n  3 Mutations for 3 Files created\n")
            ->toContain("\n== Kontrola tożsamościowa (pełny zestaw testów pod wrapperem plików wtyczki, bez mutacji)\n\n  Tests:    5 passed (9 assertions)\n")
            ->toEndWith("\n\nBIEG PRZYJĘTY\nMutanty: 3 tested\nManifest: ./build/m/a/manifest.json\n")
            ->and(strpos($output, '== Bieg mutacji: src') < strpos($output, '== Kontrola tożsamościowa'))->toBeTrue();
    } finally {
        $repo->remove();
    }
});

it('bieg odrzucony: kontrola zerowa nie przechodzi, brak celów i listy „bez mutantów”, bieg mutacji pominięty, stare logi wyczyszczone, nic na ekranie', function (): void {
    $repo = mutateRepo();
    try {
        $root = $repo->path;
        $out = $root . '/build/mutation';
        mkdir($root . '/pusty');
        unlink($root . '/scripts/mutation/bez-mutantow.json');
        mutateScenario($repo, ['control' => mutateControl($root, 'tested', 1)]);
        mkdir($out, 0o777, true);
        file_put_contents($out . '/run.log', "  9 Mutations for 9 Files created\n  Mutations: 9 tested\n");
        file_put_contents($out . '/journal.jsonl', mutateGenerated('d4', $root . '/pusty/D.php') . "\n");

        [[$code, $output], $warnings] = mutateWarnings(static fn(): array => mutateMain(mutateScript($repo, false), ['--path=pusty']));
        $manifest = mutateManifest($out);

        expect($code)->toBe(1)
            ->and($output)->toBe('')
            // Pod wrapperem plików wtyczki (proces mutanta) ostrzeżeń jest więcej, więc liczy się treść, nie liczba.
            ->and(implode("\n", $warnings))->toContain('bez-mutantow.json')
            ->and($manifest['werdykt'])->toBe('odrzucony')
            ->and($manifest['powody'])->toBe([
                'drzewo robocze ma niezatwierdzone zmiany: wynik nie jest dowodem dla SHA bazowego',
                'kontrola zerowa: mutant c1, który musi przeżyć (testy bez asercji), ma status tested (kod 1, testy: 2 passed (2 assertions)); wynik biegu byłby fałszywy',
                'bieg mutacji pominięty: kontrola zerowa lub dodatnia nie przeszła',
                'kontrola tożsamościowa: brak celów do podstawienia pod wrapper',
                'proces Pesta zakończył się kodem nieznanym: przerwany (oczekiwany 0)',
                'pusty wynik: log nie zawiera linii „N Mutations for M Files created”',
                'bieg przerwany lub nieukończony: log nie zawiera podsumowania „Mutations: ...”',
                'bez-mutantow.json: oczekiwany obiekt z "version": 1 i obiektem "pliki"',
                'pusty wynik: dziennik nie zawiera żadnego wygenerowanego mutanta',
            ])
            ->and(array_column(mutateCalls($repo), 'kind'))->toBe(['control'])
            ->and((string) file_get_contents($out . '/run.log'))->toBe('')
            ->and((string) file_get_contents($out . '/journal.jsonl'))->toBe('');
    } finally {
        $repo->remove();
    }
});

it('bieg odrzucony: zmiany w drzewie, dryf wtyczki, nieudana kontrola tożsamościowa i żywy mutant w kawałku src', function (): void {
    $repo = mutateRepo();
    try {
        $root = $repo->path;
        $home = mutateMissingDirectory('pc-dom-');
        mutateScenario($repo, [
            'control' => mutateControl($root),
            'identity' => ['journal' => [], 'stdout' => "  Tests:    1 failed, 4 passed (9 assertions)\n  {$home}\\plik\n", 'stderr' => '', 'exit' => 1],
            'main' => [
                'journal' => [mutateGenerated('e5', $root . '/src/Sub/C.php'), mutateResult('e5', 'untested', 0, '5 passed (9 assertions)')],
                'stdout' => "  1 Mutation for 1 File created\n   UNTESTED  ./src/Sub/C.php  > Line 3: PlusToMinus - ID: e5\n  Mutations: 1 untested\n",
                'stderr' => '',
                'exit' => 0,
            ],
        ]);
        $repo->write('brud.txt');

        // Bez USERPROFILE katalogiem domowym jest HOME.
        [$code] = mutateWithEnvironment(['USERPROFILE' => null, 'HOME' => $home], static fn(): array => mutateMain(mutateScript($repo, false, 'inny oryginał'), ['--path=src/Sub', '--serial']));
        $manifest = mutateManifest($root . '/build/mutation');
        $calls = mutateCalls($repo);

        expect($code)->toBe(1)
            ->and((string) file_get_contents($root . '/build/mutation/identity.log'))->toBe("  Tests:    1 failed, 4 passed (9 assertions)\n  ~\\plik\n")
            ->and($manifest['powody'])->toBe([
                'drzewo robocze ma niezatwierdzone zmiany: wynik nie jest dowodem dla SHA bazowego',
                'dryf wtyczki: oryginał vendor/pestphp/pest-plugin-mutate/src/MutationTest.php różni się od wersji, z której zrobiono łatkę (przenieś zmiany i zaktualizuj UPSTREAM_SHA256)',
                'kontrola tożsamościowa: pełny zestaw testów pod wrapperem plików wtyczki, bez mutacji, kończy się kodem 1 (1 failed, 4 passed (9 assertions)), a wymagany jest kod 0 i same zaliczone testy; zabicia mutantów pokrytych tymi testami są niewiarygodne (#73)',
                'UNTESTED poza rejestrem równoważnych: 1 mutantów (e5)',
            ])
            ->and($manifest['polecenie'])->toBe(mutatePhp() . ' -d memory_limit=2G -d pcov.enabled=1 -d pcov.directory=./src -d xdebug.mode=off ./vendor/bin/pest --mutate --everything --path=src/Sub --mutator=SetDefault,SetLaravel,SetVisibility,EqualityIdenticalToEqual,EqualityNotIdenticalToNotEqual --no-cache')
            ->and($calls[0]['argv'])->toBe(['--mutate', '--everything', '--configuration=scripts/mutation/control/phpunit.xml', '--path=scripts/mutation/control/src', '--mutator=ArithmeticPlusToMinus', '--no-cache'])
            ->and($calls[2]['env'])->toBe(['PEST_MUTATION_TESTING' => realpath($root . '/src/Sub/C.php'), 'PEST_MUTATION_FILE' => realpath($root . '/build/mutation') . DIRECTORY_SEPARATOR . 'identity-source.php', 'COLUMNS' => '300']);
    } finally {
        $repo->remove();
    }
});

it('manifest bez danych z maszyny: zmiany w drzewie tylko liczbą, bez nazw plików, ścieżka w powodzie zredagowana', function (): void {
    $repo = mutateRepo();
    try {
        $root = $repo->path;
        $out = $root . '/build/mutation';
        $home = mutateMissingDirectory('pc-dom-');
        mutateScenario($repo, [
            'control' => mutateControl($root),
            'identity' => ['journal' => [], 'stdout' => "  Tests:    5 passed (9 assertions)\n", 'stderr' => '', 'exit' => 0],
            'main' => [
                'journal' => [mutateGenerated('a1', $root . '/src/A.php'), mutateResult('a1', 'tested', 1, '1 failed, 4 passed (9 assertions)')],
                'stdout' => "  Tests:    5 passed (9 assertions)\n  1 Mutation for 1 File created\n  Mutations: 1 tested\n",
                'stderr' => '',
                'exit' => 0,
            ],
        ]);
        // Syntetyczne brudne drzewo: nazwa pliku nie może trafić do manifestu publikowanego razem z PR.
        $repo->write('tajny-projekt-x.txt');
        // Klasa wtyczki załadowana z katalogu domowego: powód podaje jej ścieżkę, a redakcja zamienia ją na ~.
        $mutate = new Mutate($root, false, static fn(string $name): string|false => $name === 'pcov' ? '9.9.9' : false, [$home . DIRECTORY_SEPARATOR . 'MutationTest.php', hash('sha256', MUTATE_UPSTREAM)]);

        [$code] = mutateWithEnvironment(['USERPROFILE' => $home, 'HOME' => $home], static fn(): array => mutateMain($mutate, []));
        $content = (string) file_get_contents($out . '/manifest.json');
        $manifest = mutateManifest($out);

        expect($code)->toBe(1)
            ->and($manifest['srodowisko'])->toMatchArray(['zmiany_w_drzewie' => 1])
            ->and($manifest['powody'])->toContain('łatka wtyczki nieaktywna: Pest\\Mutate\\MutationTest ładuje się z ~/MutationTest.php (composer dump-autoload)')
            ->and(str_contains($content, 'tajny-projekt-x'))->toBeFalse();
        foreach ([$home, $root] as $path) {
            foreach ([$path, str_replace('\\', '/', $path), str_replace(['\\', '/'], '\\\\', $path)] as $variant) {
                expect(stripos($content, $variant))->toBeFalse();
            }
        }
    } finally {
        $repo->remove();
    }
});

it('pliki kontroli usunięte w trakcie biegu: puste wejście zamiast awarii, kontrola odrzucona z powodem', function (): void {
    $repo = mutateRepo();
    try {
        $root = $repo->path;
        $out = $root . '/build/mutation';
        mutateScenario($repo, [
            'control' => [...mutateControl($root), 'delete' => [$out . '/control.jsonl', $out . '/control.log']],
            'identity' => ['journal' => [], 'stdout' => "  Tests:    5 passed (9 assertions)\n", 'stderr' => '', 'exit' => 0],
        ]);

        [[$code], $warnings] = mutateWarnings(static fn(): array => mutateMain(mutateScript($repo, false), []));

        expect($code)->toBe(1)
            ->and(mutateManifest($out)['powody'])->toContain('kontrola zerowa: bieg kontrolny nie dokończył się (kod 0)')
            ->and(implode("\n", $warnings))->toContain('control.jsonl')->toContain('control.log');
    } finally {
        $repo->remove();
    }
});

it('log kontroli tożsamościowej, dziennik i log biegu usunięte w trakcie: puste wejście zamiast awarii, bieg odrzucony', function (): void {
    $repo = mutateRepo();
    try {
        $root = $repo->path;
        $out = $root . '/build/mutation';
        mutateScenario($repo, [
            'control' => mutateControl($root),
            'identity' => ['journal' => [], 'stdout' => "  Tests:    5 passed (9 assertions)\n", 'stderr' => '', 'exit' => 0, 'delete' => [$out . '/identity.log']],
            'main' => [
                'journal' => [mutateGenerated('a1', $root . '/src/A.php'), mutateResult('a1', 'tested', 1, '1 failed, 4 passed (9 assertions)')],
                'stdout' => "  1 Mutation for 1 File created\n  Mutations: 1 tested\n",
                'stderr' => '',
                'exit' => 0,
                'delete' => [$out . '/journal.jsonl', $out . '/run.log'],
            ],
        ]);

        [[$code], $warnings] = mutateWarnings(static fn(): array => mutateMain(mutateScript($repo, false), []));

        expect($code)->toBe(1)
            ->and(mutateManifest($out)['powody'])->toContain('pusty wynik: dziennik nie zawiera żadnego wygenerowanego mutanta')
            ->and(implode("\n", $warnings))->toContain('identity.log')->toContain('journal.jsonl')->toContain('run.log');
    } finally {
        $repo->remove();
    }
});

it('main: bez sterownika pokrycia i bez możliwości utworzenia katalogu wyjścia kod 2 z komunikatem, bez uruchamiania Pesta', function (): void {
    $repo = mutateRepo();
    try {
        $root = $repo->path;
        $noDriver = new Mutate($root, true, static fn(string $name): false => false);
        // pcov znika między ustawieniami biegu głównego i kontrolnego: brak sterownika dla któregokolwiek to kod 2.
        $asked = 0;
        $vanishing = new Mutate($root, true, static function (string $name) use (&$asked): string|false {
            return $name === 'pcov' && $asked++ === 0 ? '9.9.9' : false;
        });
        file_put_contents($root . '/plik', '');
        [$blocked, $warnings] = mutateWarnings(static fn(): array => mutateMain(mutateScript($repo), ['--out=plik']));

        expect(mutateMain($noDriver, []))->toBe([2, "Brak sterownika pokrycia (pcov albo xdebug): Pest Mutate nie ustali, które testy pokrywają mutanta.\n"])
            ->and(mutateMain($vanishing, []))->toBe([2, "Brak sterownika pokrycia (pcov albo xdebug): Pest Mutate nie ustali, które testy pokrywają mutanta.\n"])
            ->and($blocked)->toBe([2, "Nie można utworzyć katalogu plik\n"])
            // Pod wrapperem plików wtyczki (proces mutanta) ostrzeżeń jest więcej, więc liczy się treść, nie liczba.
            ->and(implode("\n", $warnings))->toContain('mkdir')
            ->and(file_exists($root . '/calls.jsonl'))->toBeFalse();
    } finally {
        $repo->remove();
    }
});

it('main: pomoc i błąd użycia wypisują instrukcję (błąd z powodem), domyślnie na ekran', function (): void {
    $usage = <<<'TXT'
        Użycie: composer mutate -- [--path=src] [--serial | --processes=N] [--out=build/mutation]

          --path=ŚCIEŻKA  cel biegu: katalog albo plik (domyślnie src, bieg autorytatywny); kawałek to tryb roboczy
          --serial        bez --parallel (domyślnie równolegle, jak bieg autorytatywny z #60)
          --processes=N   liczba równoległych procesów mutantów (domyślnie 8)
          --out=KATALOG   katalog na manifest, logi i dzienniki (domyślnie build/mutation)

        Kody wyjścia: 0 bieg przyjęty, 1 bieg odrzucony, 2 błąd użycia lub środowiska.
        TXT;
    // Repo z udawanym Pestem: mutant, który mimo pomocy albo błędu użycia ruszy bieg, kończy się od razu.
    $repo = mutateRepo();
    try {
        $version = static fn(string $name): string|false => $name === 'pcov' ? '9.9.9' : false;
        $mutate = new Mutate($repo->path, version: $version);

        expect(mutateMain($mutate, ['--help']))->toBe([0, $usage . "\n"])
            ->and(mutateMain($mutate, ['--nie-ma']))->toBe([2, "Nieznany argument: --nie-ma\n\n" . $usage . "\n"])
            ->and(mutateMain(new Mutate($repo->path, false, $version), ['--nie-ma']))->toBe([2, ''])
            ->and(file_exists($repo->path . '/calls.jsonl'))->toBeFalse();
    } finally {
        $repo->remove();
    }
});
