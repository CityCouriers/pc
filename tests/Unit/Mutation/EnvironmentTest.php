<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Environment;
use CityCouriers\Pc\Dev\Mutation\Inventory;
use CityCouriers\Pc\Dev\Mutation\Journal;
use CityCouriers\Pc\Dev\Mutation\PestRun;
use CityCouriers\Pc\Tests\Support\GitRepo;
use Pest\Mutate\MutationTest;

const ENVIRONMENT_UPSTREAM = 'vendor/pestphp/pest-plugin-mutate/src/MutationTest.php';

/**
 * Wersje rozszerzeń jak z phpversion(): nazwa → wersja, brak nazwy to rozszerzenie niezaładowane.
 *
 * @param array<string, string> $loaded
 *
 * @return Closure(string): (string|false)
 */
function environmentExtensions(array $loaded): Closure
{
    return static fn(string $name): string|false => $loaded[$name] ?? false;
}

it('środowisko: SHA, zmiany w drzewie, PHP, sterownik pokrycia, wersje pakietów i skróty konfiguracji', function (): void {
    $repo = new GitRepo();
    try {
        $sha = $repo->commit(['composer.json' => "{}\n", 'phpunit.xml.dist' => "<a/>\n"]);
        $repo->write('composer.json', "{\"a\": 1}\n");
        $repo->write('nowy/a.txt');
        $repo->write('nowy/b.txt');
        $repo->write('vendor/composer/installed.json', (string) json_encode(['packages' => [['name' => 'pestphp/pest', 'version' => 'v5.3.5']]]));

        expect(Environment::collect($repo->path, environmentExtensions(['pcov' => '9.9.9'])))->toBe([
            'sha' => $sha,
            // Sama liczba zmienionych plików: nazwy (np. prywatnych notatek) nie trafiają do manifestu w publicznym repo.
            // Każdy nieśledzony plik osobno, także w nieśledzonym katalogu (bez --untracked-files=all katalog to jedna linia).
            'zmiany_w_drzewie' => 4,
            // Od PHP 8.4 PHP_ZTS to bool: porównanie z 1 dawało zawsze NTS.
            'php' => PHP_VERSION . (ZEND_THREAD_SAFE ? ' ZTS' : ' NTS'),
            'system' => PHP_OS_FAMILY,
            'sterownik_pokrycia' => 'pcov 9.9.9',
            'wersje' => ['pestphp/pest' => 'v5.3.5', 'pestphp/pest-plugin-mutate' => 'nieznana', 'phpunit/phpunit' => 'nieznana', 'phpunit/php-code-coverage' => 'nieznana', 'symfony/process' => 'nieznana'],
            'skroty' => [
                'composer.json' => Inventory::hash("{\"a\": 1}\n"),
                'composer.lock' => null,
                'phpunit.xml.dist' => Inventory::hash("<a/>\n"),
                'scripts/mutation/patch/MutationTest.php' => null,
                'scripts/mutation/bez-mutantow.json' => null,
            ],
        ]);
    } finally {
        $repo->remove();
    }
});

/**
 * Odbiera albo przywraca prawo odczytu pliku (Windows: odmowa dla wszystkich w ACL, inne systemy: tryb 000).
 */
function environmentReadable(string $path, bool $readable): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        [$code, $output] = PestRun::capture(['icacls', $path, ...($readable ? ['/remove:d', '*S-1-1-0'] : ['/deny', '*S-1-1-0:(R)'])], dirname($path));
        if ($code !== 0) {
            throw new RuntimeException('icacls: ' . $output);
        }

        return;
    }
    chmod($path, $readable ? 0o644 : 0o000);
}

it('środowisko: plik konfiguracji, którego nie da się odczytać, nie ma skrótu (jak brak pliku), a pusty ma skrót pustej treści', function (): void {
    $repo = new GitRepo();
    $file = $repo->path . '/scripts/mutation/bez-mutantow.json';
    try {
        $repo->commit(['vendor/composer/installed.json' => '{"packages": []}', 'composer.lock' => '']);
        $repo->write('scripts/mutation/bez-mutantow.json', '{}');
        environmentReadable($file, false);
        // Ostrzeżenia z odczytu są wyciszone w kodzie; pod wrapperem plików wtyczki mogą dojść inne, więc tu tylko zbierane.
        set_error_handler(static fn(): bool => true);
        try {
            $hashes = Environment::collect($repo->path, environmentExtensions([]))['skroty'];
        } finally {
            restore_error_handler();
        }

        expect($hashes)->toBe([
            'composer.json' => null,
            'composer.lock' => Inventory::hash(''),
            'phpunit.xml.dist' => null,
            'scripts/mutation/patch/MutationTest.php' => null,
            'scripts/mutation/bez-mutantow.json' => null,
        ]);
    } finally {
        environmentReadable($file, true);
        $repo->remove();
    }
});

it('środowisko: liczba zmian w drzewie bez pustych linii i linii z samych spacji, także z komunikatami gita na stderr', function (): void {
    $repo = new GitRepo();
    try {
        $repo->commit(['vendor/composer/installed.json' => '{"packages": []}']);
        $repo->write('brud.txt');
        // Hook fsmonitor pisze na stderr (tylko za pierwszym wywołaniem; git woła go raz albo dwa) pustą linię, tekst
        // ze spacjami na końcu i same spacje. Liczą się: „?? brud.txt” i „uwaga”.
        file_put_contents($repo->path . '/.git/fsmonitor.sh', "#!/bin/sh\n[ -e .git/fsmonitor.done ] && exit 1\n: > .git/fsmonitor.done\nprintf '\\nuwaga  \\n  \\n' >&2\nexit 1\n");
        chmod($repo->path . '/.git/fsmonitor.sh', 0o755);
        $repo->git('config', 'core.fsmonitor', '.git/fsmonitor.sh');

        expect(Environment::collect($repo->path, environmentExtensions([]))['zmiany_w_drzewie'])->toBe(2)
            ->and(is_file($repo->path . '/.git/fsmonitor.done'))->toBeTrue();
    } finally {
        $repo->remove();
    }
});

it('środowisko: bez podanych wersji sterownik pokrycia pochodzi z bieżącego PHP, a czyste drzewo nie ma zmian', function (): void {
    $repo = new GitRepo();
    try {
        $repo->commit(['vendor/composer/installed.json' => '{"packages": []}']);
        $driver = match (true) {
            extension_loaded('pcov') => 'pcov ' . phpversion('pcov'),
            extension_loaded('xdebug') => 'xdebug ' . phpversion('xdebug'),
            default => 'brak',
        };
        $environment = Environment::collect($repo->path);

        expect($environment['sterownik_pokrycia'])->toBe($driver)
            ->and($environment['zmiany_w_drzewie'])->toBe(0);
    } finally {
        $repo->remove();
    }
});

it('sterownik pokrycia: pcov przed xdebug, wersja „0” to też wersja, bez obu „brak”', function (Closure $version, string $driver): void {
    expect(Environment::coverageDriver($version))->toBe($driver);
})->with([
    'pcov' => [environmentExtensions(['pcov' => '1.0.12', 'xdebug' => '3.4.0']), 'pcov 1.0.12'],
    'xdebug' => [environmentExtensions(['xdebug' => '3.4.0']), 'xdebug 3.4.0'],
    'pcov w wersji 0' => [environmentExtensions(['pcov' => '0']), 'pcov 0'],
    'xdebug w wersji 0' => [environmentExtensions(['xdebug' => '0']), 'xdebug 0'],
    'brak' => [environmentExtensions([]), 'brak'],
]);

it('ustawienia pokrycia: pcov ograniczony do katalogu z wyłączonym xdebug, inaczej xdebug, bez sterownika null', function (Closure $version, ?array $ini): void {
    expect(Environment::coverageIni('/repo/src', $version))->toBe($ini);
})->with([
    'pcov' => [environmentExtensions(['pcov' => '1.0.12', 'xdebug' => '3.4.0']), ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=/repo/src', '-d', 'xdebug.mode=off']],
    'pcov w wersji 0' => [environmentExtensions(['pcov' => '0']), ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=/repo/src', '-d', 'xdebug.mode=off']],
    'xdebug' => [environmentExtensions(['xdebug' => '3.4.0']), ['-d', 'xdebug.mode=coverage']],
    'xdebug w wersji 0' => [environmentExtensions(['xdebug' => '0']), ['-d', 'xdebug.mode=coverage']],
    'brak' => [environmentExtensions([]), null],
]);

it('ustawienia pokrycia bez podanych wersji pochodzą z bieżącego PHP', function (): void {
    $ini = match (true) {
        extension_loaded('pcov') => ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=/repo/src', '-d', 'xdebug.mode=off'],
        extension_loaded('xdebug') => ['-d', 'xdebug.mode=coverage'],
        default => null,
    };

    expect(Environment::coverageIni('/repo/src'))->toBe($ini);
});

it('wersje pakietów z vendor/composer/installed.json: tylko znane pakiety z wersją tekstową, reszta „nieznana”', function (): void {
    $repo = new GitRepo();
    try {
        $unknown = array_fill_keys(['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage', 'symfony/process'], 'nieznana');
        $installed = static function (mixed $content) use ($repo): array {
            $repo->write('vendor/composer/installed.json', (string) json_encode($content));

            return Environment::versions($repo->path);
        };

        expect($installed(['packages' => [
            ['name' => 'phpunit/phpunit', 'version' => '13.4.5'],
            ['name' => 'symfony/process', 'version' => 7],
            ['name' => 'inny/pakiet', 'version' => '1.0'],
            ['name' => 5, 'version' => '1.0'],
            ['version' => '2.0'],
            'pestphp/pest',
            ['name' => 'pestphp/pest-plugin-mutate', 'version' => 'dev-citycouriers'],
        ]]))->toBe(array_replace($unknown, ['pestphp/pest-plugin-mutate' => 'dev-citycouriers', 'phpunit/phpunit' => '13.4.5']))
            ->and($installed('tekst'))->toBe($unknown)
            ->and($installed(['packages' => 5]))->toBe($unknown)
            ->and($installed(['inne' => []]))->toBe($unknown);
    } finally {
        $repo->remove();
    }
});

it('warunki ważności: SHA, czyste drzewo, aktywna łatka i oryginał wtyczki zgodny ze skrótem z łatki', function (): void {
    $repo = new GitRepo();
    try {
        $root = $repo->path;
        $repo->write(ENVIRONMENT_UPSTREAM, "oryginał\n");
        $patch = [$root . '/scripts/mutation/patch/MutationTest.php', hash('sha256', "oryginał\n")];
        $clean = ['sha' => 'abc', 'zmiany_w_drzewie' => 0];
        $dirty = 'drzewo robocze ma niezatwierdzone zmiany: wynik nie jest dowodem dla SHA bazowego';
        $drift = 'dryf wtyczki: oryginał ' . ENVIRONMENT_UPSTREAM . ' różni się od wersji, z której zrobiono łatkę (przenieś zmiany i zaktualizuj UPSTREAM_SHA256)';

        expect(Environment::problems($root, $clean, $patch))->toBe([])
            ->and(Environment::problems($root, [], $patch))->toBe(['nie udało się ustalić SHA bazowego (git rev-parse HEAD)'])
            ->and(Environment::problems($root, ['sha' => '', 'zmiany_w_drzewie' => 1], $patch))->toBe(['nie udało się ustalić SHA bazowego (git rev-parse HEAD)', $dirty])
            // Wartości spoza collect(): tylko pusty tekst to brak SHA i tylko zero to czyste drzewo.
            ->and(Environment::problems($root, ['sha' => false, 'zmiany_w_drzewie' => false], $patch))->toBe([$dirty])
            ->and(Environment::problems($root, $clean, [str_replace('/', '\\', $root . '/' . ENVIRONMENT_UPSTREAM), $patch[1]]))
            ->toBe(['łatka wtyczki nieaktywna: Pest\\Mutate\\MutationTest ładuje się z ' . ENVIRONMENT_UPSTREAM . ' (composer dump-autoload)'])
            ->and(Environment::problems($root, $clean, [$patch[0], hash('sha256', "inny\n")]))->toBe([$drift])
            ->and(Environment::problems($root . '/brak', $clean, [$root . '/brak/scripts/mutation/patch/MutationTest.php', $patch[1]]))->toBe([$drift]);
    } finally {
        $repo->remove();
    }
});

it('warunki ważności: skrót oryginału wtyczki porównany ściśle (skrót „0e…” to nie liczba 0)', function (): void {
    $repo = new GitRepo();
    try {
        $repo->write(ENVIRONMENT_UPSTREAM, '34250003024812');
        $patch = [$repo->path . '/scripts/mutation/patch/MutationTest.php', '0'];

        expect(hash_file('sha256', $repo->path . '/' . ENVIRONMENT_UPSTREAM))->toStartWith('0e')
            ->and(Environment::problems($repo->path, ['sha' => 'abc', 'zmiany_w_drzewie' => 0], $patch))
            ->toBe(['dryf wtyczki: oryginał ' . ENVIRONMENT_UPSTREAM . ' różni się od wersji, z której zrobiono łatkę (przenieś zmiany i zaktualizuj UPSTREAM_SHA256)']);
    } finally {
        $repo->remove();
    }
});

it('warunki ważności: stan łatki domyślnie z bieżącego procesu (bez łatki brak stałej to dryf, a nie błąd krytyczny)', function (): void {
    $root = dirname(__DIR__, 3);
    $class = new ReflectionClass(MutationTest::class);
    $loaded = Journal::relative((string) $class->getFileName(), $root);
    $expected = [];
    if ($loaded !== 'scripts/mutation/patch/MutationTest.php') {
        $expected[] = 'łatka wtyczki nieaktywna: Pest\\Mutate\\MutationTest ładuje się z ' . $loaded . ' (composer dump-autoload)';
    }
    if (hash_file('sha256', $root . '/' . ENVIRONMENT_UPSTREAM) !== $class->getConstant('UPSTREAM_SHA256')) {
        $expected[] = 'dryf wtyczki: oryginał ' . ENVIRONMENT_UPSTREAM . ' różni się od wersji, z której zrobiono łatkę (przenieś zmiany i zaktualizuj UPSTREAM_SHA256)';
    }

    expect(Environment::problems($root, ['sha' => 'abc', 'zmiany_w_drzewie' => 0]))->toBe($expected);
});

it('wersje pakietów bez vendor/composer/installed.json: wszystkie „nieznana”, ostrzeżenie wyciszone', function (): void {
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        $versions = Environment::versions(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-bez-vendor-' . bin2hex(random_bytes(4)));
    } finally {
        restore_error_handler();
    }

    expect($versions)->toBe(array_fill_keys(['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage', 'symfony/process'], 'nieznana'))
        // Pod wrapperem plików wtyczki (proces mutanta) ostrzeżeń jest więcej, więc liczy się treść, nie liczba.
        ->and(implode("\n", $warnings))->toContain('installed.json');
});
