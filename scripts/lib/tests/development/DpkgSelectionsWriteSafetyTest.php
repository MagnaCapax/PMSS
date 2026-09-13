<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise actual filesystem short writes without running package commands. */
class DpkgSelectionsWriteSafetyTest extends TestCase
{
    public function testStagingRejectsIncompleteWritesAndRemovesTemporaryFiles(): void
    {
        $lines = ["alpha\tinstall", "beta\thold"];
        $length = strlen(implode(PHP_EOL, $lines).PHP_EOL);
        foreach ([0, 1, $length - 1] as $limit) {
            $result = $this->stageWithFileSizeLimit($lines, $limit);
            $this->assertSame(null, $result['path']);
            $this->assertSame([], $result['files']);
            $this->assertSame(
                ['[ERROR] Unable to write temporary file for sanitized dpkg selections baseline'],
                $result['logs']
            );
        }
    }

    public function testCompleteWritesPreservePayloadAndPrivateMode(): void
    {
        foreach ([[], ["alpha\tinstall"], ["alpha\tinstall", "beta\thold"]] as $lines) {
            $payload = implode(PHP_EOL, $lines).PHP_EOL;
            $result = $this->stageWithFileSizeLimit($lines, strlen($payload));
            $this->assertTrue(is_string($result['path']));
            $this->assertSame([$result['path']], $result['files']);
            $this->assertSame($payload, $result['payload']);
            $this->assertSame(0600, $result['mode']);
            $this->assertSame([], $result['logs']);
        }
    }

    /** Confine resource limits and signal handling to a disposable PHP child. */
    private function stageWithFileSizeLimit(array $lines, int $limit): array
    {
        if (!function_exists('posix_setrlimit') || !function_exists('pcntl_signal')) {
            throw new SkipTest('File-size fault injection requires POSIX and PCNTL');
        }
        $directory = $this->pmssMakeTempDir('pmss-dpkg-write-', 0700);
        $script = 'function logMessage(string $message, array $context = []): void { $GLOBALS["logs"][] = $message; }'
            .'require '.var_export(dirname(__DIR__, 2).'/update/dpkgSelections.php', true).';'
            .'$GLOBALS["logs"] = [];'
            .'if (!pcntl_signal(SIGXFSZ, SIG_IGN) || !posix_setrlimit(POSIX_RLIMIT_FSIZE, '.$limit.', '.$limit.')) { exit(2); }'
            .'$path = pmssWriteSanitisedDpkgSelectionsTempFile('.var_export($lines, true).');'
            .'echo json_encode(["path" => $path, "files" => glob(sys_get_temp_dir()."/pmss-selections-*"),'
            .'"payload" => $path === null ? null : file_get_contents($path),'
            .'"mode" => $path === null ? null : fileperms($path) & 0777, "logs" => $GLOBALS["logs"]]);';
        return $this->pmssRunInlinePhpJson($script, ['TMPDIR' => $directory]);
    }
}
