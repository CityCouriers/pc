<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use CityCouriers\Pc\Engine\ProcessGit;
use CityCouriers\Pc\Scope\SubProcess;
use InvalidArgumentException;

/**
 * Scenariusze sprawdzające, jaki stdin dostaje proces potomny od kodu pakietu, gdy stdin procesu nadrzędnego ma dane.
 * Uruchamia je `ParentInput`: w procesie Pesta po podmianie stdin (tam działa wrapper plików wtyczki mutacji, więc zabijają
 * mutanty) albo w osobnym procesie php (worker `--parallel`, gdzie podmiana stdin zepsułaby worker).
 */
final class StdinScenarios
{
    public const string SUB_PROCESS = 'sub-process';

    public const string STAGED_FILES = 'staged-files';

    public const string READ_STDIN = 'read-stdin';

    /**
     * @param list<string> $arguments
     */
    public static function run(string $scenario, array $arguments): string
    {
        return match ($scenario) {
            self::SUB_PROCESS => (string) (new SubProcess())->run(Child::stdinReader())?->stdout,
            self::STAGED_FILES => json_encode((new ProcessGit($arguments[0]))->stagedFiles(), JSON_THROW_ON_ERROR),
            self::READ_STDIN => (string) file_get_contents('php://stdin'),
            default => throw new InvalidArgumentException('Nieznany scenariusz: ' . $scenario),
        };
    }
}
