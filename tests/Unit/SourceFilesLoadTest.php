<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P2.2 (P2.1 findings 3.3): every PHP file under src/ must load. Doctrine's annotation driver require_once()s every
 * file under the package's mapped src/ when Concrete builds its entity metadata, so one file that can't load (on
 * Neuron 4, a class extending a Neuron 3 class) returns 500 on every page of the site, guests included.
 *
 * The files are loaded in a child process (tests/Support/load_source_files.php), because a missing parent class is
 * a fatal error that can't be caught. First all files in one process; if that fails, each file on its own, so the
 * failure names every file that can't load. Legacy src/Toolkits/ is included: Doctrine loads it too.
 */
final class SourceFilesLoadTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $src = dirname(__DIR__, 2) . '/src';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $files
     */
    private static function load(array $files): string
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Support/load_source_files.php');
        foreach ($files as $file) {
            $command .= ' ' . escapeshellarg($file);
        }

        return trim((string) shell_exec($command . ' 2>&1'));
    }

    public function testThereAreSourceFilesToLoad(): void
    {
        $files = self::sourceFiles();

        $this->assertGreaterThan(30, count($files));
        $this->assertContains(dirname(__DIR__, 2) . '/src/DatabaseChatHistory.php', $files);
        $this->assertContains(dirname(__DIR__, 2) . '/src/Toolkits/PagesToolkit.php', $files);
    }

    public function testEverySourceFileLoads(): void
    {
        $files = self::sourceFiles();
        $output = self::load($files);
        if ($output === 'LOADED ' . count($files)) {
            $this->addToAssertionCount(1);

            return;
        }

        $failures = [];
        foreach ($files as $file) {
            $single = self::load([$file]);
            if ($single !== 'LOADED 1') {
                $failures[] = $single;
            }
        }

        $this->fail("These files under src/ can't be loaded, so Doctrine's scan would return 500 on every page:\n"
            . ($failures !== [] ? implode("\n", $failures) : $output));
    }
}
