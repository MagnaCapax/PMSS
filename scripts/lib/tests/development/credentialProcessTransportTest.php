<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/credentialProcess.php';
require_once dirname(__DIR__, 2).'/user/changePwCredential.php';
require_once dirname(__DIR__, 2).'/user/credentialGenerate.php';
require_once dirname(__DIR__, 2).'/user/add/cli.php';

class CredentialProcessTransportTest extends TestCase
{
    private function assertStdinTransport(array $arguments): void
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
        ], function () use ($command, $secret, &$output): void {
            $this->assertSame(0, \pmssCredentialProcessRun($command, $secret, $output));
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
        $this->assertTrue(strpos($source, 'pmssCredentialProcessRun($command, $password, $output)') !== false);
        $this->assertFalse(strpos($source, '$username, $password])') !== false);
        $this->assertStdinTransport(['-r', 'account']);
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
        $this->assertSame(7, \pmssCredentialProcessRun($command, 'provided-value', $output));
        $this->assertSame(['diagnostic'], $output);
    }

    public function testShadowUpdateCapturesStderrOnPhp73Descriptors(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-shadow-stderr-');
        mkdir($dir.'/bin');
        file_put_contents($dir.'/bin/chpasswd', "#!/bin/sh\ncat >/dev/null\nprintf 'shadow failure\n' >&2\nexit 4\n");
        chmod($dir.'/bin/chpasswd', 0700);
        $output = [];
        $this->pmssWithEnv(['PATH' => $dir.'/bin:'.getenv('PATH')], function () use (&$output): void {
            [$status, $hash] = \pmssChangePwShadowUpdate('alice', 'provided-value', true, $output);
            $this->assertSame(4, $status);
            $this->assertSame('', $hash);
        });
        $this->assertSame(['shadow failure'], $output);
    }

    public function testProcOpenDescriptorsDoNotUsePhp74RedirectType(): void
    {
        $scripts = dirname(__DIR__, 4).'/scripts';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scripts));
        $descriptor = '/\[\s*[\'\"]'.'redirect'.'[\'\"]\s*,\s*\d+\s*\]/';
        foreach ($files as $file) {
            if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            $this->assertFalse(is_string($source) && preg_match($descriptor, $source) === 1, $file->getPathname());
        }
    }
}
