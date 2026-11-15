<?php

declare(strict_types=1);

use CityCouriers\Pc\Engine\EngineException;
use CityCouriers\Pc\Engine\ProcessGit;
use CityCouriers\Pc\Tests\Support\Child;
use CityCouriers\Pc\Tests\Support\GitRepo;
use CityCouriers\Pc\Tests\Support\GitStub;
use CityCouriers\Pc\Tests\Support\ParentInput;
use CityCouriers\Pc\Tests\Support\StdinScenarios;
use CityCouriers\Pc\Tests\Support\Warnings;

/**
 * Katalog poza repozytorium (tymczasowy, usuwany przez wywołującego).
 */
function processGitPlainDirectory(): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-pg-' . bin2hex(random_bytes(6));
    mkdir($path);

    return $path;
}

it('isRepository: true w repozytorium z katalogiem roboczym, false poza repozytorium i w repozytorium bez katalogu roboczego', function (): void {
    $repo = new GitRepo();
    $plain = processGitPlainDirectory();
    $bare = new GitRepo(processGitPlainDirectory());
    $bare->git('init', '--quiet', '--bare');
    try {
        expect((new ProcessGit($repo->path))->isRepository())->toBeTrue()
            ->and((new ProcessGit($plain))->isRepository())->toBeFalse()
            ->and((new ProcessGit($bare->path))->isRepository())->toBeFalse();
    } finally {
        $repo->remove();
        $bare->remove();
        rmdir($plain);
    }
});

it('stagedFiles: dodane, zmienione i zmienione nazwy, bez usuniętych; ścieżki ze spacją i bez pustych wpisów', function (): void {
    $repo = new GitRepo();
    $repo->commit(['zmieniany.txt' => "a\n", 'usuwany.txt' => "b\n"]);
    $repo->write('zmieniany.txt', "a2\n");
    $repo->write('nowy plik.txt', "n\n");
    $repo->write('dir/b.txt', "d\n");
    $repo->stage('zmieniany.txt', 'nowy plik.txt', 'dir/b.txt');
    $repo->git('rm', '--quiet', 'usuwany.txt');
    try {
        expect((new ProcessGit($repo->path))->stagedFiles())->toBe(['dir/b.txt', 'nowy plik.txt', 'zmieniany.txt']);

        $repo->git('reset', '--quiet');
        expect((new ProcessGit($repo->path))->stagedFiles())->toBe([]);
    } finally {
        $repo->remove();
    }
});

it('stagedFiles przy błędzie gita (uszkodzony indeks) to EngineException z komunikatem', function (): void {
    $repo = new GitRepo();
    $repo->commit(['a.txt' => "a\n"]);
    file_put_contents($repo->path . '/.git/index', 'to nie jest indeks');
    try {
        expect(static fn(): array => (new ProcessGit($repo->path))->stagedFiles())->toThrow(EngineException::class, 'Nie da się odczytać plików ze stage\'a (git diff --cached zakończył się błędem).');
    } finally {
        $repo->remove();
    }
});

it('resolveCommit: pełny OID z gałęzi, HEAD i tagu adnotowanego (commit, nie obiekt tagu)', function (): void {
    $repo = new GitRepo();
    $first = $repo->commit(['a.txt' => "a\n"]);
    $second = $repo->commit(['b.txt' => "b\n"]);
    $repo->git('tag', '--annotate', '-m', 'wydanie', 'v1', $first);
    $git = new ProcessGit($repo->path);
    try {
        expect($git->resolveCommit('HEAD'))->toBe($second)
            ->and($git->resolveCommit('main'))->toBe($second)
            ->and($git->resolveCommit('v1'))->toBe($first)
            ->and($git->resolveCommit('HEAD~1'))->toBe($first)
            ->and($git->resolveCommit($second))->toBe($second);
    } finally {
        $repo->remove();
    }
});

it('resolveCommit: null dla pustej rewizji, rewizji jak opcja, nieistniejącej, nie-commita i listy rodziców', function (string $revision): void {
    $repo = new GitRepo();
    $repo->commit(['a.txt' => "a\n"]);
    $repo->commit(['b.txt' => "b\n"]);
    try {
        expect((new ProcessGit($repo->path))->resolveCommit($revision))->toBeNull();
    } finally {
        $repo->remove();
    }
})->with([
    'pusta' => [''],
    'opcja' => ['--all'],
    'opcja z myślnikiem na końcu' => ['-x-'],
    'nieistniejąca' => ['nie-ma-takiej-rewizji'],
    'drzewo' => ['HEAD^{tree}'],
    'plik' => ['HEAD:a.txt'],
    'rodzice (lista, nie pojedyncza rewizja)' => ['HEAD^@'],
    'negacja (git daje kod 0 i ^oid)' => ['^HEAD'],
]);

it('scopePaths: PC_HOOK_SCOPE=all wymusza pełny zestaw (null), a bez zmiennej zakres jest zawężony', function (): void {
    $repo = new GitRepo();
    $repo->history([['README.md' => "r\n"], ['src/Kod.php' => "<?php\n"]]);
    $repo->write('scripts/quality/scope-rules.json', (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/quality/scope-rules.json'));
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');
    $previous = getenv('PC_HOOK_SCOPE');
    try {
        putenv('PC_HOOK_SCOPE');
        $narrow = (new ProcessGit($repo->path))->scopePaths();
        putenv('PC_HOOK_SCOPE=all');
        $forced = (new ProcessGit($repo->path))->scopePaths();

        expect($narrow)->toContain('docs/a.md')
            ->and($forced)->toBeNull();
    } finally {
        putenv($previous === false ? 'PC_HOOK_SCOPE' : 'PC_HOOK_SCOPE=' . $previous);
        $repo->remove();
    }
});

it('git, którego nie da się uruchomić, to EngineException, a nie wynik fałszywy', function (): void {
    $repo = new GitRepo();
    $previous = getenv('PATH');
    try {
        putenv('PATH=');
        [$result, $warnings] = Warnings::collect(static function () use ($repo): string {
            try {
                (new ProcessGit($repo->path))->isRepository();
            } catch (EngineException $exception) {
                return $exception->getMessage();
            }

            return 'bez wyjątku';
        });
    } finally {
        putenv($previous === false ? 'PATH' : 'PATH=' . $previous);
        $repo->remove();
    }

    expect($result)->toBe('Nie udało się uruchomić gita.')
        ->and($warnings)->not->toBe([]);
});

it('resolveCommit: pusta rewizja i rewizja jak opcja są odrzucane bez uruchamiania gita', function (string $revision): void {
    $repo = new GitRepo();
    $previous = getenv('PATH');
    try {
        putenv('PATH=');
        [$result, $warnings] = Warnings::collect(static fn(): ?string => (new ProcessGit($repo->path))->resolveCommit($revision));
    } finally {
        putenv($previous === false ? 'PATH' : 'PATH=' . $previous);
        $repo->remove();
    }

    expect($result)->toBeNull()->and($warnings)->toBe([]);
})->with([
    'pusta' => [''],
    'opcja' => ['--all'],
    'opcja z myślnikiem na końcu' => ['-x-'],
]);

it('stagedFiles: puste wpisy w środku wyjścia gita są pomijane, a klucze listy układane od zera', function (): void {
    $stub = new GitStub('echo "a\0\0b\0"; exit(0);');
    try {
        expect((new ProcessGit($stub->directory()))->stagedFiles())->toBe(['a', 'b']);
    } finally {
        $stub->remove();
    }
});

it('resolveCommit przekazuje gitowi dokładnie: rev-parse --verify --quiet <rewizja>^{commit}', function (): void {
    $stub = new GitStub('if ($args === ["rev-parse", "--verify", "--quiet", "HEAD^{commit}"]) { echo str_repeat("c", 40); exit(0); } exit(1);');
    try {
        expect((new ProcessGit($stub->directory()))->resolveCommit('HEAD'))->toBe(str_repeat('c', 40));
    } finally {
        $stub->remove();
    }
});

/**
 * `stagedFiles()` w repozytorium z hookiem `core.fsmonitor` czytającym stdin: hook dziedziczy stdin gita, więc długość
 * odczytanych danych pokazuje, jaki stdin dostał git od `ProcessGit`. Zwraca pliki ze stage'a (JSON) i długość odczytu hooka.
 *
 * @return array{string, ?string}
 */
function processGitHookStdin(?string $parentInput): array
{
    $repo = new GitRepo();
    $repo->commit(['a.txt' => "a\n"]);
    $repo->write('b.txt', "b\n");
    $repo->stage('b.txt');
    $mark = $repo->path . '/hook.mark';
    $hook = $repo->path . '/hook.php';
    file_put_contents($hook, "<?php\n\$argv = [0, 'echo strlen((string) stream_get_contents(STDIN));'];\nob_start();\n" . Child::STDIN_GUARD . "\nfile_put_contents((string) getenv('PC_HOOK_MARK'), ob_get_clean());\necho \"token\\0\";\n");
    $repo->git('config', 'core.fsmonitor', '"' . str_replace('\\', '/', PHP_BINARY) . '" "' . str_replace('\\', '/', $hook) . '"');
    $previous = getenv('PC_HOOK_MARK');
    try {
        putenv('PC_HOOK_MARK=' . $mark);
        $files = $parentInput === null
            ? StdinScenarios::run(StdinScenarios::STAGED_FILES, [$repo->path])
            : ParentInput::call($parentInput, StdinScenarios::STAGED_FILES, $repo->path);

        return [$files, is_file($mark) ? (string) file_get_contents($mark) : null];
    } finally {
        putenv($previous === false ? 'PC_HOOK_MARK' : 'PC_HOOK_MARK=' . $previous);
        $repo->remove();
    }
}

it('git dostaje EOF na stdin: hook fsmonitor czytający stdin kończy się od razu, a ProcessGit nie wisi', function (): void {
    expect(processGitHookStdin(null))->toBe(['["b.txt"]', '0']);
});

it('git dostaje pusty stdin także wtedy, gdy stdin rodzica ma dane (hook fsmonitor czyta 0 bajtów)', function (): void {
    expect(processGitHookStdin("dane\n"))->toBe(['["b.txt"]', '0']);
});

it('git dostaje pusty stdin także wtedy, gdy stdin rodzica ma dane (scenariusz w osobnym procesie php)', function (): void {
    $repo = new GitRepo();
    $repo->commit(['a.txt' => "a\n"]);
    try {
        expect(ParentInput::callInChild("dane\n", StdinScenarios::STAGED_FILES, $repo->path))->toBe('[]');
    } finally {
        $repo->remove();
    }
});
