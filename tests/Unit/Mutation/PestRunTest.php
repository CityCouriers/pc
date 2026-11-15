<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\PestRun;
use CityCouriers\Pc\Tests\Support\Child;
use CityCouriers\Pc\Tests\Support\GitRepo;
use CityCouriers\Pc\Tests\Support\SpyStream;
use CityCouriers\Pc\Tests\Support\Warnings;

it('log przez protokół strumieni: wyjście w porcjach po 8192 bajty, stderr na końcu, log zamknięty jawnie przez fclose', function (): void {
    $repo = new GitRepo();
    SpyStream::register();
    try {
        $code = PestRun::run([PHP_BINARY, '-r', 'echo str_repeat("x", 1 << 20); fwrite(STDERR, "e");'], $repo->path, [], 'pcspy://bieg.log', false);
        $writes = [];
        foreach (SpyStream::$events as $event) {
            if (str_starts_with($event, 'write ')) {
                $writes[] = (int) substr($event, 6);
            }
        }

        expect($code)->toBe(0)
            ->and(SpyStream::$files['pcspy://bieg.log'] ?? null)->toBe(str_repeat('x', 1 << 20) . 'e')
            ->and(max([0, ...$writes]))->toBe(8192)
            ->and(SpyStream::$events)->toContain('close pcspy://bieg.log fclose');
    } finally {
        SpyStream::unregister();
        $repo->remove();
    }
});

it('wyjście procesu trafia do logu w całości (także samo „0”), stderr na końcu, bez pliku obok logu', function (): void {
    $repo = new GitRepo();
    try {
        $log = $repo->path . '/bieg.log';
        $code = PestRun::run([PHP_BINARY, '-r', 'echo 0; fwrite(STDERR, "e");'], $repo->path, [], $log, false);

        expect($code)->toBe(0)
            ->and((string) file_get_contents($log))->toBe('0e')
            ->and(array_values(array_diff(scandir($repo->path) ?: [], ['.', '..', '.git'])))->toBe(['bieg.log']);
    } finally {
        $repo->remove();
    }
});

it('duży stderr (większy niż bufor rury) nie blokuje procesu i trafia na koniec logu', function (): void {
    $repo = new GitRepo();
    try {
        $log = $repo->path . '/bieg.log';
        $code = PestRun::run([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 1 << 20)); echo "o";'], $repo->path, [], $log, false);

        expect($code)->toBe(0)
            ->and((string) file_get_contents($log))->toBe('o' . str_repeat('e', 1 << 20));
    } finally {
        $repo->remove();
    }
});

it('proces czytający stdin dostaje od razu koniec danych (stdin zamknięty, bez zawieszenia)', function (): void {
    $repo = new GitRepo();
    try {
        $code = PestRun::run(Child::stdinReader(), $repo->path, [], $repo->path . '/bieg.log', false);

        expect($code)->toBe(0)
            ->and((string) file_get_contents($repo->path . '/bieg.log'))->toBe('0');
    } finally {
        $repo->remove();
    }
});

it('program, którego nie da się uruchomić: log zamknięty jawnie przed wyjątkiem', function (): void {
    $repo = new GitRepo();
    SpyStream::register();
    try {
        [$message] = Warnings::collect(static function () use ($repo): ?string {
            try {
                PestRun::run(['pc-nie-ma-takiego-programu'], $repo->path, [], 'pcspy://bieg.log', false);
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }

            return null;
        });

        expect($message)->toBe('Nie można uruchomić procesu: pc-nie-ma-takiego-programu')
            ->and(SpyStream::$events)->toContain('close pcspy://bieg.log fclose');
    } finally {
        SpyStream::unregister();
        $repo->remove();
    }
});

it('wyjście krótkiego polecenia jako tekst, bez pliku tymczasowego po wszystkim', function (): void {
    $token = bin2hex(random_bytes(8));
    [$code, $output] = PestRun::capture([PHP_BINARY, '-r', 'echo $argv[1];', $token], sys_get_temp_dir());
    // Plik tymczasowy ma przedrostek „pc-” (Windows skraca przedrostek do trzech znaków); szukamy tego z naszą treścią.
    $left = array_filter(
        glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-*') ?: [],
        static fn(string $file): bool => is_file($file) && filesize($file) === strlen($token) && file_get_contents($file) === $token,
    );

    expect([$code, $output, $left])->toBe([0, $token, []]);
});

it('kod wyjścia -1 to proces przerwany (null); na Linuksie exit(-1) daje 255', function (): void {
    $repo = new GitRepo();
    try {
        $code = PestRun::run([PHP_BINARY, '-r', 'exit(-1);'], $repo->path, [], $repo->path . '/bieg.log', false);

        expect($code)->toBe(PHP_OS_FAMILY === 'Windows' ? null : 255);
    } finally {
        $repo->remove();
    }
});

it('zmienna środowiska o nazwie z samych cyfr nie wywraca uruchomienia procesu (#69)', function (): void {
    $repo = new GitRepo();
    $previous = getenv('123');
    putenv('123=x');
    try {
        $code = PestRun::run([PHP_BINARY, '-r', 'echo "ok";'], $repo->path, ['PC_A' => 'b'], $repo->path . '/bieg.log', false);

        expect($code)->toBe(0)
            ->and((string) file_get_contents($repo->path . '/bieg.log'))->toBe('ok');
    } finally {
        putenv($previous === false ? '123' : '123=' . $previous);
        $repo->remove();
    }
});

it('log, którego nie da się otworzyć, to wyjątek ze ścieżką logu', function (): void {
    $repo = new GitRepo();
    try {
        $log = $repo->path . '/brak/bieg.log';
        [$error, $warnings] = Warnings::collect(static function () use ($repo, $log): ?Throwable {
            try {
                PestRun::run([PHP_BINARY, '-r', ''], $repo->path, [], $log, false);
            } catch (RuntimeException $exception) {
                return $exception;
            }

            return null;
        });

        expect($error?->getMessage())->toBe('Nie można zapisać logu: ' . $log)
            ->and(implode("\n", $warnings))->toContain('bieg.log');
    } finally {
        $repo->remove();
    }
});

// Polecenie jako tablica: Windows (CreateProcess) i Linux (posix_spawn) zgłaszają brak programu już w proc_open.
it('program, którego nie da się uruchomić: wyjątek z całym poleceniem', function (): void {
    $repo = new GitRepo();
    try {
        [$result] = Warnings::collect(static function () use ($repo): int|string|null {
            try {
                return PestRun::run(['pc-nie-ma-takiego-programu', 'a', 'b'], $repo->path, [], $repo->path . '/bieg.log', false);
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }
        });

        expect($result)->toBe('Nie można uruchomić procesu: pc-nie-ma-takiego-programu a b');
    } finally {
        $repo->remove();
    }
});

it('log usunięty przez proces w trakcie capture: pusty tekst zamiast awarii', function (): void {
    // Proces wypisuje losowy znacznik (trafia do pliku logu), a po chwili usuwa plik tymczasowy z tą treścią.
    $child = <<<'PHP'
        echo $argv[1];
        fflush(STDOUT);
        usleep(700000);
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-*') ?: [] as $file) {
            if (@file_get_contents($file) === $argv[1]) {
                unlink($file);
            }
        }
        PHP;
    [$result] = Warnings::collect(static fn(): array => PestRun::capture([PHP_BINARY, '-r', $child, bin2hex(random_bytes(8))], sys_get_temp_dir()));

    expect($result)->toBe([0, '']);
});

it('copy (prywatna): porcje po 8192 bajty, zapis przez log', function (): void {
    SpyStream::register();
    try {
        $source = fopen('php://memory', 'r+') ?: throw new RuntimeException('php://memory');
        fwrite($source, str_repeat('x', 9000));
        rewind($source);
        $log = fopen('pcspy://copy.log', 'wb') ?: throw new RuntimeException('pcspy://copy.log');
        (new ReflectionMethod(PestRun::class, 'copy'))->invoke(null, $source, $log, false);
        fclose($log);
        $writes = array_map(static fn(string $event): int => (int) substr($event, 6), array_values(array_filter(SpyStream::$events, static fn(string $event): bool => str_starts_with($event, 'write '))));

        expect($writes)->toBe([8192, 808]);
    } finally {
        SpyStream::unregister();
    }
});

it('copy (prywatna): błąd odczytu (fread daje false) kończy kopiowanie bez zapisu i bez wyjątku', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'pc-');
    $writeOnly = fopen((string) $path, 'wb') ?: throw new RuntimeException('plik tymczasowy');
    $log = fopen('php://memory', 'r+') ?: throw new RuntimeException('php://memory');
    try {
        [, $warnings] = Warnings::collect(static function () use ($writeOnly, $log): void {
            (new ReflectionMethod(PestRun::class, 'copy'))->invoke(null, $writeOnly, $log, false);
        });
        rewind($log);

        expect(stream_get_contents($log))->toBe('')
            ->and($warnings)->not->toBe([]);
    } finally {
        fclose($writeOnly);
        unlink((string) $path);
    }
});

it('brak pliku tymczasowego (tmpfile i tempnam zwracają false) to wyjątek, a log jest zamknięty jawnie', function (): void {
    // Katalog tymczasowy PHP jest zapamiętany w procesie, więc brak pliku tymczasowego wywołujemy wyczerpaniem deskryptorów
    // (kopie stdout przez php://fd/1, bez protokołu file); log idzie przez SpyStream, który deskryptora nie zajmuje.
    $thrown = static function (Closure $run): string {
        try {
            $run();
        } catch (Throwable $exception) {
            return $exception::class . ': ' . $exception->getMessage();
        }

        return 'bez wyjątku';
    };
    $program = [PHP_BINARY, '-r', 'echo "x";'];
    $directory = sys_get_temp_dir();
    SpyStream::register();
    try {
        // Rozgrzewka: po wyczerpaniu deskryptorów autoload nie ma czym otworzyć pliku klasy.
        PestRun::run($program, $directory, [], 'pcspy://rozgrzewka.log', false);
        PestRun::capture($program, $directory);
        [$result] = Warnings::collect(static function () use ($thrown, $program, $directory): array {
            $handles = [];
            try {
                while (($handle = fopen('php://fd/1', 'wb')) !== false) {
                    $handles[] = $handle;
                }
                SpyStream::$events = [];
                $run = $thrown(static fn() => PestRun::run($program, $directory, [], 'pcspy://bieg.log', false));
                $events = SpyStream::$events;
                $capture = $thrown(static fn() => PestRun::capture($program, $directory));
            } finally {
                foreach ($handles as $handle) {
                    fclose($handle);
                }
            }

            return [$run, $events, $capture];
        });
    } finally {
        SpyStream::unregister();
    }

    expect($result[0])->toBe('RuntimeException: Nie można utworzyć pliku tymczasowego na stderr.')
        ->and($result[1])->toBe(['open pcspy://bieg.log wb', 'close pcspy://bieg.log fclose'])
        ->and($result[2])->toBe('RuntimeException: Nie można utworzyć pliku tymczasowego.');
});
