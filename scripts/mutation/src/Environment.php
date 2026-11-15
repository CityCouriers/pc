<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

use Closure;
use Pest\Mutate\MutationTest;
use ReflectionClass;

/**
 * Stan repo i środowiska do manifestu: SHA bazowy, liczba zmian w drzewie roboczym (bez nazw plików, bo manifest trafia
 * do publicznego repo), wersje narzędzi, skróty konfiguracji i warunki ważności biegu (aktywna łatka wtyczki bez dryfu,
 * sterownik pokrycia).
 */
final class Environment
{
    private const array PACKAGES = ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage', 'symfony/process'];

    private const array CONFIGS = ['composer.json', 'composer.lock', 'phpunit.xml.dist', 'scripts/mutation/patch/MutationTest.php', 'scripts/mutation/bez-mutantow.json'];

    private const string PATCH = 'scripts/mutation/patch/MutationTest.php';

    private const string UPSTREAM = 'vendor/pestphp/pest-plugin-mutate/src/MutationTest.php';

    /**
     * @param (Closure(string): (string|false))|null $version wersja rozszerzenia PHP albo false, gdy nie jest załadowane
     *        (domyślnie phpversion); szew dla testów
     *
     * @return array<string, mixed>
     */
    public static function collect(string $root, ?Closure $version = null): array
    {
        [, $sha] = PestRun::capture(['git', 'rev-parse', 'HEAD'], $root);
        [, $status] = PestRun::capture(['git', 'status', '--porcelain', '--untracked-files=all'], $root);
        $hashes = [];
        foreach (self::CONFIGS as $file) {
            // Plik nieistniejący albo nieczytelny nie ma skrótu (a nie skrót pustej treści).
            $path = $root . '/' . $file;
            $content = is_file($path) ? @file_get_contents($path) : false;
            $hashes[$file] = $content === false ? null : Inventory::hash($content);
        }

        return [
            'sha' => trim($sha),
            // Liczba niepustych linii `git status --porcelain` (jedna na zmieniony plik), bez nazw plików.
            'zmiany_w_drzewie' => count(array_filter(array_map('rtrim', explode("\n", $status)), static fn(string $line): bool => $line !== '')),
            'php' => PHP_VERSION . (PHP_ZTS ? ' ZTS' : ' NTS'),
            'system' => PHP_OS_FAMILY,
            'sterownik_pokrycia' => self::coverageDriver($version ?? phpversion(...)),
            'wersje' => self::versions($root),
            'skroty' => $hashes,
        ];
    }

    /**
     * Warunki ważności biegu niezależne od wyniku mutantów.
     *
     * @param array<string, mixed> $environment wynik collect()
     * @param array{string, string}|null $patch plik, z którego załadowano Pest\Mutate\MutationTest, i skrót oryginału
     *        wtyczki zapisany w łatce (domyślnie z bieżącego procesu); szew dla testów
     *
     * @return list<string>
     */
    public static function problems(string $root, array $environment, ?array $patch = null): array
    {
        $problems = [];
        if (($environment['sha'] ?? '') === '') {
            $problems[] = 'nie udało się ustalić SHA bazowego (git rev-parse HEAD)';
        }
        if (($environment['zmiany_w_drzewie'] ?? 0) !== 0) {
            $problems[] = 'drzewo robocze ma niezatwierdzone zmiany: wynik nie jest dowodem dla SHA bazowego';
        }
        $class = new ReflectionClass(MutationTest::class);
        // Klasa wtyczki bez łatki nie ma stałej UPSTREAM_SHA256: getConstant() daje wtedy false zamiast błędu krytycznego.
        [$loaded, $patchedFrom] = $patch ?? [(string) $class->getFileName(), $class->getConstant('UPSTREAM_SHA256')];
        if (Journal::relative($loaded, $root) !== self::PATCH) {
            $problems[] = 'łatka wtyczki nieaktywna: Pest\Mutate\MutationTest ładuje się z ' . Journal::relative($loaded, $root) . ' (composer dump-autoload)';
        }
        $upstream = $root . '/' . self::UPSTREAM;
        if (!is_file($upstream) || hash_file('sha256', $upstream) !== $patchedFrom) {
            $problems[] = 'dryf wtyczki: oryginał ' . self::UPSTREAM . ' różni się od wersji, z której zrobiono łatkę (przenieś zmiany i zaktualizuj UPSTREAM_SHA256)';
        }

        return $problems;
    }

    /**
     * Ustawienia PHP (-d) dla biegu z pokryciem albo null, gdy nie ma sterownika pokrycia.
     *
     * @param (Closure(string): (string|false))|null $version jak w collect()
     *
     * @return list<string>|null
     */
    public static function coverageIni(string $directory, ?Closure $version = null): ?array
    {
        $version ??= phpversion(...);
        if ($version('pcov') !== false) {
            return ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $directory, '-d', 'xdebug.mode=off'];
        }

        return $version('xdebug') !== false ? ['-d', 'xdebug.mode=coverage'] : null;
    }

    /**
     * @param Closure(string): (string|false) $version
     */
    public static function coverageDriver(Closure $version): string
    {
        $pcov = $version('pcov');
        if ($pcov !== false) {
            return 'pcov ' . $pcov;
        }
        $xdebug = $version('xdebug');

        return $xdebug !== false ? 'xdebug ' . $xdebug : 'brak';
    }

    /**
     * @return array<string, string>
     */
    public static function versions(string $root): array
    {
        $installed = json_decode((string) @file_get_contents($root . '/vendor/composer/installed.json'), true);
        $versions = array_fill_keys(self::PACKAGES, 'nieznana');
        $packages = is_array($installed) && is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        foreach ($packages as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && isset($versions[$package['name']]) && is_string($package['version'] ?? null)) {
                $versions[$package['name']] = $package['version'];
            }
        }

        return $versions;
    }
}
