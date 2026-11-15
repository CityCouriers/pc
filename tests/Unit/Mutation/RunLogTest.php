<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\RunLog;

/**
 * Fragment logu Pest Mutate 5.0.2 z biegu równoległego (z kodami kolorów, jak w pliku przekierowanym z terminala).
 */
function mutationParallelLog(): string
{
    return implode("\n", [
        '  Tests:    376 passed (1309 assertions)',
        "\e[1A  Parallel: 32 processes",
        '  Mutating application files...',
        "  \e[90m2001 Mutations for 74 Files created\e[39m",
        '  ----x..tt',
        "   \e[39;41;1m UNTESTED \e[39;49;22m \e[39;1m./src/Scope/SelectionRenderer.php \e[39;22m\e[90m > Line 26: ConcatRemoveLeft - ID: f07273c0557b0fe6\e[39m",
        '   UNCOVERED  ./src/Version.php  > Line 12: ConstantPublicToProtected - ID: 270b75c7d44caad8',
        "  \e[90mMutations:\e[39m \e[31;1m27 untested\e[39;22m\e[90m,\e[39m 296 uncovered, 2 timeout, 1676 tested",
        '  Score:     83.86%',
    ]);
}

it('czyta liczbę wygenerowanych, podsumowanie i identyfikatory żywych mutantów (z kodami kolorów)', function (): void {
    $log = RunLog::parse(mutationParallelLog());

    expect($log->created)->toBe(2001)
        ->and($log->files)->toBe(74)
        ->and($log->summary)->toBe(['untested' => 27, 'uncovered' => 296, 'timeout' => 2, 'tested' => 1676])
        ->and($log->listed)->toBe(['f07273c0557b0fe6' => 'untested', '270b75c7d44caad8' => 'uncovered'])
        ->and($log->count('pending'))->toBe(0)
        ->and($log->count('tested'))->toBe(1676)
        ->and($log->tests)->toBe('376 passed (1309 assertions)')
        ->and($log->passed())->toBe(376);
});

it('podsumowanie testów to pierwsza linia „Tests:” (zestaw początkowy), bez kodów kolorów; bez takiej linii null', function (): void {
    expect(RunLog::parse("  \e[90mTests:\e[39m    5 passed (9 assertions)\n  Tests:    1 failed")->tests)->toBe('5 passed (9 assertions)')
        ->and(RunLog::parse('  5 Mutations for 1 Files created')->tests)->toBeNull();
});

it('liczba zaliczonych testów tylko z pełnego podsumowania „N passed (M assertions)”, inaczej null', function (?string $tests, ?int $passed): void {
    expect((new RunLog(null, null, null, [], $tests))->passed())->toBe($passed);
})->with([
    'wszystkie zaliczone' => ['376 passed (1309 assertions)', 376],
    'jedna asercja' => ['1 passed (1 assertion)', 1],
    'brak podsumowania' => [null, null],
    'zero testów' => ['0 passed (0 assertions)', null],
    'zero z przodu' => ['0376 passed (1309 assertions)', null],
    'największy int' => [PHP_INT_MAX . ' passed (1 assertion)', PHP_INT_MAX],
    'tuż poza zakresem int' => ['9223372036854775808 passed (1 assertion)', null],
    'poza zakresem float' => [str_repeat('9', 400) . ' passed (1 assertion)', null],
    'ucięte po liczbie' => ['376 passed', null],
    'ucięte w nawiasie' => ['376 passed (1309', null],
    'nieudany test' => ['1 failed, 375 passed (1309 assertions)', null],
    'ostrzeżenia' => ['48 warnings, 328 passed (1200 assertions)', null],
    'pominięty' => ['1 skipped, 375 passed (1309 assertions)', null],
    'ryzykowny' => ['1 risky, 375 passed (1309 assertions)', null],
    'niekompletny' => ['1 incomplete, 375 passed (1309 assertions)', null],
]);

it('bieg bez mutantów: zero wygenerowanych to liczba odczytana, nie brak', function (): void {
    $log = RunLog::parse("  0 Mutations for 0 Files created\n  Mutations: 0 tested\n  INFO  No mutations created.");

    expect($log->created)->toBe(0)
        ->and($log->files)->toBe(0)
        ->and($log->summary)->toBe(['tested' => 0]);
});

it('kontrola ujemna: licznik, którego nie da się odczytać, jest nullem, nigdy zerem', function (string $log): void {
    $parsed = RunLog::parse($log);

    expect($parsed->summary)->toBeNull()
        ->and($parsed->count('tested'))->toBeNull();
})->with([
    'pusty log' => [''],
    'bieg przerwany przed podsumowaniem' => ["  2001 Mutations for 74 Files created\n  ....x"],
    'nieznana kategoria w podsumowaniu' => ['  Mutations: 3 tested, 2 escaped'],
]);

it('log bez linii o wygenerowaniu nie ma liczby mutantów', function (): void {
    expect(RunLog::parse('  Tests:    376 passed')->created)->toBeNull();
});

it('licznik kategorii z pustego podsumowania to zero, a nie brak', function (): void {
    expect((new RunLog(0, 0, [], [], null))->count('tested'))->toBe(0);
});

it('liczby z logu biorą same cyfry także wtedy, gdy \s w bieżącym locale obejmuje twardą spację (Windows-1252, ISO-8859-1)', function (): void {
    $previous = (string) setlocale(LC_CTYPE, '0');
    $latin = setlocale(LC_CTYPE, 'English_United States.1252', 'en_US.ISO-8859-1', 'en_US.iso88591', 'de_DE.ISO-8859-1');
    try {
        // Twarda spacja na początku linii i po przecinku (tę po „Mutations:” zjada już pierwszy \s* wzorca linii).
        $log = RunLog::parse("\xA012 Mutations for 3 Files created\n  Mutations: 1 untested,\xA05 tested");
    } finally {
        setlocale(LC_CTYPE, $previous);
    }

    // Bez takiego locale (C, UTF-8) twarda spacja nie jest białym znakiem, więc linii nie ma; liczba nigdy nie jest zerem.
    expect([$log->created, $log->files, $log->summary])->toBe($latin === false ? [null, null, null] : [12, 3, ['untested' => 1, 'tested' => 5]]);
});
