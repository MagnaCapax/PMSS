<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise the root launch branch without changing account or host state. */
class AccountPathFailureTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-account-failure-']; }

    public function testChildStatusAndSanitizedFirstStderrBytesReachCaller(): void
    {
        $result = $this->runAccount('printf "quota\\nfull\\t\\033[31m\\000" >&2; exit 23');
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['timedOut']);
        $this->assertSame('stage=child rc=23 stderr=quota full [31m', $result['failure']);
        $this->assertSame([true, 0, 0600, '/'], $result['descriptor']);
        $this->assertFalse($result['captureOpen']);
        $this->assertTrue($result['rootBranch']);
        $this->assertSame("-u alice --\n", $result['runuser']);
    }

    public function testNoisyStderrDoesNotBlockLargeStandardInput(): void
    {
        // More than a pipe buffer is written before the child starts reading stdin.
        $command = 'head -c 262144 /dev/zero | tr "\\000" E >&2; cat > received; exit 7';
        // The runner starts in /; use an absolute fixture path for the account output.
        $command = str_replace('> received', '> '.escapeshellarg($this->tempDir.'/received'), $command);
        $result = $this->runAccount($command, str_repeat('private-input', 20000), 5);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['timedOut']);
        $this->assertSame('stage=child rc=7 stderr='.str_repeat('E', 300), $result['failure']);
        $this->assertSame(str_repeat('private-input', 20000), file_get_contents($this->tempDir.'/received'));
    }

    public function testSuccessClearsStaleFailureAndDiscardsStandardOutput(): void
    {
        foreach ([0, 3] as $timeout) {
            $result = $this->runAccount('cat >/dev/null; printf private-output; printf warning >&2', 'private-input', $timeout);
            $this->assertTrue($result['ok']);
            $this->assertFalse($result['timedOut']);
            $this->assertSame('', $result['failure']);
            $this->assertFalse($result['captureOpen']);
        }
    }

    public function testPreLaunchFailuresRemainDistinctAndCloseCapture(): void
    {
        foreach (['validation', 'stderr', 'unlink', 'launch'] as $fault) {
            $result = $this->runAccount('exit 99', null, 0, $fault);
            $this->assertFalse($result['ok']);
            $this->assertFalse($result['timedOut']);
            $this->assertSame('stage='.($fault === 'unlink' ? 'stderr' : $fault).' rc=not-started', $result['failure']);
            $this->assertSame('', $result['runuser']);
            $this->assertFalse($result['captureOpen']);
        }
    }

    public function testSilentChildFailureKeepsExitStatusInBothTimeoutModes(): void
    {
        foreach ([0, 3] as $timeout) {
            $result = $this->runAccount('exit 19', null, $timeout);
            $this->assertFalse($result['ok']);
            $this->assertSame('stage=child rc=19', $result['failure']);
            $this->assertFalse($result['timedOut']);
        }
    }

    public function testTimeoutKeepsDiagnosticAndClosesCapture(): void
    {
        $result = $this->runAccount('printf blocked >&2; exec sleep 30', null, 1);
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['timedOut']);
        $this->assertStringContainsString('stage=timeout rc=', $result['failure']);
        $this->assertStringContainsString(' stderr=blocked', $result['failure']);
        $this->assertFalse($result['captureOpen']);
    }

    public function testReplacementFailureLogIncludesCauseWithoutInput(): void
    {
        $result = $this->runAccount('', null, 0, 'replacement');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('(stage=child rc=31 stderr=No space left)', $result['log']);
        $this->assertFalse(strpos($result['log'], 'private-input') !== false);
        $this->assertFalse(file_exists($this->tempDir.'/home/config'));
    }

    /** Load the real runner with fake root identity and a fixture-only runuser. */
    private function runAccount(string $command, ?string $input = null, int $timeout = 0, string $fault = ''): array
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) {
            throw new SkipTest('Account runner requires POSIX identity functions');
        }
        $home = $this->pmssEnsureDir($this->tempDir.'/home');
        $stub = $this->pmssEnsureDir($this->tempDir.'/stub');
        $record = $this->tempDir.'/runuser-record';
        $this->pmssWriteFile($record, '');
        // Large payloads must not cross the operating system's argument-size limit.
        $inputPath = $this->tempDir.'/input';
        if ($input !== null) $this->pmssWriteFile($inputPath, $input);
        $inputExpression = $input === null ? 'null' : 'file_get_contents('.var_export($inputPath, true).')';
        $this->pmssWriteFile($stub.'/runuser', "#!/bin/sh\nprintf '%s %s %s\\n' \"\$1\" \"\$2\" \"\$3\" > "
            .escapeshellarg($record)."\nshift 3\nexec \"\$@\"\n");
        chmod($stub.'/runuser', 0700);
        $script = <<<'PHP'
namespace AccountFailureFixture;
function posix_geteuid() { return 0; }
function posix_getpwnam($name) { return ['uid' => 12345, 'gid' => 12345]; }
function fileowner($path) { return 12345; }
function tmpfile() { return $GLOBALS['fault'] === 'stderr' ? false : \tmpfile(); }
function unlink($path) { return $GLOBALS['fault'] === 'unlink' ? false : \unlink($path); }
function proc_open($command, $descriptors, &$pipes, $cwd) {
    $GLOBALS['rootBranch'] = strpos($command, 'env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin setsid runuser -u ') !== false;
    $capture = $descriptors[2];
    $stat = \fstat($capture);
    $GLOBALS['capture'] = $capture;
    $GLOBALS['descriptor'] = [is_resource($capture), $stat['nlink'], $stat['mode'] & 0777, $cwd];
    if ($GLOBALS['fault'] === 'launch') return false;
    if ($GLOBALS['fault'] === 'replacement') $command = 'sh -c '.escapeshellarg('printf "No space left" >&2; exit 31');
    $command = str_replace('PATH=/usr/sbin:/usr/bin:/sbin:/bin', 'PATH='.escapeshellarg($GLOBALS['stub'].':/usr/sbin:/usr/bin:/sbin:/bin'), $command);
    return \proc_open($command, $descriptors, $pipes, $cwd);
}
PHP;
        $script .= '$GLOBALS["fault"] = '.var_export($fault, true).'; $GLOBALS["stub"] = '.var_export($stub, true).';';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/accountPath.php', 'AccountFailureFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/userFileAccount.php', 'AccountFailureFixture');
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/lighttpd/userFileWrite.php'), true).';';
        $script .= 'ini_set("error_log", '.var_export($this->tempDir.'/errors.log', true).');'
            .'$home = '.var_export($home, true).'; $timedOut = true; $failure = "stale";'
            .'$ok = $GLOBALS["fault"] === "replacement" ? pmssReplaceAccountFile("alice", $home, $home."/config", "private-input", 0600)'
            .': pmssAccountPathRun("alice", $home, [$GLOBALS["fault"] === "validation" ? dirname($home)."/outside" : $home."/target"], '
            .var_export($command, true).', '.$inputExpression.', '.$timeout.', $timedOut, $failure);'
            .'echo json_encode(["ok" => $ok, "timedOut" => $timedOut, "failure" => $failure,'
            .'"descriptor" => $GLOBALS["descriptor"] ?? null, "captureOpen" => is_resource($GLOBALS["capture"] ?? null),'
            .'"rootBranch" => $GLOBALS["rootBranch"] ?? false]);';
        $result = $this->pmssRunInlinePhpJson($script, ['PMSS_TIMEOUT_FIRE_LOG' => $this->tempDir.'/timeout.jsonl']);
        $result['runuser'] = file_get_contents($record);
        $result['log'] = is_file($this->tempDir.'/errors.log') ? file_get_contents($this->tempDir.'/errors.log') : '';
        return $result;
    }
}
