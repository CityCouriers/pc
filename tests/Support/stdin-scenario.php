<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\StdinScenarios;

/**
 * Uruchamiany przez `ParentInput::callInChild()` jako osobny proces php: scenariusz z argumentu, stdin to dane od rodzica.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $argv */
echo StdinScenarios::run($argv[1], array_slice($argv, 2));
