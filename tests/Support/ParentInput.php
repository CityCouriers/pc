<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use RuntimeException;

/**
 * Podmiana stdin procesu Pesta na czas jednego wywołania: proces potomny uruchomiony BEZ deskryptora 0 dziedziczy stdin
 * rodzica, więc test może sprawdzić, że kod pod testem daje potomkowi pusty stdin zamiast odziedziczonego (#73).
 *
 * Mechanika (bez FFI, bez procesu potomnego dla kodu pod testem, więc działa także pod Pest Mutate): `fclose(STDIN)`
 * zwalnia deskryptor 0, a następny `fopen('php://fd/N')` (kopia deskryptora `tmpfile()` z danymi) dostaje najniższy wolny
 * deskryptor, czyli 0, i staje się uchwytem standardowym. Zwykły plik by nie wystarczył (nie jest dziedziczony). Oryginalny
 * stdin wraca z kopii zapisanej przez `php://stdin`.
 *
 * Ograniczenia: stała `STDIN` zostaje zamkniętym zasobem do końca procesu, a worker Pesta w trybie `--parallel` czyta z niej
 * polecenia (`feof(STDIN)`), więc w workerze podmiany nie robimy: scenariusz idzie do osobnego procesu php
 * (`callInChild()`). Tam kod pod testem nie jest zmutowany (wrapper wtyczki działa tylko w procesie Pesta), ale test
 * nie jest pomijany.
 */
final class ParentInput
{
    /** @var resource|null uchwyt stdin (fd 0) w danej chwili */
    private static $zero = null;

    /** @var resource|null kopia oryginalnego stdin, trzymana do końca procesu */
    private static $saved = null;

    private static int $savedFd = 0;

    /**
     * Wynik scenariusza (`StdinScenarios`), gdy stdin procesu nadrzędnego ma podane dane: w procesie Pesta po podmianie
     * stdin, a w workerze `--parallel` w osobnym procesie php.
     */
    public static function call(string $content, string $scenario, string ...$arguments): string
    {
        if (isset($_SERVER['PEST_PARALLEL'])) {
            return self::callInChild($content, $scenario, ...$arguments);
        }

        return self::with($content, static fn(): string => StdinScenarios::run($scenario, array_values($arguments)));
    }

    /**
     * Scenariusz w osobnym procesie php, który dostaje dane na stdin (rurka z danymi, zamknięta po zapisie).
     */
    public static function callInChild(string $content, string $scenario, string ...$arguments): string
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/stdin-scenario.php', $scenario, ...array_values($arguments)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Nie można uruchomić procesu php scenariusza.');
        }
        fwrite($pipes[0], $content);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    /**
     * @template T
     *
     * @param callable(): T $run
     *
     * @return T
     */
    private static function with(string $content, callable $run): mixed
    {
        if (self::$saved === null) {
            self::$zero = STDIN;
            self::$savedFd = self::lowestFreeDescriptor(3);
            self::$saved = fopen('php://stdin', 'rb') ?: throw new RuntimeException('Nie można zapisać kopii stdin.');
        }
        $dataFd = self::lowestFreeDescriptor(self::$savedFd + 1);
        $data = tmpfile() ?: throw new RuntimeException('Nie można utworzyć pliku tymczasowego.');
        fwrite($data, $content);
        rewind($data);
        $zero = self::$zero;
        if (is_resource($zero)) {
            fclose($zero);
        }
        self::$zero = fopen('php://fd/' . $dataFd, 'rb') ?: throw new RuntimeException('Nie można podmienić stdin.');
        try {
            return $run();
        } finally {
            if (is_resource(self::$zero)) {
                fclose(self::$zero);
            }
            fclose($data);
            self::$zero = fopen('php://fd/' . self::$savedFd, 'rb') ?: null;
        }
    }

    private static function lowestFreeDescriptor(int $from): int
    {
        // Nieudane otwarcie daje ostrzeżenie, które PHPUnit widzi także przy `@`: zbieramy je (zob. Warnings).
        [$free] = Warnings::collect(static function () use ($from): int {
            while (($probe = fopen('php://fd/' . $from, 'rb')) !== false) {
                fclose($probe);
                ++$from;
            }

            return $from;
        });

        return $free;
    }
}
