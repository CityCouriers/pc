<?php

declare(strict_types=1);

use CityCouriers\Pc\Process\EmptyInput;
use CityCouriers\Pc\Scope\SubProcess;
use CityCouriers\Pc\Tests\Support\Child;
use CityCouriers\Pc\Tests\Support\Warnings;

/**
 * Proces potomny dostaje pusty stdin, nie stdin Pesta (terminal): czytanie z niego kończy się od razu zerem bajtów.
 * Stdin to rurka zamknięta zaraz po uruchomieniu, nie urządzenie przez protokół `file` (ten podmienia wtyczka Pest Mutate, #73).
 */
it('deskryptor stdin to rurka do odczytu przez proces potomny, bez protokołu file', function (): void {
    expect((new EmptyInput())->descriptor())->toBe(['pipe', 'r']);
});

it('close zamyka rurkę stdin i usuwa ją z tablicy, a pozostałe rurki zostawia', function (): void {
    $stdin = fopen('php://memory', 'r+') ?: throw new RuntimeException('php://memory');
    $stdout = fopen('php://memory', 'r+') ?: throw new RuntimeException('php://memory');
    $stderr = fopen('php://memory', 'r+') ?: throw new RuntimeException('php://memory');
    $pipes = [0 => $stdin, 1 => $stdout, 2 => $stderr];

    (new EmptyInput())->close($pipes);

    expect(array_keys($pipes))->toBe([1, 2])
        ->and($pipes[1])->toBe($stdout)
        ->and($pipes[2])->toBe($stderr)
        ->and(is_resource($stdin))->toBeFalse()
        ->and(is_resource($stdout))->toBeTrue()
        ->and(is_resource($stderr))->toBeTrue();
});

it('close przy braku rurki stdin (nieudane proc_open) niczego nie robi i nie zgłasza błędu', function (): void {
    $empty = [];
    (new EmptyInput())->close($empty);

    $stdout = fopen('php://memory', 'r+') ?: throw new RuntimeException('php://memory');
    $outputOnly = [1 => $stdout];
    (new EmptyInput())->close($outputOnly);

    expect($empty)->toBe([])
        ->and($outputOnly)->toBe([1 => $stdout])
        ->and(is_resource($stdout))->toBeTrue();
});

it('SubProcess (kod pakietu): proces czytający stdin nie czeka i dostaje 0 bajtów', function (): void {
    $started = microtime(true);
    $output = (new SubProcess())->run(Child::stdinReader());

    expect($output)->not->toBeNull()
        ->and($output?->stdout)->toBe('0')
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});

it('SubProcess (kod pakietu): program, którego nie da się uruchomić, to null, bez ostrzeżeń i bez zamykania rurki', function (): void {
    [$output, $warnings] = Warnings::collect(static fn(): mixed => (new SubProcess())->run(['pc-nie-ma-takiego-programu', 'a']));

    expect($output)->toBeNull()->and($warnings)->toBe([]);
});

it('Child (testy): proces czytający stdin nie czeka i dostaje 0 bajtów', function (): void {
    [$code, $stdout] = Child::capture(Child::stdinReader());

    expect($code)->toBe(0)->and($stdout)->toBe('0');
});

it('Child::open zwraca tylko rurki wyjścia, stdin jest już zamknięty', function (): void {
    $pipes = [];
    $process = Child::open(Child::stdinReader(), $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Nie udało się uruchomić PHP.');
    }
    expect(array_keys($pipes))->toBe([1, 2]);

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0)->and($stdout)->toBe('0');
});
