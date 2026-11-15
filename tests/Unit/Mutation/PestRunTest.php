<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\PestRun;
use CityCouriers\Pc\Tests\Support\GitRepo;
use CityCouriers\Pc\Tests\Support\SpyStream;
use CityCouriers\Pc\Tests\Support\Warnings;

it('log przez protokół strumieni: wyjście w porcjach po 8192 bajty, stderr na końcu, log zamknięty jawnie przez fclose', function (): void {
    $repo = new GitRepo();
    SpyStream::register($repo->path . '/spy.stderr');
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

it('proces, którego nie da się uruchomić (plik stderr się nie otwiera): log zamknięty przed wyjątkiem', function (): void {
    $repo = new GitRepo();
    SpyStream::register($repo->path . '/spy.stderr');
    SpyStream::$stderrFails = true;
    try {
        [$message] = Warnings::collect(static function () use ($repo): ?string {
            try {
                PestRun::run([PHP_BINARY, '-r', 'echo 1;'], $repo->path, [], 'pcspy://bieg.log', false);
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }

            return null;
        });

        expect($message)->toBe('Nie można uruchomić procesu: ' . PHP_BINARY . ' -r echo 1;')
            ->and(SpyStream::$events)->toContain('close pcspy://bieg.log fclose');
    } finally {
        SpyStream::unregister();
        $repo->remove();
    }
});

it('log usunięty przez proces w trakcie capture: pusty tekst zamiast awarii', function (): void {
    // Proces znajduje własny plik stderr po losowym znaczniku i usuwa log obok niego (ta sama nazwa bez „.stderr”).
    $child = <<<'PHP'
        fwrite(STDERR, $argv[1]);
        fflush(STDERR);
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-*.stderr') ?: [] as $file) {
            if (@file_get_contents($file) === $argv[1]) {
                unlink(substr($file, 0, -strlen('.stderr')));
            }
        }
        PHP;
    [$result] = Warnings::collect(static fn(): array => PestRun::capture([PHP_BINARY, '-r', $child, bin2hex(random_bytes(8))], sys_get_temp_dir()));

    expect($result)->toBe([0, '']);
});

it('wyjście procesu trafia do logu w całości (także samo „0”), stderr na końcu, plik stderr znika', function (): void {
    $repo = new GitRepo();
    try {
        $log = $repo->path . '/bieg.log';
        $code = PestRun::run([PHP_BINARY, '-r', 'echo 0; fwrite(STDERR, "e");'], $repo->path, [], $log, false);

        expect($code)->toBe(0)
            ->and((string) file_get_contents($log))->toBe('0e')
            ->and(file_exists($log . '.stderr'))->toBeFalse();
    } finally {
        $repo->remove();
    }
});

it('plik stderr usunięty przez proces: log bez stderr zamiast awarii', function (): void {
    $repo = new GitRepo();
    try {
        $log = $repo->path . '/bieg.log';
        // Odczyt brakującego pliku stderr jest wyciszony w kodzie; PHPUnit i tak widzi takie ostrzeżenie, więc tu zebrane.
        [$code] = Warnings::collect(static fn(): ?int => PestRun::run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "e"); unlink((string) getenv("PC_STDERR")); echo "o";'],
            $repo->path,
            ['PC_STDERR' => $log . '.stderr'],
            $log,
            false,
        ));

        expect($code)->toBe(0)
            ->and((string) file_get_contents($log))->toBe('o');
    } finally {
        $repo->remove();
    }
});

it('proces czytający stdin dostaje od razu koniec danych (stdin zamknięty, bez zawieszenia)', function (): void {
    $repo = new GitRepo();
    try {
        $code = PestRun::run([PHP_BINARY, '-r', 'echo stream_get_contents(STDIN) === "" ? "koniec" : "dane";'], $repo->path, [], $repo->path . '/bieg.log', false);

        expect($code)->toBe(0)
            ->and((string) file_get_contents($repo->path . '/bieg.log'))->toBe('koniec');
    } finally {
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
