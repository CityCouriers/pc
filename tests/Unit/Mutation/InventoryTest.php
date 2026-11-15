<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Inventory;
use CityCouriers\Pc\Dev\Mutation\PestRun;
use CityCouriers\Pc\Dev\Mutation\Target;
use CityCouriers\Pc\Tests\Support\GitRepo;
use CityCouriers\Pc\Tests\Support\SpyStream;
use CityCouriers\Pc\Tests\Support\Warnings;

it('skrót pliku nie zależy od końców linii (ten sam na Windows i Linuksie)', function (): void {
    expect(Inventory::hash("a\r\nb\rc\n"))->toBe(Inventory::hash("a\nb\nc\n"))
        ->and(Inventory::hash("a\n"))->not->toBe(Inventory::hash("b\n"));
});

it('klasy, interfejsy, cechy i wyliczenia z pełną przestrzenią nazw; bez `::class` i klas anonimowych', function (): void {
    $source = <<<'PHP'
        <?php
        namespace Pc\Przyklad;
        final class A { public function b(): string { return A::class . (new class {})::class; } }
        interface I {}
        trait T {}
        enum E: string { case X = 'x'; }
        PHP;

    expect(Inventory::classes($source))->toBe(['Pc\Przyklad\A', 'Pc\Przyklad\I', 'Pc\Przyklad\T', 'Pc\Przyklad\E'])
        ->and(Inventory::classes("<?php\nfunction f(): void {}\n"))->toBe([]);
});

it('cele z src: wszystkie pliki PHP, posortowane, ze ścieżką względną i skrótem', function (): void {
    $root = dirname(__DIR__, 3);
    $targets = Inventory::scan($root, 'src');
    $paths = array_map(static fn(Target $target): string => $target->path, $targets);
    $sorted = $paths;
    sort($sorted);
    $version = array_combine($paths, $targets)['src/Version.php'];

    expect($paths)->toBe($sorted)
        ->and(count($paths))->toBe(count(glob($root . '/src/{,*/,*/*/}*.php', GLOB_BRACE) ?: []))
        ->and($version->classes)->toBe(['CityCouriers\Pc\Version'])
        ->and($version->sha256)->toBe(Inventory::hash((string) file_get_contents($root . '/src/Version.php')))
        ->and(Inventory::scan($root, 'src/Version.php'))->toEqual([$version]);
});

it('kontrola ujemna: nieistniejący cel to błąd, nie pusta lista', function (): void {
    Inventory::scan(dirname(__DIR__, 3), 'src/NieMaTakiegoKatalogu');
})->throws(RuntimeException::class, 'Nie ma katalogu celów mutacji: src/NieMaTakiegoKatalogu');

/**
 * Dowiązanie do katalogu o nazwie pliku PHP. Na Windows połączenie katalogów (junction), bo dowiązanie symboliczne
 * wymaga uprawnień administratora albo trybu dewelopera.
 */
function inventoryDirectoryLink(string $target, string $link): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        if (!symlink($target, $link)) {
            throw new RuntimeException('Nie można utworzyć dowiązania: ' . $link);
        }

        return;
    }
    $command = sprintf("New-Item -ItemType Junction -Path '%s' -Target '%s' | Out-Null", $link, $target);
    [$code, $output] = PestRun::capture(['powershell', '-NoProfile', '-NonInteractive', '-Command', $command], dirname($link));
    if ($code !== 0) {
        throw new RuntimeException('Nie można utworzyć połączenia katalogów: ' . $output);
    }
}

it('cele z katalogu: tylko pliki .php (bez innych plików i dowiązań do katalogów), także pusty, przy katalogu repo z końcowym separatorem', function (): void {
    $repo = new GitRepo();
    $link = $repo->path . '/src/katalog.php';
    try {
        $repo->write('src/B.php', "<?php\nclass B {}\n");
        $repo->write('src/Sub/A.php', "<?php\nnamespace Sub;\ninterface A {}\n");
        $repo->write('src/opis.md', "# B\n");
        $repo->write('src/pusty.php', '');
        mkdir($repo->path . '/src/prawdziwy');
        inventoryDirectoryLink($repo->path . '/src/prawdziwy', $link);

        $targets = Inventory::scan($repo->path . '/', 'src');

        expect(array_map(static fn(Target $target): array => [$target->path, $target->classes], $targets))
            ->toBe([['src/B.php', ['B']], ['src/Sub/A.php', ['Sub\A']], ['src/pusty.php', []]])
            ->and($targets[2]->sha256)->toBe(Inventory::hash(''));
    } finally {
        if (is_dir($link)) {
            PHP_OS_FAMILY === 'Windows' ? rmdir($link) : unlink($link);
        }
        $repo->remove();
    }
});

it('nazwy klas mimo komentarzy przed nazwą, w przestrzeni globalnej i jednoczłonowej; `::class` przed nazwą to nie deklaracja', function (): void {
    expect(Inventory::classes("<?php\nnamespace /* a */ Pc;\nclass /* b */ A {}\ninterface /** c */ I {}\n"))->toBe(['Pc\A', 'Pc\I'])
        ->and(Inventory::classes("<?php\nclass A {}\n"))->toBe(['A'])
        // Kod niepoprawny, ale token_get_all go przyjmuje: stała `::class`, po której stoi nazwa, nie deklaruje klasy.
        ->and(Inventory::classes('<?php echo A::class B;'))->toBe([]);
});

it('kontrola ujemna: cel widoczny jako plik, którego odczyt się nie udaje, to błąd z pełną ścieżką, nie pusty skrót', function (): void {
    SpyStream::register();
    SpyStream::$readFails = true;
    try {
        [$message] = Warnings::collect(static function (): ?string {
            try {
                Inventory::scan('pcspy://repo', 'A.php');
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }

            return null;
        });

        expect($message)->toBe('Nie można odczytać celu mutacji: pcspy://repo' . DIRECTORY_SEPARATOR . 'A.php');
    } finally {
        SpyStream::unregister();
    }
});
