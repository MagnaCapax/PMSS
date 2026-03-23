<?php
namespace {
    if (!function_exists('pmssTestInstallRunUserStepShim')) {
        function pmssTestInstallRunUserStepShim(string $mode = 'noop'): void
        {
            $GLOBALS['PMSS_TEST_RUNUSERSTEP_MODE'] = $mode;
            if (function_exists('runUserStep')) {
                return;
            }
            function runUserStep(string $user, string $description, string $command): int
            {
                $mode = (string) ($GLOBALS['PMSS_TEST_RUNUSERSTEP_MODE'] ?? 'noop');
                if ($mode === 'profile') {
                    $GLOBALS['PMSS_PROFILE'][] = ['description' => $description, 'command' => $command];
                } elseif ($mode === 'last') {
                    $GLOBALS['PMSS_TEST_RUNUSERSTEP_LAST'] = ['user' => $user, 'description' => $description, 'command' => $command];
                }
                return 0;
            }
        }
    }
}

namespace PMSS\Tests {

class SkipTest extends \Exception {}

abstract class TestCase
{
    /**
     * @var array<int, array{0:bool|string,1:string,2:?string}>
     */
    private $results = [];

    public function run(): array
    {
        $methods = array_filter(get_class_methods($this), static function ($method) {
            return strpos($method, 'test') === 0;
        });
        foreach ($methods as $method) {
            try {
                if (method_exists($this, 'setUp')) {
                    $this->setUp();
                }
                $this->$method();
                if (method_exists($this, 'tearDown')) {
                    $this->tearDown();
                }
                $this->results[] = [true, $method, null];
            } catch (SkipTest $e) {
                $this->results[] = ['skip', $method, $e->getMessage()];
            } catch (\AssertionError $e) {
                $this->results[] = [false, $method, $e->getMessage()];
            } catch (\Throwable $e) {
                $this->results[] = [false, $method, $e->getMessage()];
            }
        }
        return $this->results;
    }

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new \AssertionError($message !== '' ? $message : 'Assertion failed: expected true');
        }
    }

    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            $msg = $message !== '' ? $message : sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true));
            throw new \AssertionError($msg);
        }
    }

    protected function assertMatches(string $pattern, string $value, string $message = ''): void
    {
        if (!preg_match($pattern, $value)) {
            $msg = $message !== '' ? $message : sprintf('Value %s does not match pattern %s', $value, $pattern);
            throw new \AssertionError($msg);
        }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (strpos($haystack, $needle) === false) {
            $msg = $message !== '' ? $message : sprintf('Expected string to contain %s, but it did not', var_export($needle, true));
            throw new \AssertionError($msg);
        }
    }

    protected function pmssAssertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (strpos($haystack, $needle) !== false) {
            throw new \AssertionError($message !== '' ? $message : sprintf('Expected string to not contain %s, but it did', var_export($needle, true)));
        }
    }

    protected function assertStringContainsAllStrings(array $needles, string $haystack, string $messagePrefix = ''): void
    {
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $haystack, $messagePrefix !== '' ? $messagePrefix.$needle : '');
        }
    }

    protected function assertOrderedStrings(array $needles, string $haystack, string $missingPrefix = '', string $orderPrefix = ''): void
    {
        $offset = -1;
        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle);
            $this->assertTrue($position !== false, $missingPrefix !== '' ? $missingPrefix.$needle : 'Missing substring: '.$needle);
            $this->assertTrue($position > $offset, $orderPrefix !== '' ? $orderPrefix.$needle : 'String order changed at: '.$needle);
            $offset = $position;
        }
    }

    protected function fail(string $message = ''): void
    {
        throw new \AssertionError($message !== '' ? $message : 'Test failed');
    }

    protected function isSandbox(): bool
    {
        if (getenv('PMSS_SANDBOX') === '1') return true;
        // Not root or no systemd bus → assume sandbox/CI
        $notRoot = function_exists('posix_geteuid') ? (posix_geteuid() !== 0) : true;
        $noSystemd = !is_dir('/run/systemd/system');
        return $notRoot || $noSystemd;
    }

    protected function skipIfSandbox(string $reason = 'sandboxed environment'): void
    {
        if ($this->isSandbox()) {
            throw new SkipTest($reason);
        }
    }

    /** Create a unique temporary directory for hermetic tests. */
    protected function pmssMakeTempDir(string $prefix, int $mode = 0755): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
        @mkdir($path, $mode, true);
        return $path;
    }

    /** Remove a temporary directory tree created during tests. */
    protected function pmssRemoveTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }
            @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /** Restore a previous environment variable value captured with getenv(). */
    protected function pmssRestoreEnv(string $key, $value, bool $unsetEmptyString = false): void
    {
        if ($value === false || $value === null || ($unsetEmptyString && $value === '')) {
            putenv($key);
            return;
        }

        putenv($key.'='.$value);
    }

    /**
     * Apply temporary environment variable overrides for the duration of a callback.
     *
     * @param array<string, string|null> $values
     */
    protected function pmssWithEnv(array $values, callable $callback): void
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            $this->pmssRestoreEnv($key, $value);
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                $this->pmssRestoreEnv($key, $value);
            }
        }
    }

    /** Temporarily prepend a directory to PATH for a callback. */
    protected function pmssWithPathPrefix(string $prefix, callable $callback): void
    {
        $originalPath = getenv('PATH');
        $path = $prefix.(($originalPath !== false && $originalPath !== '') ? ':'.$originalPath : '');

        $this->pmssWithEnv(['PATH' => $path], $callback);
    }

    /** Skip the current test when symlinks are unavailable. */
    protected function pmssRequireSymlinkSupport(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }
    }

    /** Create a symlink or skip the current test when the platform blocks it. */
    protected function pmssCreateSymlinkOrSkip(string $target, string $link): void
    {
        $this->pmssRequireSymlinkSupport();
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink creation failed');
        }
    }

    /** Resolve a repository-relative path from the development test tree. */
    protected function pmssRepoPath(string $relativePath): string
    {
        return dirname(__DIR__, 4).'/'.ltrim($relativePath, '/');
    }

    /** Read a repository file and fail the test when it is unavailable. */
    protected function pmssReadRepoFile(string $relativePath): string
    {
        $path = $this->pmssRepoPath($relativePath);
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    /** Read a repository file and assert that it contains a substring. */
    protected function pmssAssertRepoFileContainsString(string $relativePath, string $needle, string $message = ''): void
    {
        $this->assertStringContainsString($needle, $this->pmssReadRepoFile($relativePath), $message);
    }

    /** Read a repository file and assert that it omits a substring. */
    protected function pmssAssertRepoFileNotContainsString(string $relativePath, string $needle, string $message = ''): void
    {
        $this->pmssAssertStringNotContainsString($needle, $this->pmssReadRepoFile($relativePath), $message);
    }

    /** Read a repository file and assert that it contains multiple substrings. */
    protected function pmssAssertRepoFileContainsAllStrings(string $relativePath, array $needles, string $messagePrefix = ''): void
    {
        $this->assertStringContainsAllStrings($needles, $this->pmssReadRepoFile($relativePath), $messagePrefix);
    }

    /** Read a repository file and assert ordered substrings. */
    protected function pmssAssertRepoFileContainsOrderedStrings(string $relativePath, array $needles, string $missingPrefix = '', string $orderPrefix = ''): void
    {
        $this->assertOrderedStrings($needles, $this->pmssReadRepoFile($relativePath), $missingPrefix, $orderPrefix);
    }

    /** Read a repository file and assert that it omits multiple substrings. */
    protected function pmssAssertRepoFileNotContainsStrings(string $relativePath, array $needles, string $messagePrefix = ''): void
    {
        foreach ($needles as $needle) {
            $this->pmssAssertRepoFileNotContainsString($relativePath, $needle, $messagePrefix !== '' ? $messagePrefix.$needle : '');
        }
    }

    /** Read a repository file and assert a fixed substring count. */
    protected function pmssAssertRepoFileSubstringCount(string $relativePath, string $needle, int $expectedCount, string $message = ''): void
    {
        $this->assertEquals($expectedCount, substr_count($this->pmssReadRepoFile($relativePath), $needle), $message);
    }
}
}
