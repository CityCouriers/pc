<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Acceptance;
use CityCouriers\Pc\Dev\Mutation\Generated;
use CityCouriers\Pc\Dev\Mutation\Journal;
use CityCouriers\Pc\Dev\Mutation\Outcome;
use CityCouriers\Pc\Dev\Mutation\RunLog;
use CityCouriers\Pc\Dev\Mutation\Status;
use CityCouriers\Pc\Dev\Mutation\Target;
use CityCouriers\Pc\Dev\Mutation\Verdict;
use CityCouriers\Pc\Dev\Mutation\ZeroMutants;

/**
 * Spójny bieg: dwa cele z mutantami i jeden z wpisem „bez mutantów”, wszystkie mutanty zabite testem.
 *
 * @return array{targets: list<Target>, mutants: array<string, array{string, Status}>, zero: array<string, string>}
 */
function mutationRun(): array
{
    return [
        'targets' => [new Target('src/A.php', 'a', ['A']), new Target('src/B.php', 'b', ['B']), new Target('src/C.php', 'c', ['C'])],
        'mutants' => ['a1' => ['src/A.php', Status::Tested], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]],
        'zero' => ['src/C.php' => 'interfejs bez kodu wykonywalnego'],
    ];
}

/**
 * @param array<string, array{string, Status}> $mutants identyfikator → [plik, status]
 * @param list<string> $withoutResult identyfikatory bez wyniku (bieg przerwany)
 */
function mutationJournal(array $mutants, array $withoutResult = [], ?string $summary = '1 failed'): Journal
{
    $generated = [];
    $outcomes = [];
    foreach ($mutants as $id => [$file, $status]) {
        $generated[$id] = new Generated($id, $file, 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 3, 3);
        if (!in_array($id, $withoutResult, true)) {
            $outcomes[$id] = new Outcome($id, $status, $status === Status::Tested ? 1 : 0, 2, 'testy', 0.2, $status === Status::Tested ? $summary : '2 passed');
        }
    }

    return new Journal($generated, $outcomes, []);
}

/**
 * Log zgodny z dziennikiem (te same liczby i lista żywych mutantów).
 *
 * @param array<string, array{string, Status}> $mutants
 */
function mutationLog(array $mutants): string
{
    $counts = [];
    $lines = [sprintf('  %d Mutations for %d Files created', count($mutants), count(array_unique(array_column($mutants, 0))))];
    foreach ($mutants as $id => [$file, $status]) {
        $counts[$status->label()] = ($counts[$status->label()] ?? 0) + 1;
        if ($status === Status::Untested || $status === Status::Uncovered) {
            $lines[] = sprintf('   %s  ./%s  > Line 3: PlusToMinus - ID: %s', strtoupper($status->value), $file, $id);
        }
    }
    $parts = [];
    foreach ($counts as $category => $count) {
        $parts[] = $count . ' ' . $category;
    }
    $lines[] = '  Mutations: ' . implode(', ', $parts === [] ? ['0 tested'] : $parts);

    return implode("\n", $lines);
}

/**
 * @param array<mixed> $override klucze: targets, mutants, zero, journal, log, exit, registry, classified, controls, scope
 */
function mutationVerdict(array $override = []): Verdict
{
    $run = mutationRun();
    $mutants = $override['mutants'] ?? $run['mutants'];
    assert(is_array($mutants));
    /** @var array<string, array{string, Status}> $mutants */
    $journal = $override['journal'] ?? mutationJournal($mutants);
    $log = $override['log'] ?? mutationLog($mutants);
    $targets = $override['targets'] ?? $run['targets'];
    $zero = $override['zero'] ?? $run['zero'];
    $registry = $override['registry'] ?? [];
    $classified = $override['classified'] ?? [];
    $controls = $override['controls'] ?? [];
    assert($journal instanceof Journal && is_string($log) && is_array($targets) && is_array($zero) && is_array($registry) && is_array($classified) && is_array($controls));
    /** @var list<Target> $targets */
    /** @var array<string, string> $zero */
    /** @var list<string> $registry */
    /** @var array<string, string> $classified */
    /** @var list<string> $controls */
    $exit = array_key_exists('exit', $override) ? $override['exit'] : 0;
    assert(is_int($exit) || $exit === null);
    $scope = $override['scope'] ?? 'src';
    assert(is_string($scope));

    return Acceptance::evaluate($targets, $scope, $journal, RunLog::parse($log), $exit, new ZeroMutants($zero, []), $registry, $classified, $controls);
}

it('spójny, kompletny bieg bez żywych mutantów jest przyjęty, z licznikami łącznie i na cel', function (): void {
    $verdict = mutationVerdict();

    expect($verdict->reasons)->toBe([])
        ->and($verdict->accepted())->toBeTrue()
        ->and($verdict->notes)->toBe([])
        ->and($verdict->counts)->toBe(['tested' => 3])
        ->and($verdict->perTarget)->toBe([
            'src/A.php' => ['wygenerowane' => 2, 'tested' => 2],
            'src/B.php' => ['wygenerowane' => 1, 'tested' => 1],
            'src/C.php' => ['wygenerowane' => 0],
        ]);
});

it('żywy mutant z rejestru równoważnych nie odrzuca biegu', function (): void {
    $mutants = ['a1' => ['src/A.php', Status::Tested], 'a2' => ['src/A.php', Status::Untested], 'b1' => ['src/B.php', Status::Uncovered]];

    expect(mutationVerdict(['mutants' => $mutants, 'registry' => ['a2', 'b1']])->reasons)->toBe([]);
});

// Kontrole ujemne kompletności z #60: każda sytuacja odrzuca bieg z czytelnym powodem.
it('kontrola ujemna kompletności: bieg jest odrzucony z powodem', function (array $override, string $reason): void {
    $verdict = mutationVerdict($override);

    expect($verdict->accepted())->toBeFalse()
        ->and(implode("\n", $verdict->reasons))->toContain($reason);
})->with([
    'cel usunięty z manifestu' => [
        ['targets' => [new Target('src/A.php', 'a', ['A']), new Target('src/C.php', 'c', ['C'])]],
        'cel spoza manifestu: src/B.php (1 mutantów)',
    ],
    'pusty wynik (dziennik bez mutantów)' => [
        ['mutants' => [], 'zero' => []],
        'pusty wynik: dziennik nie zawiera żadnego wygenerowanego mutanta',
    ],
    'pusty wynik (brak logu)' => [
        ['log' => ''],
        'pusty wynik: log nie zawiera linii „N Mutations for M Files created”',
    ],
    'przerwany bieg (mutanty bez wyniku)' => [
        ['journal' => mutationJournal(mutationRun()['mutants'], ['b1'])],
        'bieg przerwany lub nieukończony: 1 mutantów bez wyniku (b1)',
    ],
    'przerwany bieg (brak podsumowania w logu)' => [
        ['log' => '  3 Mutations for 2 Files created', 'exit' => null],
        'bieg przerwany lub nieukończony: log nie zawiera podsumowania',
    ],
    'przerwany proces Pesta' => [
        ['exit' => null],
        'proces Pesta zakończył się kodem nieznanym: przerwany',
    ],
    'UNTESTED poza rejestrem' => [
        ['mutants' => ['a1' => ['src/A.php', Status::Untested], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]]],
        'UNTESTED poza rejestrem równoważnych: 1 mutantów (a1)',
    ],
    'UNCOVERED poza rejestrem' => [
        ['mutants' => ['a1' => ['src/A.php', Status::Uncovered], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]]],
        'UNCOVERED poza rejestrem równoważnych: 1 mutantów (a1)',
    ],
    'timeout' => [
        ['mutants' => ['a1' => ['src/A.php', Status::Timeout], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]]],
        'timeout (nigdy nie jest zabiciem): 1 mutantów (a1)',
    ],
    'mutant bez wyniku końcowego (pending)' => [
        ['mutants' => ['a1' => ['src/A.php', Status::None], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]]],
        'mutanty bez wyniku końcowego (pending): 1 (a1)',
    ],
    'niezgodna suma kategorii' => [
        ['log' => "  3 Mutations for 2 Files created\n  Mutations: 1 untested, 2 tested"],
        'niezgodna suma kategorii tested: log 2, dziennik 3',
    ],
    'niezgodna liczba wygenerowanych' => [
        ['log' => "  4 Mutations for 2 Files created\n  Mutations: 3 tested"],
        'niezgodna liczba wygenerowanych mutantów: log 4, dziennik 3',
    ],
    'niezgodna lista żywych mutantów' => [
        ['mutants' => ['a1' => ['src/A.php', Status::Untested], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]], 'registry' => ['a1'], 'log' => "  3 Mutations for 2 Files created\n  Mutations: 1 untested, 2 tested"],
        'niezgodna lista żywych mutantów: log wymienia 0, dziennik ma 1',
    ],
    'cel bez mutantów i bez wpisu' => [
        ['zero' => []],
        'cel bez mutantów i bez wpisu w bez-mutantow.json: src/C.php',
    ],
    'wpis „bez mutantów” dla celu z mutantami' => [
        ['zero' => ['src/C.php' => 'x', 'src/A.php' => 'y']],
        'nieaktualny wpis w bez-mutantow.json: src/A.php ma 2 mutantów',
    ],
    'wpis „bez mutantów” dla nieistniejącego celu' => [
        ['zero' => ['src/C.php' => 'x', 'src/D.php' => 'y']],
        'nieaktualny wpis w bez-mutantow.json: nie ma celu src/D.php',
    ],
    'proces Pesta z kodem niezerowym' => [
        ['exit' => 1],
        'proces Pesta zakończył się kodem 1 (oczekiwany 0)',
    ],
    'nieudana kontrola' => [
        ['controls' => ['kontrola zerowa: przykład']],
        'kontrola zerowa: przykład',
    ],
    // Obraz `--processes=N` bez łatki: każdy pokryty mutant „zabity” kodem 2, zanim Pest uruchomił test.
    'wszystkie zabicia bez uruchomionego testu' => [
        ['journal' => mutationJournal(mutationRun()['mutants'], [], null)],
        'zabicia bez nieudanego testu i bez sklasyfikowanej przyczyny: 3 mutantów (a1, a2, b1); nie dowodzą skutku mutacji',
    ],
]);

it('wpis „bez mutantów” spoza kawałka nie odrzuca biegu w kawałku', function (): void {
    $verdict = mutationVerdict([
        'targets' => [new Target('src/A.php', 'a', ['A'])],
        'mutants' => ['a1' => ['src/A.php', Status::Tested]],
        'zero' => ['src/Engine/C.php' => 'x'],
        'scope' => 'src/A.php',
    ]);

    expect($verdict->reasons)->toBe([]);
});

it('zabicia bez podsumowania testów odrzucają bieg, a filtr zwinięty do klas jest tylko uwagą', function (): void {
    $journal = mutationJournal(mutationRun()['mutants'], [], null);
    $outcomes = $journal->outcomes;
    $outcomes['b1'] = new Outcome('b1', Status::Tested, 255, 90, 'klasy', 1.0, '1 failed');
    $verdict = mutationVerdict(['journal' => new Journal($journal->generated, $outcomes, [])]);

    expect($verdict->reasons)->toBe(['zabicia bez nieudanego testu i bez sklasyfikowanej przyczyny: 2 mutantów (a1, a2); nie dowodzą skutku mutacji'])
        ->and($verdict->notes)->toBe(['filtr testów w trybie „klasy” (za długi pełny filtr): 1 mutantów']);
});

// P1-b, obraz #73 w biegu szeregowym: mutant przeżywa wszystkie testy, a proces kończy się kodem 1 przez ostrzeżenia
// wrappera. Jedno takie zabicie obok prawdziwych odrzuca bieg.
it('kontrola ujemna: jedno fałszywe zabicie (same ostrzeżenia) obok prawdziwych odrzuca bieg z identyfikatorem', function (): void {
    $verdict = mutationVerdictWith(['a2' => new Outcome('a2', Status::Tested, 1, 30, 'testy', 20.4, '6 warnings, 107 passed (419 assertions)')]);

    expect($verdict->reasons)->toBe(['zabicia bez nieudanego testu i bez sklasyfikowanej przyczyny: 1 mutantów (a2); nie dowodzą skutku mutacji'])
        ->and($verdict->notes)->toBe([]);
});

it('zabicie bez nieudanego testu ze sklasyfikowaną przyczyną nie odrzuca biegu i jest w uwagach z przyczyną; pozostałe odrzucają', function (): void {
    $journal = mutationJournal(mutationRun()['mutants'], [], null);
    $verdict = mutationVerdict(['journal' => $journal, 'classified' => ['a2' => 'błąd krytyczny PHP: zmiana widoczności metody interfejsu', 'x9' => 'nie ma takiego mutanta']]);

    expect($verdict->reasons)->toBe(['zabicia bez nieudanego testu i bez sklasyfikowanej przyczyny: 2 mutantów (a1, b1); nie dowodzą skutku mutacji'])
        ->and($verdict->notes)->toBe(['zabicie bez nieudanego testu a2 sklasyfikowane: błąd krytyczny PHP: zmiana widoczności metody interfejsu']);
});

it('powód podaje do dziesięciu identyfikatorów posortowanych, a przy większej liczbie wielokropek', function (int $count, string $reason): void {
    $mutants = [];
    foreach (array_reverse(range(1, $count)) as $index) {
        $mutants[sprintf('a%02d', $index)] = ['src/A.php', Status::Untested];
    }
    $mutants['b1'] = ['src/B.php', Status::Tested];

    expect(mutationVerdict(['mutants' => $mutants])->reasons)->toBe([$reason]);
})->with([
    'dziesięć' => [10, 'UNTESTED poza rejestrem równoważnych: 10 mutantów (a01, a02, a03, a04, a05, a06, a07, a08, a09, a10)'],
    'jedenaście' => [11, 'UNTESTED poza rejestrem równoważnych: 11 mutantów (a01, a02, a03, a04, a05, a06, a07, a08, a09, a10, ...)'],
]);

it('błędy dziennika i listy „bez mutantów” odrzucają bieg', function (): void {
    $run = mutationRun();
    $journal = mutationJournal($run['mutants']);
    $verdict = Acceptance::evaluate(
        $run['targets'],
        'src',
        new Journal($journal->generated, $journal->outcomes, ['dziennik, wiersz 7: nieznane zdarzenie']),
        RunLog::parse(mutationLog($run['mutants'])),
        0,
        new ZeroMutants($run['zero'], ['bez-mutantow.json: wpis "x" bez uzasadnienia']),
        [],
        [],
        [],
    );

    expect($verdict->reasons)->toBe(['dziennik, wiersz 7: nieznane zdarzenie', 'bez-mutantow.json: wpis "x" bez uzasadnienia']);
});

it('zero wygenerowanych w logu to liczba odczytana: porównana z dziennikiem, a nie brak linii', function (): void {
    expect(mutationVerdict(['log' => "  0 Mutations for 0 Files created\n  Mutations: 3 tested"])->reasons)
        ->toBe(['niezgodna liczba wygenerowanych mutantów: log 0, dziennik 3']);
});

it('puste podsumowanie w logu to podsumowanie odczytane: sumy kategorii i lista żywych są porównane z dziennikiem', function (): void {
    $run = mutationRun();
    $mutants = ['a1' => ['src/A.php', Status::Untested], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]];
    $verdict = Acceptance::evaluate($run['targets'], 'src', mutationJournal($mutants), new RunLog(3, 2, [], [], null), 0, new ZeroMutants($run['zero'], []), ['a1'], [], []);

    expect($verdict->reasons)->toBe([
        'niezgodna suma kategorii tested: log 0, dziennik 2',
        'niezgodna suma kategorii untested: log 0, dziennik 1',
        'niezgodna lista żywych mutantów: log wymienia 0, dziennik ma 1',
    ]);
});

it('kategoria z logu, której nie ma w dzienniku, ma w dzienniku zero', function (): void {
    expect(mutationVerdict(['log' => "  3 Mutations for 2 Files created\n  Mutations: 1 untested, 3 tested"])->reasons)
        ->toBe(['niezgodna suma kategorii untested: log 1, dziennik 0']);
});

it('lista żywych mutantów nie zależy od kolejności w dzienniku ani w logu', function (array $mutants, string $listed): void {
    $log = "  3 Mutations for 2 Files created\n" . $listed . "\n  Mutations: 2 untested, 1 tested";

    expect(mutationVerdict(['mutants' => $mutants, 'log' => $log, 'registry' => ['a1', 'b1']])->reasons)->toBe([]);
})->with([
    'dziennik w innej kolejności' => [
        ['b1' => ['src/B.php', Status::Untested], 'a1' => ['src/A.php', Status::Untested], 'a2' => ['src/A.php', Status::Tested]],
        "   UNTESTED  ./src/A.php  > Line 3: PlusToMinus - ID: a1\n   UNTESTED  ./src/B.php  > Line 3: PlusToMinus - ID: b1",
    ],
    'log w innej kolejności' => [
        ['a1' => ['src/A.php', Status::Untested], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Untested]],
        "   UNTESTED  ./src/B.php  > Line 3: PlusToMinus - ID: b1\n   UNTESTED  ./src/A.php  > Line 3: PlusToMinus - ID: a1",
    ],
]);

// Identyfikatory szesnastkowe mogą wyglądać jak liczby („1e2” i „01e2” to 100): porządek list nie może od tego zależeć.
it('lista żywych mutantów o identyfikatorach równych liczbowo nie zależy od kolejności w dzienniku ani w logu', function (): void {
    $mutants = ['1e2' => ['src/A.php', Status::Untested], '01e2' => ['src/A.php', Status::Untested], 'b1' => ['src/B.php', Status::Tested]];
    $log = "  3 Mutations for 2 Files created\n"
        . "   UNTESTED  ./src/A.php  > Line 3: PlusToMinus - ID: 01e2\n   UNTESTED  ./src/A.php  > Line 3: PlusToMinus - ID: 1e2\n"
        . '  Mutations: 2 untested, 1 tested';

    expect(mutationVerdict(['mutants' => $mutants, 'log' => $log, 'registry' => ['1e2', '01e2']])->reasons)->toBe([]);
});

it('wpisy „bez mutantów”: cel z jednym mutantem i nieistniejący cel w zakresie odrzucają bieg, wpis spoza zakresu nie', function (array $override, array $reasons): void {
    expect(mutationVerdict($override)->reasons)->toBe($reasons);
})->with([
    'cel z jednym mutantem' => [
        ['zero' => ['src/C.php' => 'x', 'src/B.php' => 'y']],
        ['nieaktualny wpis w bez-mutantow.json: src/B.php ma 1 mutantów'],
    ],
    'zakres z ukośnikiem na końcu' => [
        ['scope' => 'src/', 'zero' => ['src/C.php' => 'x', 'src/D.php' => 'y']],
        ['nieaktualny wpis w bez-mutantow.json: nie ma celu src/D.php'],
    ],
    'katalog o nazwie zaczynającej się od zakresu' => [
        ['zero' => ['src/C.php' => 'x', 'srcinne/D.php' => 'y']],
        [],
    ],
    'nazwa liczbowa równa zakresowi tylko liczbowo' => [
        ['scope' => '1', 'zero' => ['src/C.php' => 'x', '1.0' => 'y']],
        [],
    ],
]);

it('identyfikator mutanta z samych cyfr (klucz int w tablicy PHP) jest liczony jak każdy inny', function (): void {
    $lines = [];
    foreach (['123' => 'src/A.php', 'b1' => 'src/B.php'] as $id => $file) {
        $lines[] = json_encode(['event' => 'generated', 'id' => (string) $id, 'file' => $file, 'mutator' => 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 'start_line' => 3, 'end_line' => 3]);
    }
    $lines[] = json_encode(['event' => 'result', 'id' => '123', 'status' => 'untested', 'exit_code' => 0, 'patterns' => 2, 'filter' => 'testy', 'duration' => 0.2, 'summary' => '2 passed']);
    $lines[] = json_encode(['event' => 'result', 'id' => 'b1', 'status' => 'tested', 'exit_code' => 1, 'patterns' => 2, 'filter' => 'testy', 'duration' => 0.2, 'summary' => '1 failed']);
    $log = "  2 Mutations for 2 Files created\n   UNTESTED  ./src/A.php  > Line 3: PlusToMinus - ID: 123\n  Mutations: 1 untested, 1 tested";

    expect(mutationVerdict(['journal' => Journal::parse(implode("\n", $lines), '/repo'), 'log' => $log])->reasons)
        ->toBe(['UNTESTED poza rejestrem równoważnych: 1 mutantów (123)']);
});

it('zabicia bez testu odrzucają bieg także obok mutanta, który przeżył', function (): void {
    $mutants = ['a1' => ['src/A.php', Status::Tested], 'a2' => ['src/A.php', Status::Untested], 'b1' => ['src/B.php', Status::Tested]];

    expect(mutationVerdict(['mutants' => $mutants, 'journal' => mutationJournal($mutants, [], null), 'registry' => ['a2']])->reasons)
        ->toBe(['zabicia bez nieudanego testu i bez sklasyfikowanej przyczyny: 2 mutantów (a1, b1); nie dowodzą skutku mutacji']);
});

/**
 * Bieg z mutationRun(), w którym wyniki wybranych mutantów zastąpiono podanymi.
 *
 * @param array<string, Outcome> $replaced
 */
function mutationVerdictWith(array $replaced): Verdict
{
    $journal = mutationJournal(mutationRun()['mutants']);

    return mutationVerdict(['journal' => new Journal($journal->generated, [...$journal->outcomes, ...$replaced], [])]);
}

it('kontrola ujemna: pusta linia podsumowania i podsumowanie bez „failed” przy dowolnym kodzie wyjścia to zabicia niezweryfikowane', function (): void {
    $verdict = mutationVerdictWith([
        'a1' => new Outcome('a1', Status::Tested, 255, 2, 'testy', 0.2, null),
        'a2' => new Outcome('a2', Status::Tested, 1, 2, 'testy', 0.2, ''),
        'b1' => new Outcome('b1', Status::Tested, 2, 2, 'testy', 0.2, '2 passed (2 assertions)'),
    ]);

    expect($verdict->reasons)->toBe(['zabicia bez nieudanego testu i bez sklasyfikowanej przyczyny: 3 mutantów (a1, a2, b1); nie dowodzą skutku mutacji']);
});

it('uwagi: filtr zwinięty do klas jest policzony dla każdego mutanta', function (): void {
    $notes = mutationVerdictWith([
        'a1' => new Outcome('a1', Status::Tested, 1, 90, 'klasy', 1.0, '1 failed'),
        'b1' => new Outcome('b1', Status::Tested, 1, 90, 'klasy', 1.0, '1 failed'),
    ])->notes;

    expect($notes)->toBe(['filtr testów w trybie „klasy” (za długi pełny filtr): 2 mutantów']);
});

it('liczniki łącznie i na cel są posortowane, a mutant spoza celów ma własny wiersz', function (): void {
    $mutants = ['a1' => ['src/A.php', Status::Untested], 'x1' => ['lib/X.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]];
    $targets = [new Target('src/B.php', 'b', ['B']), new Target('src/A.php', 'a', ['A']), new Target('src/C.php', 'c', ['C'])];
    $verdict = mutationVerdict(['mutants' => $mutants, 'targets' => $targets, 'registry' => ['a1']]);

    expect($verdict->counts)->toBe(['tested' => 2, 'untested' => 1])
        ->and($verdict->perTarget)->toBe([
            'lib/X.php' => ['wygenerowane' => 1, 'tested' => 1],
            'src/A.php' => ['wygenerowane' => 1, 'untested' => 1],
            'src/B.php' => ['wygenerowane' => 1, 'tested' => 1],
            'src/C.php' => ['wygenerowane' => 0],
        ]);
});
