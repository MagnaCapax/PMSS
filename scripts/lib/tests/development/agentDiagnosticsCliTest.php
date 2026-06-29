<?php
namespace PMSS\Tests\Development;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/agentDiagnostics.php';

use PMSS\Tests\TestCase;

final class agentDiagnosticsCliTest extends TestCase
{
    public function testHelpShowsUsage(): void
    {
        $this->pmssAssertRepoPhpScriptOutputContains('scripts/util/agentDiagnostics.php', ['--help'], ['agentDiagnostics.php [--json] [--pretty] [--user USERNAME]']);
    }

    public function testJsonCollectsServerAndUserSections(): void
    {
        $scriptRoot = $this->makeScriptRoot();
        $motdPath = $this->pmssMakeTempFile('pmss-agent-motd-');
        $mdstatPath = $this->pmssMakeTempFile('pmss-agent-mdstat-');
        $fstabPath = $this->pmssMakeTempFile('pmss-agent-fstab-');
        $versionPath = $this->pmssMakeTempFile('pmss-agent-version-');
        file_put_contents($motdPath, "hello host\n");
        file_put_contents($mdstatPath, "md0 : active raid1\n");
        file_put_contents($fstabPath, "/dev/md0 /home ext4 defaults 0 0\n");
        file_put_contents($versionPath, "git/main@2026-03-28\n");

        $binDir = $this->makeCommandStubs();
        $output = $this->pmssRunRepoPhpScript('scripts/util/agentDiagnostics.php', ['--json', '--pretty', '--user=alice'], $this->pmssPathPrefixedEnvironment($binDir, $this->pmssTestModeEnv([
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $scriptRoot,
            'PMSS_AGENT_DIAGNOSTICS_MOTD_PATH' => $motdPath,
            'PMSS_AGENT_DIAGNOSTICS_MDSTAT_PATH' => $mdstatPath,
            'PMSS_AGENT_DIAGNOSTICS_FSTAB_PATH' => $fstabPath,
            'PMSS_AGENT_DIAGNOSTICS_VERSION_PATH' => $versionPath,
        ])));
        $payload = $this->pmssDecodeJsonArray($output);

        $this->assertSame(
            ['motd', 'storage', 'services', 'cgroup', 'system_test', 'users', 'resources', 'traffic', 'user_settings', 'user_processes', 'user_identity', 'user_quota', 'user_disk'],
            array_keys($payload['sections'])
        );
        $this->assertSame(
            ['mode', 'hierarchy_cmdline', 'proc_hidepid', 'user_managers_active', 'user_managers_failed', 'user_io_stat_sample', 'user_cpu_pressure_sample'],
            array_keys($payload['sections']['cgroup'])
        );
        $this->assertSame(
            ['nginx', 'proftpd', 'cron', 'ssh', 'rtorrent_count', 'lighttpd_count'],
            array_keys($payload['sections']['services'])
        );
        $this->assertSame('git/main@2026-03-28', $payload['version']);
        $this->assertSame('alice', $payload['user']);
        $this->assertSame("hello host\n", $payload['sections']['motd']['raw']);
        $this->assertSame(['alice', 'bob'], $payload['sections']['users']['list']);
        $this->assertSame(4, $payload['sections']['services']['rtorrent_count']);
        $this->assertSame(['pid1 rtorrent'], $payload['sections']['user_processes']);
        $this->assertSame(['raw' => 'uid=1001(alice) gid=1001(alice) groups=1001(alice)'], $payload['sections']['user_identity']);
        $this->assertSame(['raw' => 'Disk quotas for user alice'], $payload['sections']['user_quota']);
        $this->assertSame(['raw' => '12G /home/alice'], $payload['sections']['user_disk']);
        $this->assertSame('Alice Setting', $payload['sections']['user_settings']['label']);
    }

    public function testInvalidUserReturnsFailure(): void
    {
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/agentDiagnostics.php', ['--json', '--user=bad!'], $this->pmssTestModeEnv([
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $this->makeScriptRoot(),
        ]));

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Invalid username', $result['output']);
    }

    public function testTextOutputKeepsSectionDelimiters(): void
    {
        $scriptRoot = $this->makeScriptRoot();
        $binDir = $this->makeCommandStubs();
        $this->pmssAssertRepoPhpScriptOutputContains('scripts/util/agentDiagnostics.php', [], ['PMSS Agent Diagnostics', 'user: -', '== services =='], $this->pmssPathPrefixedEnvironment($binDir, $this->pmssTestModeEnv([
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $scriptRoot,
        ])));
    }

    public function testJsonSectionReportsScriptFailure(): void
    {
        $scriptRoot = $this->makeScriptRoot(true);
        $binDir = $this->makeCommandStubs();
        $output = $this->pmssRunRepoPhpScript('scripts/util/agentDiagnostics.php', ['--json'], $this->pmssPathPrefixedEnvironment($binDir, $this->pmssTestModeEnv([
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $scriptRoot,
        ])));
        $payload = $this->pmssDecodeJsonArray($output);

        $this->assertSame('checkUsers.php --json failed', $payload['sections']['users']['consistency']['error']);
    }

    public function testJsonSectionReportsMissingScriptWithoutShellingPhpCliError(): void
    {
        $scriptRoot = $this->makeScriptRoot();
        unlink($scriptRoot.'/scripts/util/checkUsers.php');
        $binDir = $this->makeCommandStubs();
        $output = $this->pmssRunRepoPhpScript('scripts/util/agentDiagnostics.php', ['--json'], $this->pmssPathPrefixedEnvironment($binDir, $this->pmssTestModeEnv([
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $scriptRoot,
        ])));
        $payload = $this->pmssDecodeJsonArray($output);

        $this->assertSame('checkUsers.php --json failed', $payload['sections']['users']['consistency']['error']);
        $this->assertStringContainsString('Diagnostics script missing or unreadable: scripts/util/checkUsers.php', $payload['sections']['users']['consistency']['stderr']);
    }

    public function testPhpScriptRejectsUnsafeRelativePathBeforeExecution(): void
    {
        $result = \pmssAgentDiagnosticsPhpScript('../scripts/listUsers.php');

        $this->assertSame(1, $result['rc']);
        $this->assertSame('', $result['stdout']);
        $this->assertSame('Diagnostics script path unsafe: ../scripts/listUsers.php', $result['stderr']);
    }

    public function testPhpScriptRejectsUnsafeScriptRootBeforeExecution(): void
    {
        $root = $this->pmssMakeNamedTempDir('pmss-agent-root-');
        $unsafeRoot = $root.'/safe/../safe';
        @mkdir($root.'/safe', 0700, true);

        $this->pmssWithEnv(['PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $unsafeRoot], function () use ($unsafeRoot): void {
            $result = \pmssAgentDiagnosticsPhpScript('scripts/listUsers.php');

            $this->assertSame(1, $result['rc']);
            $this->assertSame('', $result['stdout']);
            $this->assertSame('Diagnostics script root unsafe: '.$unsafeRoot, $result['stderr']);
        });
    }

    public function testSpecCollectRecursesMixedNestedSectionsInStableOrder(): void
    {
        $scriptRoot = $this->makeScriptRoot();
        $motdPath = $this->pmssMakeTempFile('pmss-agent-motd-');
        file_put_contents($motdPath, "hello host\n");

        $this->pmssWithEnv([
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $scriptRoot,
            'PMSS_AGENT_DIAGNOSTICS_MOTD_PATH' => $motdPath,
        ], function (): void {
            $sections = \pmssAgentDiagnosticsSpecCollect([
                'motd' => ['type' => 'file', 'env' => 'PMSS_AGENT_DIAGNOSTICS_MOTD_PATH', 'path' => '/etc/motd', 'wrap' => 'raw'],
                'services' => [
                    'rtorrent_count' => ['type' => 'command', 'command' => "printf '4\\n'", 'format' => 'int'],
                ],
                'users' => [
                    'list' => ['type' => 'php', 'path' => 'scripts/listUsers.php', 'format' => 'lines'],
                ],
            ]);

            $this->assertSame(['motd', 'services', 'users'], array_keys($sections));
            $this->assertSame("hello host\n", $sections['motd']['raw']);
            $this->assertSame(['rtorrent_count'], array_keys($sections['services']));
            $this->assertSame(4, $sections['services']['rtorrent_count']);
            $this->assertSame(['alice', 'bob'], $sections['users']['list']);
        });
    }

    public function testSpecCollectWrapsRawCommandOutputWithoutDedicatedFormat(): void
    {
        $section = \pmssAgentDiagnosticsSpecCollect([
            'type' => 'command',
            'command' => "printf 'uid=1001(alice)\\n'",
            'wrap' => 'raw',
        ]);

        $this->assertSame(['raw' => 'uid=1001(alice)'], $section);
    }

    public function testSpecTimeoutBoundsInvalidAndLargeValues(): void
    {
        $default = (int) constant('PMSS_AGENT_DIAGNOSTICS_COMMAND_TIMEOUT_DEFAULT');

        foreach ([[], ['timeout' => 'bad'], ['timeout' => 0], ['timeout' => $default + 1]] as $spec) {
            $this->assertSame($default, \pmssAgentDiagnosticsSpecTimeout($spec));
        }
        $this->assertSame(1, \pmssAgentDiagnosticsSpecTimeout(['timeout' => '1']));
    }

    public function testSpecCollectStopsSlowCommandAtTimeout(): void
    {
        $section = \pmssAgentDiagnosticsSpecCollect([
            'type' => 'command',
            'command' => "printf 'before\\n'; sleep 2; printf 'after\\n'",
            'timeout' => 1,
        ]);

        $this->assertStringContainsString('before', (string) $section);
        $this->assertStringNotContainsString('after', (string) $section);
    }

    public function testSpecLabelDerivesStablePhpErrorLabels(): void
    {
        foreach ([
            'checkUsers.php --json' => [
                'path' => 'scripts/util/checkUsers.php',
                'args' => ['--json'],
            ],
            'userSetting.php view alice' => [
                'path' => 'scripts/userSetting.php',
                'args' => ['view', 'alice'],
            ],
        ] as $expected => $spec) {
            $this->assertSame($expected, \pmssAgentDiagnosticsSpecLabel($spec));
        }
    }

    private function makeScriptRoot(bool $brokenCheckUsers = false): string
    {
        $root = $this->pmssMakeNamedTempDir('pmss-agent-root-');
        mkdir($root.'/scripts/util', 0777, true);
        $this->pmssWriteExecutablePhpFile($root.'/scripts/listUsers.php', "echo \"alice\\nbob\\n\";");
        $this->pmssWriteExecutablePhpFile($root.'/scripts/showTraffic.php', "echo json_encode([['user' => 'alice', 'monthMiB' => 1024]]), PHP_EOL;");
        $this->pmssWriteExecutablePhpFile($root.'/scripts/userSetting.php', "echo json_encode(['label' => 'Alice Setting']), PHP_EOL;");
        $this->pmssWriteExecutablePhpFile($root.'/scripts/util/systemTest.php', "echo json_encode(['summary' => ['ok' => 3]]), PHP_EOL;");
        $checkUsersBody = $brokenCheckUsers ? "fwrite(STDERR, 'boom'); exit(3);" : "echo json_encode(['consistent' => ['alice']]), PHP_EOL;";
        $this->pmssWriteExecutablePhpFile($root.'/scripts/util/checkUsers.php', $checkUsersBody);
        $this->pmssWriteExecutablePhpFile($root.'/scripts/util/userResourcesList.php', "echo json_encode([['user' => 'alice', 'uid' => 1001]]), PHP_EOL;");
        return $root;
    }

    private function makeCommandStubs(): string
    {
        $binDir = $this->pmssMakeNamedTempDir('pmss-agent-bin-');
        return $this->pmssWriteExecutableFiles($binDir, [
            'df' => "#!/bin/sh\nprintf 'Filesystem Size Used Avail Use%% Mounted on\\n/dev/md0 100G 10G 90G 10%% /home\\n'\n",
            'systemctl' => "#!/bin/sh\nprintf 'active\\n'\n",
            'pgrep' => "#!/bin/sh\nif [ \"$1\" = '-cx' ]; then\n  if [ \"$2\" = 'rtorrent' ]; then printf '4\\n'; else printf '2\\n'; fi\n  exit 0\nfi\nprintf 'pid1 rtorrent\\n'\n",
            'id' => "#!/bin/sh\nprintf 'uid=1001(alice) gid=1001(alice) groups=1001(alice)\\n'\n",
            'quota' => "#!/bin/sh\nprintf 'Disk quotas for user alice\\n'\n",
            'du' => "#!/bin/sh\nprintf '12G /home/alice\\n'\n",
        ]);
    }
}
