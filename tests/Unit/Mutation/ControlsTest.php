<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Controls;
use CityCouriers\Pc\Dev\Mutation\Generated;
use CityCouriers\Pc\Dev\Mutation\Journal;
use CityCouriers\Pc\Dev\Mutation\Outcome;
use CityCouriers\Pc\Dev\Mutation\RunLog;
use CityCouriers\Pc\Dev\Mutation\Status;

/**
 * Dziennik biegu kontrolnego: po jednym mutancie w klasie Przezycie i Zabicie.
 */
function mutationControlJournal(Outcome $survivor, Outcome $victim): Journal
{
    return new Journal(
        [
            $survivor->id => new Generated($survivor->id, Controls::SURVIVOR, 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 15, 15),
            $victim->id => new Generated($victim->id, Controls::VICTIM, 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 15, 15),
        ],
        [$survivor->id => $survivor, $victim->id => $victim],
        [],
    );
}

function mutationControlLog(): RunLog
{
    return RunLog::parse("  2 Mutations for 2 Files created\n  Mutations: 1 untested, 1 tested");
}

function mutationSurvived(): Outcome
{
    return new Outcome('5a', Status::Untested, 0, 2, 'testy', 0.3, '2 passed (2 assertions)');
}

function mutationKilled(): Outcome
{
    return new Outcome('6b', Status::Tested, 1, 2, 'testy', 0.3, '1 failed, 1 pending (1 assertions)');
}

it('kontrola przechodzi: mutant bez asercji przeżył, mutant z asercją zginął przez nieudany test', function (): void {
    expect(Controls::mutation(mutationControlJournal(mutationSurvived(), mutationKilled()), mutationControlLog(), 0))->toBe([]);
});

it('kontrola ujemna: fałszywe zabicie (proces mutanta padł bez testu) odrzuca bieg', function (): void {
    // Tak wyglądał każdy mutant z co najmniej dwoma wzorcami filtra przy wtyczce bez łatki na Windows (#60).
    $falseKill = new Outcome('5a', Status::Tested, 255, 2, 'testy', 0.2, null);
    $reasons = Controls::mutation(mutationControlJournal($falseKill, mutationKilled()), mutationControlLog(), 0);

    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toContain('kontrola zerowa: mutant 5a, który musi przeżyć')
        ->and($reasons[0])->toContain('ma status tested (kod 255, testy: nie uruchomiono)');
});

it('kontrola ujemna: mutant z asercją, który przeżył albo zginął bez nieudanego testu, odrzuca bieg', function (Outcome $victim): void {
    $reasons = Controls::mutation(mutationControlJournal(mutationSurvived(), $victim), mutationControlLog(), 0);

    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toContain('kontrola dodatnia: mutant 6b');
})->with([
    'przeżył (testy nie biegną)' => [new Outcome('6b', Status::Untested, 0, 2, 'testy', 0.2, '0 passed')],
    'zabity kodem 255 bez testu' => [new Outcome('6b', Status::Tested, 255, 2, 'testy', 0.2, null)],
    'timeout' => [new Outcome('6b', Status::Timeout, null, 2, 'testy', 9.0, null)],
]);

it('kontrola ujemna: kontrola z jednym wzorcem filtra nie sprawdza rozcinania filtra, więc odrzuca bieg', function (): void {
    $single = new Outcome('5a', Status::Untested, 0, 1, 'testy', 0.3, '1 passed');

    expect(implode("\n", Controls::mutation(mutationControlJournal($single, mutationKilled()), mutationControlLog(), 0)))
        ->toContain('ma 1 wzorzec filtra, a kontrola wymaga co najmniej dwóch');
});

it('kontrola ujemna: brak mutantów kontrolnych, brak wyniku albo nieukończony bieg odrzuca bieg', function (): void {
    $empty = Controls::mutation(new Journal([], [], []), RunLog::parse(''), 1);
    $journal = mutationControlJournal(mutationSurvived(), mutationKilled());
    $withoutResult = Controls::mutation(new Journal($journal->generated, [], ['dziennik: błąd']), mutationControlLog(), 0);

    expect($empty)->toBe([
        'kontrola zerowa: bieg kontrolny nie dokończył się (kod 1)',
        'kontrola zerowa: brak mutantów w ' . Controls::SURVIVOR,
        'kontrola zerowa: brak mutantów w ' . Controls::VICTIM,
    ])->and($withoutResult)->toBe([
        'kontrola zerowa: dziennik: błąd',
        'kontrola zerowa: mutant w ' . Controls::SURVIVOR . ' bez wyniku',
        'kontrola zerowa: mutant w ' . Controls::VICTIM . ' bez wyniku',
    ]);
});

it('kontrola tożsamościowa: przechodzi tylko kod 0 z samymi zaliczonymi testami w liczbie zestawu początkowego biegu', function (): void {
    expect(Controls::identity(0, RunLog::parse("  \e[90mTests:\e[39m    376 passed (1309 assertions)\n"), 376))->toBe([])
        ->and(Controls::identity(0, RunLog::parse('  Tests:    1 passed (1 assertion)'), 1))->toBe([]);
});

// P1-a: fail-closed dla kodu 0. Brak, ucięcie i zero testów to nie „zielono”, tak samo każda kategoria poza „passed”.
it('kontrola ujemna tożsamościowej: wynik inny niż kod 0 z samymi zaliczonymi testami odrzuca bieg z kodem i podsumowaniem (#73)', function (?int $code, string $log, string $shown): void {
    expect(Controls::identity($code, RunLog::parse($log), 376))->toBe([sprintf(
        'kontrola tożsamościowa: pełny zestaw testów pod wrapperem plików wtyczki, bez mutacji, kończy się kodem %s (%s), a wymagany jest kod 0 i same zaliczone testy; zabicia mutantów pokrytych tymi testami są niewiarygodne (#73)',
        $code ?? 'nieznanym',
        $shown,
    )]);
})->with([
    'ostrzeżenia wrappera (#73)' => [1, '  Tests:    48 warnings, 328 passed (1200 assertions)', '48 warnings, 328 passed (1200 assertions)'],
    'kod 0 bez podsumowania' => [0, '', 'brak podsumowania'],
    'kod 0 bez testów' => [0, '   INFO  No tests found.', 'brak podsumowania'],
    'kod 0 i zero zaliczonych' => [0, '  Tests:    0 passed (0 assertions)', '0 passed (0 assertions)'],
    'kod 0 i ucięty log' => [0, '  Tests:    376 pass', '376 pass'],
    'kod 0 i liczba testów poza zakresem int' => [0, '  Tests:    9223372036854775808 passed (1 assertion)', '9223372036854775808 passed (1 assertion)'],
    'kod 0 i ostrzeżenia' => [0, '  Tests:    1 warnings, 375 passed (1309 assertions)', '1 warnings, 375 passed (1309 assertions)'],
    'kod 0 i test pominięty' => [0, '  Tests:    1 skipped, 375 passed (1309 assertions)', '1 skipped, 375 passed (1309 assertions)'],
    'kod 0 i test ryzykowny' => [0, '  Tests:    1 risky, 375 passed (1309 assertions)', '1 risky, 375 passed (1309 assertions)'],
    'kod 0 i test niekompletny' => [0, '  Tests:    1 incomplete, 375 passed (1309 assertions)', '1 incomplete, 375 passed (1309 assertions)'],
    'kod 1 mimo samych zaliczonych' => [1, '  Tests:    376 passed (1309 assertions)', '376 passed (1309 assertions)'],
    'kod nieznany' => [null, '  Tests:    376 passed (1309 assertions)', '376 passed (1309 assertions)'],
]);

it('kontrola ujemna tożsamościowej: inna liczba testów niż w zestawie początkowym biegu (mniej, więcej, nieznana) odrzuca bieg', function (int $passed, ?int $expected, string $shown): void {
    expect(Controls::identity(0, RunLog::parse(sprintf('  Tests:    %d passed (999 assertions)', $passed)), $expected))->toBe([sprintf(
        'kontrola tożsamościowa: pod wrapperem przeszło %d testów, a zestaw początkowy biegu mutacji miał ich %s; kontrola nie objęła tego samego zestawu',
        $passed,
        $shown,
    )]);
})->with([
    'mniej (część zestawu nie wystartowała)' => [375, 376, '376'],
    'więcej' => [377, 376, '376'],
    'liczba zestawu początkowego nieznana' => [376, null, 'nieznaną liczbę'],
]);

it('kontrola zerowa: kod niezerowy albo nieznany odrzuca bieg mimo pełnego podsumowania, a kod 0 z odczytanym podsumowaniem nie', function (): void {
    $journal = mutationControlJournal(mutationSurvived(), mutationKilled());

    expect(Controls::mutation($journal, mutationControlLog(), 1))->toBe(['kontrola zerowa: bieg kontrolny nie dokończył się (kod 1)'])
        ->and(Controls::mutation($journal, mutationControlLog(), null))->toBe(['kontrola zerowa: bieg kontrolny nie dokończył się (kod nieznany)'])
        ->and(Controls::mutation($journal, new RunLog(2, 2, [], [], null), 0))->toBe([]);
});

it('kontrola zerowa: komunikat podaje kod i podsumowanie testów mutanta, który musiał przeżyć', function (): void {
    $killed = new Outcome('5a', Status::Tested, 1, 2, 'testy', 0.2, '1 failed, 1 passed (2 assertions)');

    expect(Controls::mutation(mutationControlJournal($killed, mutationKilled()), mutationControlLog(), 0))->toBe([
        'kontrola zerowa: mutant 5a, który musi przeżyć (testy bez asercji), ma status tested (kod 1, testy: 1 failed, 1 passed (2 assertions)); wynik biegu byłby fałszywy',
    ]);
});

it('kontrola dodatnia: komunikat podaje status i podsumowanie testów mutanta, który musiał zginąć', function (): void {
    $survived = new Outcome('6b', Status::Untested, 0, 2, 'testy', 0.2, '2 passed (2 assertions)');

    expect(Controls::mutation(mutationControlJournal(mutationSurvived(), $survived), mutationControlLog(), 0))->toBe([
        'kontrola dodatnia: mutant 6b, który musi zginąć przez nieudany test, ma status untested (testy: 2 passed (2 assertions))',
    ]);
});
