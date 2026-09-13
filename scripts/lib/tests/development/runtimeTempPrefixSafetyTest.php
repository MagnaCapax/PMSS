<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/runtime/filesystem.php';

class RuntimeTempPrefixSafetyTest extends TestCase
{
    public function testInvalidPrefixesCannotCreateFilesOrAuthorizeCleanup(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-prefix-safety-');
        $marker = $this->pmssWriteFile($directory.'/keep', 'preserved');
        $commands = [];
        $messages = [];
        $logger = static function (string $message) use (&$messages): void {
            $messages[] = $message;
        };
        $runner = static function (string $description, string $command) use (&$commands): int {
            $commands[] = [$description, $command];
            return 0;
        };

        foreach (['', '../pmss-', '-pmss-', '.pmss-', 'pmss/path', 'pmss space',
            "pmss-\n", "pmss-\r\n", "\npmss-", "pmss-\nextra", "pmss-\0", "pmss-\t"] as $prefix) {
            $this->assertSame(false, \pmssPrivateTempPrefixIsSafe($prefix));
            $this->assertSame(null, \pmssCreatePrivateTempFile($prefix));
            $this->assertSame(null, \pmssPrivateTempDirRealpath($directory, $prefix, $logger));
            $this->assertSame(1, \pmssRemovePrivateTempDir($directory, $prefix, 'Cleanup fixture', $logger, $runner));
        }

        $this->assertSame([], $commands);
        $this->assertSame(array_fill(0, 24, '[WARN] Refusing temporary directory cleanup for unsafe prefix'), $messages);
        $this->assertSame('preserved', file_get_contents($marker));
    }

    public function testFinalNewlineCannotAuthorizeMatchingDirectoryCleanup(): void
    {
        // A matching on-disk name must not bypass prefix validation.
        $prefix = "pmss-prefix-newline-\n";
        $directory = $this->pmssMakeTempDir($prefix);
        $calls = 0;
        $logger = static function (string $message): void {};
        $runner = static function () use (&$calls): int {
            $calls++;
            return 0;
        };
        $this->assertSame(1, \pmssRemovePrivateTempDir($directory, $prefix, 'Cleanup fixture', $logger, $runner));
        $this->assertSame(0, $calls);
        $this->assertTrue(is_dir($directory));
    }

    public function testValidPrefixesPreserveCreationAndCleanupContracts(): void
    {
        foreach (['p', 'P', '7', 'pmss-normal-', 'pmss_mixed.7-'] as $prefix) {
            $this->assertSame(true, \pmssPrivateTempPrefixIsSafe($prefix));
            $file = \pmssCreatePrivateTempFile($prefix);
            $directory = null;
            try {
                $this->assertTrue(is_string($file) && is_file($file));
                $directory = \pmssCreatePrivateTempDir($prefix);
                $this->assertTrue(is_string($directory) && is_dir($directory));
                $this->assertSame(0700, fileperms($directory) & 0777);
                $this->assertSame(realpath($directory), \pmssPrivateTempDirRealpath($directory, $prefix));
                $calls = [];
                $runner = static function (string $description, string $command) use (&$calls): int {
                    $calls[] = [$description, $command];
                    return 23;
                };
                $this->assertSame(23, \pmssRemovePrivateTempDir($directory, $prefix, 'Cleanup fixture', null, $runner));
                $this->assertSame([['Cleanup fixture', 'rm -rf '.escapeshellarg(realpath($directory))]], $calls);
                $this->assertTrue(is_dir($directory));
            } finally {
                if (is_string($file)) {
                    unlink($file);
                }
                if (is_string($directory)) {
                    rmdir($directory);
                }
            }
        }
    }
}
