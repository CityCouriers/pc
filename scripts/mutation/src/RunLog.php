<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Surowy log biegu Pest Mutate: linia podsumowania zestawu początkowego `Tests: ...`, linia „N Mutations for M Files
 * created”, linia podsumowania `Mutations: ...` oraz bloki UNTESTED/UNCOVERED z identyfikatorami. To drugie, niezależne
 * od dziennika źródło: skrypt porównuje oba. Ten sam parser czyta log kontroli tożsamościowej (tylko `Tests: ...`).
 * Licznik, którego nie udało się odczytać, jest nullem (błędem), nigdy zerem.
 */
final readonly class RunLog
{
    /**
     * @param array<string, int>|null $summary kategoria (`tested`, `untested`, `uncovered`, `pending`, `timeout`) → liczba
     * @param array<string, string> $listed identyfikator → kategoria z bloków UNTESTED/UNCOVERED
     * @param string|null $tests pierwsza linia podsumowania testów (`Tests: ...`) bez prefiksu; null, gdy jej nie ma
     */
    public function __construct(
        public ?int $created,
        public ?int $files,
        public ?array $summary,
        public array $listed,
        public ?string $tests,
    ) {}

    public static function parse(string $log): self
    {
        $plain = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $log) ?? $log;
        $created = null;
        $files = null;
        if (preg_match('/^\s*(\d+) Mutations? for (\d+) Files? created/m', $plain, $matches) === 1) {
            $created = (int) $matches[1];
            $files = (int) $matches[2];
        }

        $tests = preg_match('/^\s*Tests:\s+(.+?)\s*$/m', $plain, $matches) === 1 ? $matches[1] : null;

        return new self($created, $files, self::summary($plain), self::listed($plain), $tests);
    }

    /**
     * Liczba testów, gdy wszystkie przeszły (dokładnie „N passed (M assertions)”, N > 0); null przy braku albo ucięciu
     * podsumowania i przy każdej innej kategorii (failed, warnings, skipped, risky, incomplete, todos, deprecated, notices).
     * Liczba spoza zakresu int to też null: `(int)` dałoby wtedy 0 albo PHP_INT_MAX.
     */
    public function passed(): ?int
    {
        return preg_match('/^([1-9]\d*) passed \(\d+ assertions?\)$/', $this->tests ?? '', $matches) === 1 ? (filter_var($matches[1], FILTER_VALIDATE_INT) ?: null) : null;
    }

    /**
     * Liczba w danej kategorii podsumowania; brak kategorii w obecnym podsumowaniu to 0 (wtyczka pomija zera).
     */
    public function count(string $category): ?int
    {
        return $this->summary === null ? null : $this->summary[$category] ?? 0;
    }

    /**
     * @return array<string, int>|null
     */
    private static function summary(string $plain): ?array
    {
        if (preg_match('/^\s*Mutations:\s*(.*?)\s*$/m', $plain, $matches) !== 1) {
            return null;
        }
        $summary = [];
        foreach (explode(',', $matches[1]) as $part) {
            if (preg_match('/^\s*(\d+)\s+(tested|untested|uncovered|pending|timeout)\s*$/', $part, $item) !== 1) {
                return null;
            }
            $summary[$item[2]] = (int) $item[1];
        }

        return $summary;
    }

    /**
     * @return array<string, string>
     */
    private static function listed(string $plain): array
    {
        preg_match_all('/^\s*(UNTESTED|UNCOVERED)\s+.*?- ID: ([0-9a-f]+)\s*$/m', $plain, $matches, PREG_SET_ORDER);
        $listed = [];
        foreach ($matches as $match) {
            $listed[$match[2]] = strtolower($match[1]);
        }

        return $listed;
    }
}
