<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use CityCouriers\Pc\Process\EmptyInput;

/**
 * Jedyne miejsce, w którym testy uruchamiają procesy potomne. Każdy dostaje pusty stdin (rurka zamknięta zaraz po
 * uruchomieniu, nie urządzenie przez protokół `file`, #73), więc nie dziedziczy terminala Pesta. Msys bash (Git for Windows) z odziedziczonym pty mintty zmienia tryb terminala i urywa bieg
 * procesu nadrzędnego z kodem 0, a w PowerShell i cmd (stdin to konsola Windows) tego nie widać. Pilnuje tego
 * `Tests\Unit\ChildProcessPolicyTest`.
 */
final class Child
{
    /**
     * Kod strażnika: uruchamia `$argv[1]` (kod PHP) jako wnuka z odziedziczonym stdin i czeka na niego najwyżej 3 sekundy.
     * Wypisuje wynik wnuka albo `BLOCKED`, gdy wnuk nadal czeka (np. czyta stdin, który nikt nie zamknął). Odczytu stdin
     * na rurce nie da się ograniczyć czasem w PHP na Windows (`stream_select` i tryb nieblokujący nie działają na
     * rurkach), więc test, w którym stdin nie został zamknięty (mutant, błąd), kończy się porażką zamiast zawieszenia.
     */
    public const string STDIN_GUARD = <<<'PHP'
        $child = proc_open([PHP_BINARY, '-r', $argv[1]], [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $start = microtime(true);
        while (proc_get_status($child)['running'] && microtime(true) - $start < 3.0) {
            usleep(20000);
        }
        if (proc_get_status($child)['running']) {
            proc_terminate($child);
            echo 'BLOCKED';
        } else {
            echo stream_get_contents($pipes[1]);
        }
        PHP;

    /**
     * Polecenie, które czyta stdin do końca i wypisuje liczbę bajtów albo `BLOCKED`, gdy po 3 sekundach nadal nie ma EOF
     * (zob. `STDIN_GUARD`).
     *
     * @return list<string>
     */
    public static function stdinReader(): array
    {
        return [PHP_BINARY, '-r', self::STDIN_GUARD, 'echo strlen((string) stream_get_contents(STDIN));'];
    }

    /**
     * `proc_open` z pustym stdin (rurka zamknięta zaraz po uruchomieniu, więc proces dostaje EOF) i potokami na stdout (1)
     * oraz stderr (2). Rurki stdin nie zwracamy: zamykamy ją tu, przed jakimkolwiek odczytem.
     *
     * @param list<string>               $command
     * @param array<int, resource>       $pipes
     * @param array<string, string>|null $environment
     * @param array<string, bool>|null   $options
     *
     * @return resource|false
     */
    public static function open(array $command, array &$pipes, ?string $directory = null, ?array $environment = null, ?array $options = null)
    {
        $input = new EmptyInput();
        $process = proc_open($command, [0 => $input->descriptor(), 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment, $options);
        $input->close($pipes);

        return $process;
    }

    /**
     * Uruchamia polecenie i zwraca kod wyjścia oraz stdout i stderr.
     *
     * @param list<string>               $command
     * @param array<string, string>|null $environment
     *
     * @return array{int, string, string}
     */
    public static function capture(array $command, ?string $directory = null, ?array $environment = null): array
    {
        $pipes = [];
        $process = self::open($command, $pipes, $directory, $environment);
        if ($process === false) {
            return [127, '', ''];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
