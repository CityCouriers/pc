<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use RuntimeException;

/**
 * Atrapa programu `git` w katalogu na początku PATH: skrypt PHP dostaje argumenty gita (bez `-C <katalog>`) i może
 * zwrócić dowolne wyjście. Pozwala sprawdzić dokładne argumenty i nietypowe wyjście, których prawdziwy git nie daje.
 *
 * Windows: `proc_open` z tablicą uruchamia program bez powłoki i znajduje tylko `git.exe`, więc atrapą jest kopia
 * `php.exe`; po `-C` PHP traktuje następny argument jako plik skryptu, dlatego katalog dla `ProcessGit` to ścieżka
 * skryptu (`directory()`). POSIX: wykonywalny skrypt `git` wywołujący PHP.
 */
final class GitStub
{
    private string $directory;

    private string $script;

    private string $previousPath;

    /**
     * @param string $body kod PHP; `$args` to argumenty gita bez `-C <katalog>`, kod wyjścia zwraca `exit`
     */
    public function __construct(string $body)
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-gs-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->script = $this->directory . DIRECTORY_SEPARATOR . 'stub.php';
        file_put_contents($this->script, "<?php\n\$args = array_slice(\$argv, 1);\nif ((\$args[0] ?? '') === '-C') {\n    \$args = array_slice(\$args, 2);\n}\n" . $body . "\n");
        if (PHP_OS_FAMILY === 'Windows') {
            copy(PHP_BINARY, $this->directory . '\\git.exe');
        } else {
            file_put_contents($this->directory . '/git', "#!/bin/sh\nexec '" . PHP_BINARY . "' '" . $this->script . "' \"\$@\"\n");
            chmod($this->directory . '/git', 0o755);
        }
        $this->previousPath = (string) getenv('PATH');
        putenv('PATH=' . $this->directory . PATH_SEPARATOR . $this->previousPath);
    }

    /**
     * Katalog do przekazania `ProcessGit` (Windows: ścieżka skryptu atrapy, zob. opis klasy).
     */
    public function directory(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? $this->script : $this->directory;
    }

    public function remove(): void
    {
        putenv('PATH=' . $this->previousPath);
        foreach ([$this->script, $this->directory . '/git', $this->directory . '\\git.exe'] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException('Nie można usunąć atrapy gita: ' . $file);
            }
        }
        rmdir($this->directory);
    }
}
