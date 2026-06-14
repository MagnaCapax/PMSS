<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/runtime.php';

class RuntimeLockSafetyTest extends TestCase
{
    public function testLockFileHandleMatchesCurrentPath(): void
    {
        $path = $this->pmssMakeTempFile('pmss-runtime-lock-');
        $handle = @fopen($path, 'c+');
        $this->assertTrue(is_resource($handle), 'Expected lock fixture handle');

        try {
            $this->assertTrue(\pmssLockFileHandleMatchesPath($handle, $path));
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }
    }

    public function testLockFileHandleRejectsReplacedPath(): void
    {
        $path = $this->pmssMakeTempFile('pmss-runtime-lock-');
        $handle = @fopen($path, 'c+');
        $this->assertTrue(is_resource($handle), 'Expected lock fixture handle');

        try {
            @unlink($path);
            $this->pmssWriteFile($path, "replacement\n");

            $this->assertFalse(\pmssLockFileHandleMatchesPath($handle, $path));
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }
    }
}
