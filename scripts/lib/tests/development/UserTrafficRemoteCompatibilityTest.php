<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserTrafficRemoteCompatibilityTest extends TestCase
{
    public function testRemoteUserTrafficKeepsCallbackSafeContract(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/util/remote/userTraffic.php',
            [
                'pmssManagedHomeUsersList()',
                'serialize($userTrafficData)',
                'Output contract (STDOUT only, no banners/warnings):',
                'array<string,array{normal:int,local:int,ingress:int}>',
                'Hallinta callback path expects an unserialize()-compatible payload after trim()',
                'Leading newlines are allowed; any other STDOUT bytes, and anything written',
                'Do not call /scripts/listUsers.php from this callback producer.',
            ],
            [
                "pmssListManagedUsers('/scripts/listUsers.php')" => 'Remote userTraffic callback must not rely on listUsers mount-gate bypass path',
                'PMSS_SKIP_HOME_MOUNT_CHECK=1' => 'Remote userTraffic callback must not rely on listUsers mount-gate bypass path',
            ]
        );
    }

    /** The heartbeat covers the slow reads and is reaped before the payload is written. */
    public function testRemoteUserTrafficStopsHeartbeatBeforePayload(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/remote/userTraffic.php', ['ordered' => [[
            "require_once dirname(__DIR__, 2).'/lib/runtime/stdoutHeartbeat.php';",
            'pmssStdoutHeartbeatStartForStdout();',
            'foreach (pmssManagedHomeUsersList() as $userName)',
            'pmssStdoutHeartbeatStop();',
            'echo serialize($userTrafficData);',
        ]]]);
    }

    /**
     * Run the script body with piped stdout while the traffic reads block past one
     * heartbeat interval, then hold the process open past another interval after
     * the payload: only heartbeat newlines may precede it and nothing may follow.
     */
    public function testPipedRunPrefixesOnlyNewlinesAndEndsWithPayload(): void
    {
        $path = $this->pmssRepoPath('scripts/util/remote/userTraffic.php');
        $source = (string) file_get_contents($path);
        $this->assertSame(1, substr_count($source, 'declare(strict_types=1);'));
        $source = preg_replace('/\A#![^\n]*\n<\?php/', '', $source, 1, $stripped);
        $this->assertSame(1, $stripped, 'userTraffic.php must start with a shebang and <?php');
        $source = str_replace('__DIR__', var_export(dirname($path), true), $source);
        [$head, $body] = explode('declare(strict_types=1);', $source, 2);
        $shims = 'namespace UserTrafficHeartbeatFixture; '
            .'function pmssManagedHomeUsersList(): array { return ["qauser1", "qauser2"]; } '
            .'function pmssReadUserTrafficStates(string $user): array { sleep(4); '
            .'return ["normal" => strlen($user), "local" => 2, "ingress" => 3]; } ';
        $code = 'eval('.var_export($head.'declare(strict_types=1); '.$shims.$body, true).'); usleep(5500000);';
        $process = proc_open('exec '.$this->pmssBuildInlinePhpCommand($code),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), 'userTraffic fixture failed: '.$errors);

        $expected = [
            'qauser1' => ['normal' => 7, 'local' => 2, 'ingress' => 3],
            'qauser2' => ['normal' => 7, 'local' => 2, 'ingress' => 3],
        ];
        $this->assertSame($expected, unserialize(trim((string) $output)));
        $payload = ltrim((string) $output, "\n");
        $this->assertSame(serialize($expected), $payload, 'bytes other than leading newlines reached stdout');
        $this->assertTrue(strlen((string) $output) - strlen($payload) >= 2, 'expected heartbeat newlines during the eight-second read');
    }
}
