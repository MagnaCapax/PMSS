<?php
namespace PMSS\Tests\Development;

require_once __DIR__.'/../common/TestCase.php';

use PMSS\Tests\TestCase;

final class agentDiagnosticsCliTest extends TestCase
{
    public function testHelpShowsUsage(): void
    {
        $output = $this->pmssRunRepoPhpScript('scripts/util/agentDiagnostics.php', ['--help']);
        $this->assertStringContainsString('agentDiagnostics.php [--json] [--pretty] [--user USERNAME]', $output);
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
        $output = $this->pmssRunRepoPhpScript('scripts/util/agentDiagnostics.php', ['--json', '--pretty', '--user=alice'], [
            'PMSS_TEST_MODE' => '1',
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $scriptRoot,
            'PMSS_AGENT_DIAGNOSTICS_MOTD_PATH' => $motdPath,
            'PMSS_AGENT_DIAGNOSTICS_MDSTAT_PATH' => $mdstatPath,
            'PMSS_AGENT_DIAGNOSTICS_FSTAB_PATH' => $fstabPath,
            'PMSS_AGENT_DIAGNOSTICS_VERSION_PATH' => $versionPath,
            'PATH' => $binDir.':'.(string) getenv('PATH'),
        ]);
        $payload = json_decode($output, true);

        $this->assertSame('git/main@2026-03-28', $payload['version']);
        $this->assertSame('alice', $payload['user']);
        $this->assertSame("hello host\n", $payload['sections']['motd']['raw']);
        $this->assertSame(['alice', 'bob'], $payload['sections']['users']['list']);
        $this->assertSame(4, $payload['sections']['services']['rtorrent_count']);
        $this->assertSame(['pid1 rtorrent'], $payload['sections']['user_processes']);
        $this->assertSame('Alice Setting', $payload['sections']['user_settings']['label']);
    }

    public function testInvalidUserReturnsFailure(): void
    {
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/agentDiagnostics.php', ['--json', '--user=bad!'], [
            'PMSS_TEST_MODE' => '1',
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $this->makeScriptRoot(),
        ]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Invalid username', $result['output']);
    }

    public function testTextOutputKeepsSectionDelimiters(): void
    {
        $scriptRoot = $this->makeScriptRoot();
        $binDir = $this->makeCommandStubs();
        $output = $this->pmssRunRepoPhpScript('scripts/util/agentDiagnostics.php', [], [
            'PMSS_TEST_MODE' => '1',
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $scriptRoot,
            'PATH' => $binDir.':'.(string) getenv('PATH'),
        ]);

        $this->assertStringContainsString('PMSS Agent Diagnostics', $output);
        $this->assertStringContainsString('user: -', $output);
        $this->assertStringContainsString('== services ==', $output);
    }

    public function testJsonSectionReportsScriptFailure(): void
    {
        $scriptRoot = $this->makeScriptRoot(true);
        $binDir = $this->makeCommandStubs();
        $output = $this->pmssRunRepoPhpScript('scripts/util/agentDiagnostics.php', ['--json'], [
            'PMSS_TEST_MODE' => '1',
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $scriptRoot,
            'PATH' => $binDir.':'.(string) getenv('PATH'),
        ]);
        $payload = json_decode($output, true);

        $this->assertSame('checkUsers.php --json failed', $payload['sections']['users']['consistency']['error']);
    }

    public function testJsonSectionReportsMissingScriptWithoutShellingPhpCliError(): void
    {
        $scriptRoot = $this->makeScriptRoot();
        unlink($scriptRoot.'/scripts/util/checkUsers.php');
        $binDir = $this->makeCommandStubs();
        $output = $this->pmssRunRepoPhpScript('scripts/util/agentDiagnostics.php', ['--json'], [
            'PMSS_TEST_MODE' => '1',
            'PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $scriptRoot,
            'PATH' => $binDir.':'.(string) getenv('PATH'),
        ]);
        $payload = json_decode($output, true);

        $this->assertSame('checkUsers.php --json failed', $payload['sections']['users']['consistency']['error']);
        $this->assertStringContainsString('Diagnostics script missing or unreadable: scripts/util/checkUsers.php', $payload['sections']['users']['consistency']['stderr']);
    }

    private function makeScriptRoot(bool $brokenCheckUsers = false): string
    {
        $root = $this->pmssMakeNamedTempDir('pmss-agent-root-');
        mkdir($root.'/scripts/util', 0777, true);
        $this->writeScript($root.'/scripts/listUsers.php', "echo \"alice\\nbob\\n\";");
        $this->writeScript($root.'/scripts/showTraffic.php', "echo json_encode([['user' => 'alice', 'monthMiB' => 1024]]), PHP_EOL;");
        $this->writeScript($root.'/scripts/userSetting.php', "echo json_encode(['label' => 'Alice Setting']), PHP_EOL;");
        $this->writeScript($root.'/scripts/util/systemTest.php', "echo json_encode(['summary' => ['ok' => 3]]), PHP_EOL;");
        $checkUsersBody = $brokenCheckUsers ? "fwrite(STDERR, 'boom'); exit(3);" : "echo json_encode(['consistent' => ['alice']]), PHP_EOL;";
        $this->writeScript($root.'/scripts/util/checkUsers.php', $checkUsersBody);
        $this->writeScript($root.'/scripts/util/userResourcesList.php', "echo json_encode([['user' => 'alice', 'uid' => 1001]]), PHP_EOL;");
        return $root;
    }

    private function makeCommandStubs(): string
    {
        $binDir = $this->pmssMakeNamedTempDir('pmss-agent-bin-');
        file_put_contents($binDir.'/df', "#!/bin/sh\nprintf 'Filesystem Size Used Avail Use%% Mounted on\\n/dev/md0 100G 10G 90G 10%% /home\\n'\n");
        file_put_contents($binDir.'/systemctl', "#!/bin/sh\nprintf 'active\\n'\n");
        file_put_contents($binDir.'/pgrep', "#!/bin/sh\nif [ \"$1\" = '-cx' ]; then\n  if [ \"$2\" = 'rtorrent' ]; then printf '4\\n'; else printf '2\\n'; fi\n  exit 0\nfi\nprintf 'pid1 rtorrent\\n'\n");
        file_put_contents($binDir.'/id', "#!/bin/sh\nprintf 'uid=1001(alice) gid=1001(alice) groups=1001(alice)\\n'\n");
        file_put_contents($binDir.'/quota', "#!/bin/sh\nprintf 'Disk quotas for user alice\\n'\n");
        file_put_contents($binDir.'/du', "#!/bin/sh\nprintf '12G /home/alice\\n'\n");
        foreach (['df', 'systemctl', 'pgrep', 'id', 'quota', 'du'] as $binary) {
            @chmod($binDir.'/'.$binary, 0755);
        }
        return $binDir;
    }

    private function writeScript(string $path, string $body): void
    {
        file_put_contents($path, "#!/usr/bin/env php\n<?php\n{$body}\n");
        @chmod($path, 0755);
    }
}
