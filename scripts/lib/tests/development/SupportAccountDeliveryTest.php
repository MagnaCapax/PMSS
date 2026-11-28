<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** ADR 0016: exercise the delivered SSH command beyond the operator permission wall. */
class SupportAccountDeliveryTest extends TestCase
{
    private const SUPPORT = 'etc/skel/bin/pmssSupport/';

    public function testWrapperExecutesOnlyTheAdjacentAccountCopy(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/skel/bin/support', [
            'exec php "$(dirname -- "${BASH_SOURCE[0]}")/pmssSupport/supportCommand.php" "$@"',
        ], ['/scripts/']);
    }

    public function testEveryDependencyIsLocalAndDeliveredBeforeTheWrapper(): void
    {
        $manifest = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');
        $wrapperPosition = strpos($manifest, "'bin/support'");
        $this->assertTrue($wrapperPosition !== false);
        $files = glob($this->pmssRepoPath(self::SUPPORT).'*.php');
        $this->assertSame(16, count($files), 'support entrypoint, ten modules and five local dependency modules');
        foreach ($files as $file) {
            $name = basename($file);
            $source = (string) file_get_contents($file);
            $position = strpos($manifest, "'bin/pmssSupport/".$name."'");
            $this->assertTrue($position !== false && $position < $wrapperPosition, $name.' missing or delivered too late');
            // Every code include is local; only the existing operator-config data include is dynamic.
            $tokens = token_get_all($source);
            foreach ($tokens as $index => $token) {
                if (!is_array($token) || !in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) continue;
                $include = '';
                while (($part = $tokens[++$index]) !== ';') $include .= is_array($part) ? $part[1] : $part;
                $include = trim($include);
                if ($name === 'config.php' && $include === '$path') continue;
                $this->assertTrue(preg_match('~^__DIR__\s*\.\s*\'/([A-Za-z]+\.php)\'$~', $include, $match) === 1,
                    $name.' has a dependency outside the delivered set: '.$include);
                $this->assertTrue(is_file(dirname($file).'/'.$match[1]), $name.' has an undelivered dependency');
            }
            $this->assertFalse(strpos($source, "'/scripts/") !== false, $name.' reaches into the operator tree');
            $this->assertFalse(strpos($source, 'scripts/lib/runtime.php') !== false, $name.' loads the operator bootstrap');
            $executable = substr($source, 0, 2) === '#!';
            $this->assertSame($executable, (fileperms($file) & 0111) !== 0, $name.' shebang/mode mismatch');
        }
    }

    public function testRootAlertsUseTheInstalledSkeletonCopy(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/usageAlertDelivery.php', [
            "dirname(__DIR__, 3).'/etc/skel/bin/pmssSupport/config.php'",
            "dirname(__DIR__, 3).'/etc/skel/bin/pmssSupport/mail.php'",
        ], ["'/home/", "'/support/config.php'", "'/support/mail.php'"]);
    }

    public function testUnprivilegedHomeWrapperSubmitsUsingStubTransport(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            throw new SkipTest('Run this permission-wall regression on an unprivileged QA worker');
        }
        $root = $this->pmssMakeTempDir('pmss-support-account-');
        $home = $root.'/fixtur01';
        $bin = $home.'/bin';
        foreach (glob($this->pmssRepoPath(self::SUPPORT).'*.php') as $source) {
            $target = $this->pmssWriteFile($bin.'/pmssSupport/'.basename($source), (string) file_get_contents($source));
            chmod($target, fileperms($source) & 0777);
        }
        $wrapper = $bin.'/support';
        $this->pmssWriteExecutableFile($wrapper, $this->pmssReadRepoFile('etc/skel/bin/support'));
        $config = $root.'/config/support.php';
        // Restrict PATH so no installed sendmail or real diagnostic command can run.
        $stubs = $root.'/stubs';
        foreach (['uptime', 'df', 'pgrep'] as $command) {
            $this->pmssWriteExecutableFile($stubs.'/'.$command, "#!/bin/sh\nprintf 'fixture diagnostic\\n'\n");
        }
        foreach (['php' => PHP_BINARY, 'bash' => '/bin/bash', 'dirname' => '/usr/bin/dirname'] as $name => $target) {
            $this->assertTrue(symlink($target, $stubs.'/'.$name));
        }
        $environment = [
            'PATH' => $stubs,
            'HOME' => $home, 'USER' => 'fixtur01', 'PMSS_HOME_DIR' => $root,
            'PMSS_SUPPORT_CONFIG_PATH' => $config, 'PMSS_CONFIG_DIR' => $root.'/absent-config',
            'PMSS_VERSION_FILE' => $root.'/absent-version',
        ];
        $help = $this->pmssExecArgvCommand([$wrapper, '--help'], $environment);
        $this->assertSame(0, $help['rc']);
        $this->assertSame('Usage: support <message>', $help['output']);
        $empty = $this->pmssExecArgvCommand([$wrapper], $environment);
        $this->assertSame(1, $empty['rc']);
        $this->assertSame('Usage: support <message>', $empty['output']);
        [$result, $mail] = $this->submitViaSmtpStub($wrapper, $config, $environment, $root);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertStringContainsString("Ticket submitted! You'll receive a response via email.", $result['output']);
        $snapshots = glob($home.'/.support/requests/request-*.txt');
        $this->assertSame(1, count($snapshots));
        $this->assertSame(0700, fileperms(dirname($snapshots[0])) & 0777);
        $this->assertSame(0600, fileperms($snapshots[0]) & 0777);
        $this->assertSame(posix_geteuid(), fileowner($snapshots[0]));
        $this->assertStringContainsAllStrings(['fixture support request', 'fixture diagnostic'],
            (string) file_get_contents($snapshots[0]));
        $this->assertStringContainsAllStrings(['To: support@example.invalid', 'fixture support request'],
            $mail);
    }

    /** Serve one loopback-only SMTP dialogue while the actual account wrapper runs. */
    private function submitViaSmtpStub(string $wrapper, string $config, array $environment, string $root): array
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) throw new SkipTest('Loopback SMTP fixture unavailable: '.$error);
        $connection = $process = null;
        try {
            $address = stream_socket_get_name($server, false);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            $this->pmssWriteFile($config, '<?php return '.var_export([
                'targetEmail' => 'support@example.invalid', 'snapshotDirectory' => '.support/requests',
                'smtpPort' => $port, 'connectTimeout' => 5, 'relayHost' => '127.0.0.1',
            ], true).";\n");
            $process = proc_open($this->pmssBuildArgvCommand([$wrapper, 'fixture support request']), [
                0 => ['file', '/dev/null', 'r'], 1 => ['file', $root.'/stdout', 'w'],
                2 => ['file', $root.'/stderr', 'w'],
            ], $pipes, $root, $environment);
            $this->assertTrue(is_resource($process));
            $connection = @stream_socket_accept($server, 5);
            $this->assertTrue(is_resource($connection), 'Account wrapper did not reach the SMTP stub');
            stream_set_timeout($connection, 5);
            fwrite($connection, "220 fixture ready\r\n");
            foreach (['EHLO ', 'MAIL FROM:', 'RCPT TO:', 'DATA'] as $command) {
                $this->assertTrue(strpos((string) fgets($connection), $command) === 0, $command);
                fwrite($connection, $command === 'DATA' ? "354 send data\r\n" : "250 accepted\r\n");
            }
            $mail = '';
            while (($line = fgets($connection)) !== false && $line !== ".\r\n") $mail .= $line;
            $this->assertSame(".\r\n", $line, 'SMTP message was not terminated');
            fwrite($connection, "250 queued\r\n");
            $this->assertSame("QUIT\r\n", fgets($connection));
            fwrite($connection, "221 goodbye\r\n");
            $rc = proc_close($process);
            $process = null;
            return [['rc' => $rc, 'output' => (string) file_get_contents($root.'/stdout')
                .(string) file_get_contents($root.'/stderr')], $mail];
        } finally {
            if (is_resource($connection)) fclose($connection);
            fclose($server);
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }
}
