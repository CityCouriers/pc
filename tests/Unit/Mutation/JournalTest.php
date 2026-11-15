<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Journal;
use CityCouriers\Pc\Dev\Mutation\Status;

/**
 * @param array<string, mixed> $fields
 */
function mutationJournalLine(array $fields): string
{
    return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function mutationGeneratedLine(string $id, string $file = 'C:\\repo\\src\\A.php'): string
{
    return mutationJournalLine(['event' => 'generated', 'id' => $id, 'file' => $file, 'mutator' => 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 'start_line' => 7, 'end_line' => 9]);
}

function mutationResultLine(string $id, string $status = 'tested'): string
{
    return mutationJournalLine(['event' => 'result', 'id' => $id, 'status' => $status, 'exit_code' => 1, 'patterns' => 2, 'filter' => 'testy', 'duration' => 0.5, 'summary' => '1 failed']);
}

it('czyta wygenerowane mutanty i wyniki, ścieżki względem repo z ukośnikami', function (): void {
    $journal = Journal::parse(implode("\n", [mutationGeneratedLine('aa01'), mutationResultLine('aa01'), '']), 'c:/repo/');

    expect($journal->errors)->toBe([])
        ->and($journal->generated['aa01']->file)->toBe('src/A.php')
        ->and($journal->generated['aa01']->mutatorName())->toBe('PlusToMinus')
        ->and($journal->generated['aa01']->startLine)->toBe(7)
        ->and($journal->generated['aa01']->endLine)->toBe(9)
        ->and($journal->outcomes['aa01']->status)->toBe(Status::Tested)
        ->and($journal->outcomes['aa01']->exitCode)->toBe(1)
        ->and($journal->outcomes['aa01']->patterns)->toBe(2)
        ->and($journal->outcomes['aa01']->filter)->toBe('testy')
        ->and($journal->outcomes['aa01']->duration)->toBe(0.5)
        ->and($journal->outcomes['aa01']->summary)->toBe('1 failed');
});

it('ścieżka spoza repo zostaje bezwzględna', function (): void {
    expect(Journal::relative('D:\\inne\\src\\A.php', 'C:\\repo'))->toBe('D:/inne/src/A.php')
        ->and(Journal::relative('C:\\Repo\\src\\A.php', 'c:\\repo'))->toBe('src/A.php');
});

/**
 * Wiersz `generated` z podmienionymi polami; null usuwa pole.
 *
 * @param array<string, mixed> $changes
 */
function mutationGeneratedWith(array $changes): string
{
    $fields = array_merge(['event' => 'generated', 'id' => 'ab', 'file' => 'C:\\repo\\src\\A.php', 'mutator' => 'M', 'start_line' => 7, 'end_line' => 9], $changes);

    return mutationJournalLine(array_filter($fields, static fn(mixed $value): bool => $value !== null));
}

/**
 * Wiersz `generated` i po nim wiersz `result` z podmienionymi polami.
 *
 * @param array<string, mixed> $changes
 */
function mutationResultWith(array $changes): string
{
    $fields = array_merge(['event' => 'result', 'id' => 'ab', 'status' => 'tested', 'exit_code' => 1, 'patterns' => 2, 'filter' => 'testy', 'duration' => 1, 'summary' => '1 failed'], $changes);

    return mutationGeneratedLine('ab') . "\n" . mutationJournalLine($fields);
}

it('kontrola ujemna: każdy uszkodzony wiersz dziennika jest błędem z numerem wiersza i identyfikatorem, nie pominięciem', function (string $content, string $error): void {
    expect(Journal::parse($content, 'C:\\repo')->errors)->toBe([$error]);
})->with([
    'nie JSON' => ['{zepsute', 'dziennik, wiersz 1: to nie jest obiekt JSON'],
    'brak identyfikatora' => [mutationJournalLine(['event' => 'generated']), 'dziennik, wiersz 1: brak poprawnego identyfikatora mutanta'],
    'nieznane zdarzenie' => [mutationJournalLine(['event' => 'inne', 'id' => 'ab']), 'dziennik, wiersz 1: nieznane zdarzenie'],
    'mutant dwa razy' => [mutationGeneratedLine('ab') . "\n" . mutationGeneratedLine('ab'), 'dziennik, wiersz 2: mutant ab wygenerowany dwa razy'],
    'dwa wyniki' => [mutationGeneratedLine('ab') . "\n" . mutationResultLine('ab') . "\n" . mutationResultLine('ab'), 'dziennik, wiersz 3: mutant ab ma dwa wyniki'],
    'wynik bez wygenerowania' => [mutationResultLine('ab'), 'dziennik: wynik mutanta ab bez wpisu o wygenerowaniu'],
    'nieznany status' => [mutationGeneratedLine('ab') . "\n" . mutationResultLine('ab', 'zabity'), 'dziennik, wiersz 2: niepełny wynik mutanta ab'],
    'niepełne wygenerowanie' => [mutationJournalLine(['event' => 'generated', 'id' => 'ab', 'file' => 'x']), 'dziennik, wiersz 1: niepełny wpis o wygenerowaniu mutanta ab'],
    'bez pliku' => [mutationGeneratedWith(['file' => null]), 'dziennik, wiersz 1: niepełny wpis o wygenerowaniu mutanta ab'],
    'plik liczbą' => [mutationGeneratedWith(['file' => 5]), 'dziennik, wiersz 1: niepełny wpis o wygenerowaniu mutanta ab'],
    'pusty plik' => [mutationGeneratedWith(['file' => '']), 'dziennik, wiersz 1: niepełny wpis o wygenerowaniu mutanta ab'],
    'mutator liczbą' => [mutationGeneratedWith(['mutator' => 5]), 'dziennik, wiersz 1: niepełny wpis o wygenerowaniu mutanta ab'],
    'pusty mutator' => [mutationGeneratedWith(['mutator' => '']), 'dziennik, wiersz 1: niepełny wpis o wygenerowaniu mutanta ab'],
    'linia tekstem' => [mutationGeneratedWith(['start_line' => '7']), 'dziennik, wiersz 1: niepełny wpis o wygenerowaniu mutanta ab'],
    'linia ułamkiem' => [mutationGeneratedWith(['end_line' => 9.5]), 'dziennik, wiersz 1: niepełny wpis o wygenerowaniu mutanta ab'],
    'kod wyjścia fałszem' => [mutationResultWith(['exit_code' => false]), 'dziennik, wiersz 2: niepełny wynik mutanta ab'],
    'kod wyjścia tekstem' => [mutationResultWith(['exit_code' => '1']), 'dziennik, wiersz 2: niepełny wynik mutanta ab'],
    'podsumowanie fałszem' => [mutationResultWith(['summary' => false]), 'dziennik, wiersz 2: niepełny wynik mutanta ab'],
]);

it('puste i białe wiersze dziennika są pomijane bez przerywania czytania; wynik bez kodu i bez podsumowania (przerwany proces) jest poprawny', function (): void {
    $journal = Journal::parse(implode("\n", [mutationGeneratedLine('ab'), '  ', '', mutationJournalLine(['event' => 'result', 'id' => 'ab', 'status' => 'timeout', 'exit_code' => null, 'patterns' => 0, 'filter' => '', 'duration' => 3, 'summary' => null])]), 'C:\\repo');

    expect($journal->errors)->toBe([])
        ->and($journal->outcomes['ab']->exitCode)->toBeNull()
        ->and($journal->outcomes['ab']->summary)->toBeNull()
        ->and($journal->outcomes['ab']->duration)->toBe(3.0);
});
