<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Process;

/**
 * Stdin dla procesów potomnych: rurka, którą zamykamy zaraz po uruchomieniu, więc proces dostaje natychmiast koniec pliku.
 *
 * Proces potomny bez jawnego stdin dziedziczy stdin rodzica: terminal hooka `pre-commit`, a w `pre-push` listę refów
 * od gita. Program, który z niego czyta albo zmienia tryb terminala (na przykład bash z Git for Windows pod mintty),
 * zawiesza się, zjada cudze dane albo psuje wyjście i urywa bieg rodzica. Każdy proces uruchamiany przez `pc`
 * dostaje więc pusty stdin.
 *
 * Nie używamy do tego urządzenia `NUL` ani `/dev/null` przez protokół `file`: wtyczka Pest Mutate podmienia wrapper
 * strumieni tego protokołu w całym procesie mutanta i `proc_open` nie dostaje wtedy używalnego deskryptora (#73).
 */
final readonly class EmptyInput
{
    /**
     * @return array{string, string}
     */
    public function descriptor(): array
    {
        return ['pipe', 'r'];
    }

    /**
     * Zamyka końcówkę zapisu stdin zaraz po udanym `proc_open` (przed odczytem stdout i stderr), żeby proces dostał EOF.
     * Przy nieudanym `proc_open` nie ma czego zamykać.
     *
     * @param array<int, resource> $pipes
     *
     * @param-out array<int, resource> $pipes
     */
    public function close(array &$pipes): void
    {
        if (is_resource($pipes[0] ?? null)) {
            fclose($pipes[0]);
        }
        unset($pipes[0]);
    }
}
