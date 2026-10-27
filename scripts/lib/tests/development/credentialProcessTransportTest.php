<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/credentialProcess.php';
require_once dirname(__DIR__, 2).'/user/changePwCredential.php';
require_once dirname(__DIR__, 2).'/user/credentialGenerate.php';
require_once dirname(__DIR__, 2).'/user/add/cli.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/pmssAccountAgent.php';

class CredentialProcessTransportTest extends TestCase
{
    private function assertStdinTransport(array $arguments, ?float $timeout = null): void
    {
        $dir = $this->pmssMakeTempDir('pmss-credential-process-');
        $script = $dir.'/record.php';
        file_put_contents($script, '<?php file_put_contents(getenv("PMSS_TEST_ARGS"), json_encode(array_slice($argv, 1))); file_put_contents(getenv("PMSS_TEST_INPUT"), stream_get_contents(STDIN));');
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
        foreach ($arguments as $argument) { $command .= ' '.escapeshellarg($argument); }
        $secret = 'sample-credential-42';
        $output = [];
        $this->pmssWithEnv([
            'PMSS_TEST_ARGS' => $dir.'/args', 'PMSS_TEST_INPUT' => $dir.'/input',
        ], function () use ($command, $secret, $timeout, &$output): void {
            $this->assertSame(0, \pmssCredentialProcessRun($command, $secret, $output, false, $timeout));
        });
        $this->assertSame($arguments, json_decode((string) file_get_contents($dir.'/args'), true));
        $this->assertSame($secret."\n", file_get_contents($dir.'/input'));
        $this->assertFalse(strpos($command, $secret) !== false);
    }

    public function testRecreateHandoffUsesStdin(): void
    {
        $source = $this->pmssReadRepoFile('scripts/recreateUser.php');
        $this->assertTrue(strpos($source, 'pmssCredentialProcessRun($pwCommand, $passwordInput)') !== false);
        $this->assertFalse(strpos($source, 'escapeshellarg($password)') !== false);
        $this->assertStdinTransport(['--password-stdin', 'alice']);
    }

    public function testAddUserHandoffUsesStdin(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/systemUserCreate.php');
        $this->assertTrue(strpos($source, '$user[\'password\']') !== false);
        $this->assertTrue(strpos($source, '$safeChangePw,') !== false);
        $this->assertFalse(strpos($source, 'escapeshellarg($user[\'password\'])') !== false);
        $this->assertStdinTransport(['--password-stdin', 'bob']);
    }

    public function testQbittorrentHandoffUsesStdin(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/qbittorrent.php');
        $this->assertTrue(strpos($source, 'pmssCredentialProcessRun($command, $password, $output, false, 45)') !== false);
        $this->assertFalse(strpos($source, '$username, $password])') !== false);
        $this->assertStdinTransport(['-r', 'account'], 45);
    }

    public function testHtpasswdHandoffUsesStdin(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/passwordFile.php');
        $this->assertTrue(strpos($source, 'htpasswd -i -B') !== false);
        $this->assertFalse(strpos($source, 'escapeshellarg($password)') !== false);
        $this->assertStdinTransport(['-i', '-B', '/tmp/fixture', 'alice']);
    }

    public function testChangePwStdinAndPositionalValuesMatch(): void
    {
        $source = $this->pmssReadRepoFile('scripts/changePw.php');
        $this->assertTrue(strpos($source, "\$token === '--password-stdin'") !== false);
        $this->assertTrue(strpos($source, 'pmssChangePwPasswordReadLine(STDIN)') !== false);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "same-value\nsecond-line\n");
        rewind($stream);
        $this->assertSame('same-value', \pmssChangePwPasswordReadLine($stream));
        fclose($stream);
        $blank = fopen('php://temp', 'w+');
        fwrite($blank, "\n");
        rewind($blank);
        $this->assertSame('', \pmssChangePwPasswordReadLine($blank));
        fclose($blank);
        $empty = fopen('php://temp', 'w+');
        $this->assertSame(null, \pmssChangePwPasswordReadLine($empty));
        fclose($empty);
    }

    public function testAddUserRandHandoffGeneratesOnlyForReceivedEmptyLine(): void
    {
        $add = $this->pmssReadRepoFile('scripts/lib/user/add/systemUserCreate.php');
        $changePw = $this->pmssReadRepoFile('scripts/changePw.php');
        $this->assertTrue(strpos($add, "\$user['password']") !== false);
        $this->assertTrue(strpos($changePw, "if (\$password === '')") !== false);
        $this->assertTrue(strpos($changePw, '$password = generatePassword();') !== false);

        // Exercise the actual transport, line reader and generator without touching an account.
        $dir = $this->pmssMakeTempDir('pmss-change-pw-stdin-');
        $script = $dir.'/changePw-fixture.php';
        $reader = var_export(dirname(__DIR__, 2).'/user/changePwCredential.php', true);
        $generator = var_export(dirname(__DIR__, 2).'/user/credentialGenerate.php', true);
        file_put_contents($script, "<?php require_once {$reader}; require_once {$generator}; "
            .'$password = pmssChangePwPasswordReadLine(STDIN); if ($password === null) exit(1); '
            .'echo $password === "" ? pmssAccountPasswordGenerate() : $password;');
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);

        $output = [];
        foreach ([['alice', 'rand', '512', '100'], ['--user=alice', '--password=rand', '--ram-mib=512', '--disk-quota-gib=100']] as $arguments) {
            $user = \pmssAddUserParseCli(array_merge(['addUser.php'], $arguments))['user'];
            $this->assertSame('', $user['password']);
            $this->assertSame(0, \pmssCredentialProcessRun($command, $user['password'], $output));
            $this->assertSame(1, count($output));
            $this->assertMatches('/^[A-Za-z0-9][A-Za-z0-9_-]{14}[A-Za-z0-9]$/', $output[0]);
        }
        $this->assertSame(0, \pmssCredentialProcessRun($command, 'provided-value', $output));
        $this->assertSame(['provided-value'], $output);
        $this->assertSame(1, \pmssCredentialProcessRun($command, null, $output));
        $this->assertSame([], $output);
    }

    public function testCapturedStderrSharesStdoutOnPhp73Descriptors(): void
    {
        $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('fwrite(STDERR, "diagnostic\n"); exit(7);');
        $output = [];
        foreach ([null, 2.0] as $timeout) {
            $this->assertSame(7, \pmssCredentialProcessRun($command, 'provided-value', $output, false, $timeout));
            $this->assertSame(['diagnostic'], $output);
        }
    }

    public function testBoundedStderrCaptureKeepsCredentialStdoutOnTheInheritedConsole(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-credential-timed-stderr-');
        $library = $this->pmssRepoPath('scripts/lib/user/credentialProcess.php');
        $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('echo "generated-value\n"; fwrite(STDERR, "diagnostic\n"); exit(7);');
        foreach ([false, true] as $capture) {
            file_put_contents($dir.'/harness.php', '<?php require '.var_export($library, true).'; '
                .'echo "before\n"; $output = '.($capture ? '[]' : 'null').'; '
                .'$rc = pmssCredentialProcessRun('.var_export($command, true).', null, $output, true, 2); '
                .'echo "after\n"; file_put_contents('.var_export($dir.'/result.json', true).', json_encode([$rc, $output]));');
            $run = $this->pmssExecShellCommandWithTempStderr(escapeshellarg(PHP_BINARY).' '.escapeshellarg($dir.'/harness.php'));
            $this->assertSame([7, $capture ? ['diagnostic'] : null], json_decode(file_get_contents($dir.'/result.json'), true));
            $this->assertSame("before\ngenerated-value\nafter", $run['result']['output']);
            $this->assertSame($capture ? '' : "diagnostic\n", file_get_contents($run['stderrPath']));
        }
    }

    public function testProvisionStepLogsFailureStderrAndPreservesExitStatus(): void
    {
        $result = $this->provisionStepHarness('fwrite(STDERR, "PHP Fatal error: fixture failed\n"); exit(255);', 'provided-value');
        $this->assertSame(255, $result['rc']);
        $this->assertStringContainsString(' :: PHP Fatal error: fixture failed', $result['log']);
        $this->assertStringContainsString('PHP Fatal error: fixture failed', $result['stderr']);
        $this->assertSame(['steps' => 1, 'err' => 1, 'ok' => 0, 'last_error' => 'Set initial password rc=255'], $result['stats']);
        $this->assertStringContainsString('fixture failed', json_encode($result['events']));
    }

    public function testProvisionStepKeepsGeneratedPasswordOnStdoutOnly(): void
    {
        $result = $this->provisionStepHarness('echo "new password: generated-value\n"; fwrite(STDERR, "sync failed\n"); exit(7);', '');
        $this->assertSame(7, $result['rc']);
        $this->assertStringContainsString('new password: generated-value', $result['output']);
        $this->assertStringContainsString(' :: sync failed', $result['log']);
        $this->assertFalse(strpos($result['log'].json_encode($result['events']), 'generated-value') !== false);
    }

    public function testProvisionStepRedactsBeforeCompactingAndBoundingDiagnostics(): void
    {
        $secret = str_repeat('sample-secret-', 30);
        $result = $this->provisionStepHarness('$password = rtrim(fgets(STDIN), "\n"); fwrite(STDERR, "Rejected ".$password."\r\n\t\033".str_repeat("x", 350)); exit(1);', $secret);
        $this->assertStringContainsString(' :: Rejected [REDACTED] ', $result['log']);
        $excerpt = explode(' :: ', trim($result['log']), 2)[1];
        $this->assertSame(303, strlen($excerpt));
        $this->assertSame('...', substr($excerpt, -3));
        $this->assertFalse(strpos($result['log'].json_encode($result['events']), 'sample-secret-') !== false);
        $this->assertFalse(strpos($result['log'], "\033") !== false);
    }

    public function testProvisionStepWithoutDiagnosticRetainsFailureLine(): void
    {
        foreach (['exit(9);', 'fwrite(STDERR, " \n\t "); exit(9);'] as $child) {
            $result = $this->provisionStepHarness($child, 'provided-value');
            $this->assertSame(9, $result['rc']);
            $this->assertStringContainsString('Set initial password failed (rc=9, duration=', $result['log']);
            $this->assertFalse(strpos($result['log'], ' :: ') !== false);
        }
    }

    public function testProvisionStepSuccessPreservesConsoleWithoutPersistingChildOutput(): void
    {
        $result = $this->provisionStepHarness('echo "new password: provided-value\n"; fwrite(STDERR, "advisory\n");', 'provided-value');
        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('new password: provided-value', $result['output']);
        $this->assertStringContainsString('advisory', $result['stderr']);
        $this->assertStringContainsString('Set initial password completed (duration=', $result['log']);
        $this->assertSame(['steps' => 1, 'err' => 0, 'ok' => 1], $result['stats']);
        $this->assertStringContainsAndOmitsStrings([], ['provided-value', 'advisory'], $result['log'].json_encode($result['events']));
    }

    /** Exercise the real process and provisioning wrappers with isolated log sinks. */
    private function provisionStepHarness(string $childSource, string $stdin): array
    {
        $dir = $this->pmssMakeTempDir('pmss-provision-step-');
        file_put_contents($dir.'/child.php', '<?php '.$childSource);
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($dir.'/child.php');
        $library = $this->pmssRepoPath('scripts/lib/user/add/provisioningRuntime.php');
        file_put_contents($dir.'/harness.php', '<?php require '.var_export($library, true).'; '
            .'function pmssUserBaseContext($action, $phase, $user, $extra) { return $extra; } '
            .'function pmssUserWriteLogs($event) { $GLOBALS["events"][] = $event; } '
            .'function pmssUserLifecycleContextLog($action, $phase, $user, $extra) { pmssUserWriteLogs(pmssUserBaseContext($action, $phase, $user, $extra)); } '
            .'$user = ["name" => "alice"]; $events = []; $provisionStats = ["steps" => 0, "err" => 0, "ok" => 0]; '
            .'$rc = runProvisionStep("Set initial password", '.var_export($command, true).', null, '.var_export($stdin, true).'); '
            .'file_put_contents('.var_export($dir.'/result.json', true).', json_encode(["rc" => $rc, "stats" => $provisionStats, "events" => $events]));');
        $run = $this->pmssExecShellCommandWithTempStderr(escapeshellarg(PHP_BINARY).' '.escapeshellarg($dir.'/harness.php'), [
            'PMSS_ADDUSER_LOG_PATH' => $dir.'/provision.log',
        ]);
        $this->assertSame(0, $run['result']['rc'], $run['result']['output'].file_get_contents($run['stderrPath']));
        return json_decode(file_get_contents($dir.'/result.json'), true) + [
            'output' => $run['result']['output'], 'stderr' => file_get_contents($run['stderrPath']),
            'log' => file_get_contents($dir.'/provision.log'),
        ];
    }

    public function testShadowUpdateCapturesStderrOnPhp73Descriptors(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-shadow-stderr-');
        mkdir($dir.'/bin');
        $this->pmssWriteExecutableFile($dir.'/bin/chpasswd', "#!/bin/sh\ncat >/dev/null\nprintf 'shadow failure\n' >&2\nexit 4\n", 0755, 0700);
        $output = [];
        $this->pmssWithEnv(['PATH' => $dir.'/bin:'.getenv('PATH')], function () use (&$output): void {
            [$status, $hash] = \pmssChangePwShadowUpdate('alice', 'provided-value', true, $output);
            $this->assertSame(4, $status);
            $this->assertSame('', $hash);
        });
        $this->assertSame(['shadow failure'], $output);
    }

    public function testAccountAgentRunCapturesStderrOnPhp73Descriptors(): void
    {
        [$status, $output] = \pmssAgentRun(['sh', '-c', 'printf out; printf err >&2; exit 3']);
        $this->assertSame(3, $status);
        $this->assertSame('outerr', $output);
    }

    public function testProcOpenDescriptorsDoNotUsePhp74Types(): void
    {
        // PHP 7.4 added the 'redirect' and 'null' descriptor types; match both array spellings, not $map['null'].
        $open = '(?:(?<![\\w\\])])\\[|\\barray\\s*\\()\\s*';
        $descriptors = [
            '/'.$open.'[\'\"]'.'redirect'.'[\'\"]\\s*,\\s*\\d+/',
            '/'.$open.'[\'\"]'.'null'.'[\'\"]\\s*[\\])]/',
        ];
        $checked = 0;
        foreach (['scripts', 'etc/skel/bin'] as $root) {
            foreach ($this->pmssRepoFiles($root, '/\\.php$/D') as $relative => $file) {
                $source = (string) file_get_contents($file->getPathname());
                foreach ($descriptors as $descriptor) {
                    $this->assertFalse(preg_match($descriptor, $source) === 1, $relative);
                }
                $checked++;
            }
        }
        $this->assertTrue($checked > 0, 'Expected PHP files to scan');
        $r = 'redi'.'rect';
        $n = 'nu'.'ll';
        foreach (["2 => ['$r', 1]", "2 => array('$r', 1)", "2 => array( \"$r\" , 1 )", "1 => ['$n']", "1 => array('$n')", "[['pipe', 'r'], ['$n']]"] as $sample) {
            $this->assertTrue(preg_match($descriptors[0], $sample) === 1 || preg_match($descriptors[1], $sample) === 1, $sample);
        }
        foreach (["\$states['$n']", "\$rows[0]['$n']", "['$r', '/user-x/app', '/user-x/app/']"] as $sample) {
            $this->assertFalse(preg_match($descriptors[0], $sample) === 1 || preg_match($descriptors[1], $sample) === 1, $sample);
        }
    }
}
