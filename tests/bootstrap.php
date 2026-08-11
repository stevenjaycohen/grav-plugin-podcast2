<?php

declare(strict_types=1);

use Grav\Common\Grav;

const PODCAST2_TEST_SOURCE = __DIR__ . '/..';

$gravRootInput = getenv('PODCAST2_TEST_GRAV_ROOT');
if (!is_string($gravRootInput) || $gravRootInput === '') {
    fwrite(STDERR, "PODCAST2_TEST_GRAV_ROOT must point to the Grav installation.\n");
    exit(2);
}

$gravRoot = realpath($gravRootInput);
if (
    $gravRoot === false
    || !is_file($gravRoot . '/system/defines.php')
    || !is_file($gravRoot . '/system/router.php')
    || !is_file($gravRoot . '/vendor/autoload.php')
    || !is_dir($gravRoot . '/user/pages')
) {
    fwrite(STDERR, "PODCAST2_TEST_GRAV_ROOT is not a usable Grav installation.\n");
    exit(2);
}
define('PODCAST2_TEST_GRAV_ROOT', $gravRoot);

$runtime = sys_get_temp_dir() . '/podcast2-tests-' . bin2hex(random_bytes(6));
foreach ([$runtime, $runtime . '/cache', $runtime . '/logs', $runtime . '/tmp'] as $directory) {
    if (!mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create test directory {$directory}");
    }
}
define('PODCAST2_TEST_RUNTIME', $runtime);

putenv('GRAV_CACHE_PATH=' . $runtime . '/cache');
putenv('GRAV_LOG_PATH=' . $runtime . '/logs');
putenv('GRAV_TMP_PATH=' . $runtime . '/tmp');
putenv('GRAV_DOTENV_DISABLE=1');

$originalDirectory = getcwd();
chdir(PODCAST2_TEST_GRAV_ROOT);
$loader = require PODCAST2_TEST_GRAV_ROOT . '/vendor/autoload.php';
$grav = Grav::instance(['loader' => $loader]);
$grav->setup();
require_once PODCAST2_TEST_GRAV_ROOT . '/user/plugins/get-id3/get-id3.php';
require_once PODCAST2_TEST_GRAV_ROOT . '/user/plugins/podcast2/podcast2.php';
chdir($originalDirectory);

/**
 * Minimal dependency-free test harness.
 */
final class Podcast2TestHarness
{
    private int $passed = 0;
    private int $failed = 0;

    /**
     * Run one isolated test case.
     */
    public function test(string $name, callable $test): void
    {
        try {
            $test();
            $this->passed++;
            echo "PASS {$name}\n";
        } catch (Throwable $error) {
            $this->failed++;
            fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
            if ($name === 'Grav HTTP integration and feed isolation') {
                $serverLog = PODCAST2_TEST_RUNTIME . '/server.log';
                if (is_file($serverLog)) {
                    $lines = file($serverLog, FILE_IGNORE_NEW_LINES) ?: [];
                    $tail = implode("\n", array_slice($lines, -20));
                    $tail = preg_replace('/\?[^\s]*/', '?[redacted]', $tail);
                    if ($tail !== '') {
                        fwrite(STDERR, "Server log tail:\n{$tail}\n");
                    }
                }
            }
        } finally {
            Grav::instance()['messages']->clear();
        }
    }

    /**
     * Assert that a condition is true.
     */
    public static function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Assert strict equality and include both values on failure.
     */
    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
            );
        }
    }

    /**
     * Print the summary and return the process exit code.
     */
    public function finish(): int
    {
        echo "\n{$this->passed} passed, {$this->failed} failed\n";

        return $this->failed === 0 ? 0 : 1;
    }
}

/**
 * Remove only a test-owned path below the system temporary directory or fixtures.
 */
function podcast2RemoveTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException("Cannot remove {$path}");
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entryPath = $entry->getPathname();
        if ($entry->isLink() || $entry->isFile()) {
            if (!unlink($entryPath)) {
                throw new RuntimeException("Cannot remove {$entryPath}");
            }
        } elseif (!rmdir($entryPath)) {
            throw new RuntimeException("Cannot remove {$entryPath}");
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException("Cannot remove {$path}");
    }
}

/**
 * Write a small PCM WAV file without requiring a binary fixture in the repository.
 */
function podcast2WriteWave(string $path, int $seconds = 1): void
{
    $sampleRate = 8000;
    $samples = $sampleRate * $seconds;
    $dataSize = $samples * 2;
    $header = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16);
    $header .= 'data' . pack('V', $dataSize);

    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException("Cannot create {$path}");
    }
    try {
        if (fwrite($handle, $header) !== strlen($header)) {
            throw new RuntimeException("Cannot write WAV header to {$path}");
        }
        $chunk = str_repeat("\0", 8192);
        $remaining = $dataSize;
        while ($remaining > 0) {
            $length = min($remaining, strlen($chunk));
            if (fwrite($handle, $chunk, $length) !== $length) {
                throw new RuntimeException("Cannot write WAV data to {$path}");
            }
            $remaining -= $length;
        }
    } finally {
        fclose($handle);
    }
}

/**
 * Capture Git's complete tracked and untracked working-tree state.
 */
function podcast2GitState(string $repository): string
{
    $command = ['git', '-C', $repository, 'status', '--porcelain=v1', '-z', '--untracked-files=all'];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException("Cannot inspect Git state for {$repository}");
    }
    $stdout = '';
    while (!feof($pipes[1])) {
        $stdout .= fread($pipes[1], 8192);
    }
    $stderr = '';
    while (!feof($pipes[2])) {
        $stderr .= fread($pipes[2], 8192);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $stderr !== '') {
        throw new RuntimeException("Git state inspection failed for {$repository}: " . trim($stderr));
    }

    return (string) $stdout;
}
