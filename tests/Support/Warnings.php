<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use Closure;

/**
 * Ostrzeżenia PHP zebrane zamiast zgłoszenia, gdy test celowo wywołuje brak pliku, programu albo nieudany odczyt.
 * PHPUnit widzi także ostrzeżenia wyciszone przez `@`, więc kod pod testem nie może ich ukryć sam.
 */
final class Warnings
{
    /**
     * @template T
     *
     * @param Closure(): T $run
     *
     * @return array{T, list<string>} wynik i treści ostrzeżeń
     */
    public static function collect(Closure $run): array
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
}
