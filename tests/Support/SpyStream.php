<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

/**
 * Protokół strumieni `pcspy://` dla testów skryptu mutacji. Pliki trzyma w pamięci i zapisuje zdarzenia (otwarcie, zapis,
 * zamknięcie). Umie też odczyt, który się nie udaje mimo `is_file()`. Wrapper plików wtyczki mutacji podmienia tylko protokół
 * `file`, więc ten działa tak samo w procesie mutanta.
 */
final class SpyStream
{
    public const string PROTOCOL = 'pcspy';

    /** @var resource|null kontekst ustawiany przez PHP */
    public $context;

    /** @var array<string, string> ścieżka → treść */
    public static array $files = [];

    /** @var list<string> */
    public static array $events = [];

    /** Każde otwarcie do odczytu się nie udaje, choć `is_file()` daje true. */
    public static bool $readFails = false;

    private string $path = '';

    private int $position = 0;

    public static function register(): void
    {
        self::$files = [];
        self::$events = [];
        self::$readFails = false;
        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    public static function unregister(): void
    {
        stream_wrapper_unregister(self::PROTOCOL);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$events[] = "open {$path} {$mode}";
        $this->path = $path;
        if (str_contains($mode, 'r') && self::$readFails) {
            return false;
        }
        if (str_contains($mode, 'w')) {
            self::$files[$path] = '';
        }

        return true;
    }

    public function stream_write(string $data): int
    {
        self::$events[] = 'write ' . strlen($data);
        self::$files[$this->path] = (self::$files[$this->path] ?? '') . $data;

        return strlen($data);
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$files[$this->path] ?? '', $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$files[$this->path] ?? '');
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        // Czy zamyka jawne fclose(), czy dopiero zwolnienie zasobu.
        $byFclose = in_array('fclose', array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function'), true);
        self::$events[] = "close {$this->path}" . ($byFclose ? ' fclose' : '');
    }

    /**
     * @return array{mode: int, size: int}
     */
    public function stream_stat(): array
    {
        return ['mode' => 0o100644, 'size' => 0];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    /**
     * @return array{mode: int, size: int}
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0o100644, 'size' => 0];
    }

    public function unlink(string $path): bool
    {
        self::$events[] = "unlink {$path}";

        return true;
    }
}
