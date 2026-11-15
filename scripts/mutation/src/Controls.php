<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Ocena kontroli biegu mutacji (scripts/mutation/control):
 * - zerowa: mutant klasy `Przezycie` (testy bez asercji o wyniku) musi przeżyć; zabity = fałszywe zabicie;
 * - dodatnia: mutant klasy `Zabicie` (testy z asercją) musi zginąć z raportem nieudanego testu; przeżycie = testy w
 *   procesie mutanta nie biegną;
 * - tożsamościowa: pełny zestaw testów pod wrapperem plików wtyczki, bez mutacji, musi przejść w całości i mieć tyle
 *   testów, ile zestaw początkowy biegu mutacji (#73).
 * Obie klasy kontrolne mają po dwa testy, więc filtr procesu mutanta ma co najmniej dwa wzorce.
 */
final class Controls
{
    public const string SURVIVOR = 'scripts/mutation/control/src/Przezycie.php';

    public const string VICTIM = 'scripts/mutation/control/src/Zabicie.php';

    /**
     * @return list<string> powody odrzucenia (puste: kontrola przeszła)
     */
    public static function mutation(Journal $journal, RunLog $log, ?int $exitCode): array
    {
        $reasons = [];
        if ($exitCode !== 0 || $log->summary === null) {
            $reasons[] = sprintf('kontrola zerowa: bieg kontrolny nie dokończył się (kod %s)', $exitCode ?? 'nieznany');
        }
        foreach ($journal->errors as $error) {
            $reasons[] = 'kontrola zerowa: ' . $error;
        }
        $byFile = [self::SURVIVOR => [], self::VICTIM => []];
        foreach ($journal->generated as $id => $mutant) {
            $byFile[$mutant->file][] = $journal->outcomes[$id] ?? null;
        }
        foreach ($byFile as $file => $outcomes) {
            if ($outcomes === []) {
                $reasons[] = sprintf('kontrola zerowa: brak mutantów w %s', $file);
            }
            foreach ($outcomes as $outcome) {
                $problem = self::problem($file, $outcome);
                if ($problem !== null) {
                    $reasons[] = $problem;
                }
            }
        }

        return $reasons;
    }

    /**
     * Fail-closed: przechodzi wyłącznie kod 0 z podsumowaniem „N passed (M assertions)” i tą samą liczbą testów co zestaw
     * początkowy biegu mutacji. Brak albo ucięcie podsumowania, zero testów, ostrzeżenia, testy pominięte, ryzykowne
     * i niekompletne oraz inna liczba testów (np. mniej, bo część zestawu nie wystartowała) odrzucają bieg.
     *
     * @param RunLog $run log kontroli tożsamościowej
     * @param int|null $expected liczba testów zestawu początkowego biegu mutacji; null: nieznana
     *
     * @return list<string>
     */
    public static function identity(?int $exitCode, RunLog $run, ?int $expected): array
    {
        $passed = $run->passed();
        if ($exitCode !== 0 || $passed === null) {
            return [sprintf(
                'kontrola tożsamościowa: pełny zestaw testów pod wrapperem plików wtyczki, bez mutacji, kończy się kodem %s (%s), a wymagany jest kod 0 i same zaliczone testy; zabicia mutantów pokrytych tymi testami są niewiarygodne (#73)',
                $exitCode ?? 'nieznanym',
                $run->tests ?? 'brak podsumowania',
            )];
        }
        if ($passed !== $expected) {
            return [sprintf(
                'kontrola tożsamościowa: pod wrapperem przeszło %d testów, a zestaw początkowy biegu mutacji miał ich %s; kontrola nie objęła tego samego zestawu',
                $passed,
                $expected ?? 'nieznaną liczbę',
            )];
        }

        return [];
    }

    private static function problem(string $file, ?Outcome $outcome): ?string
    {
        if ($outcome === null) {
            return sprintf('kontrola zerowa: mutant w %s bez wyniku', $file);
        }
        if ($outcome->patterns < 2) {
            return sprintf('kontrola zerowa: mutant %s ma %d wzorzec filtra, a kontrola wymaga co najmniej dwóch', $outcome->id, $outcome->patterns);
        }
        if ($file === self::SURVIVOR && $outcome->status !== Status::Untested) {
            return sprintf(
                'kontrola zerowa: mutant %s, który musi przeżyć (testy bez asercji), ma status %s (kod %s, testy: %s); wynik biegu byłby fałszywy',
                $outcome->id,
                $outcome->status->value,
                $outcome->exitCode ?? '-',
                $outcome->summary ?? 'nie uruchomiono',
            );
        }
        $killedByTest = $outcome->status === Status::Tested && str_contains((string) $outcome->summary, 'failed');
        if ($file === self::VICTIM && !$killedByTest) {
            return sprintf(
                'kontrola dodatnia: mutant %s, który musi zginąć przez nieudany test, ma status %s (testy: %s)',
                $outcome->id,
                $outcome->status->value,
                $outcome->summary ?? 'nie uruchomiono',
            );
        }

        return null;
    }
}
