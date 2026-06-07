<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class UserCliHelpContractsTest extends TestCase
{
    public function testAddUserLongHelpIncludesStructuredSectionsAndRanges(): void
    {
        $this->pmssAssertRepoPhpScriptOutputContains('scripts/addUser.php', ['--help'], [
            'Usage',
            'Positional Parameters',
            'Named Options',
            'Examples',
            '--cpu-weight=WEIGHT',
            '--io-latency-ms=MS',
            '--io-cost-qos=SETTING',
            '--io-cost-model=SETTING',
            '1-10000',
            '250 MiB',
        ]);
    }

    public function testAddUserShortHelpMatchesLongHelp(): void
    {
        $this->assertSame(
            $this->pmssRunRepoPhpScript('scripts/addUser.php', ['--help']),
            $this->pmssRunRepoPhpScript('scripts/addUser.php', ['-h'])
        );
    }

    public function testUserConfigHelpBypassesUserLookup(): void
    {
        $this->pmssAssertRepoPhpScriptOutputContains('scripts/util/userConfig.php', ['ghostuser', '--help'], [
            'Usage',
            '--welcome-message=HTML',
            '--iops-limit=OPS',
            '--io-latency-ms=MS',
            '--io-cost-qos=SETTING',
            '--io-cost-model=SETTING',
            'MemoryHigh',
            'Examples',
            '250 MiB',
        ]);
    }

    public function testUserConfigCgroupHelpIncludesProfilesAndRanges(): void
    {
        $this->pmssAssertRepoPhpScriptOutputContains('scripts/util/userConfigCgroup.php', ['--help'], [
            'Resource Options',
            '--defaults',
            '--io-profile=hdd|nvme|bulk',
            '--io-latency-ms=MS',
            '--io-cost-qos=SETTING',
            '--io-cost-model=SETTING',
            '1-10000',
            '250 MiB',
            'Examples',
        ]);
    }

    public function testUserResourcesListHelpBypassesRootGuard(): void
    {
        $result = $this->pmssAssertRepoPhpScriptOutputContains('scripts/util/userResourcesList.php', ['--help'], [
            '--brief',
            '--full',
            '--json',
            '--jsonl',
            '--help works without root',
        ]);
        $this->pmssAssertStringNotContainsString('must be run as root', $result['output']);
    }

    public function testOperatorDocsStayAlignedWithStructuredHelp(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'docs/addUser.md' => ['required' => [
                'addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB',
                '--cpu-weight=WEIGHT',
                '--io-latency-ms=MS',
                '--io-cost-qos=SETTING',
                '--io-cost-model=SETTING',
                '250 MiB',
                '--docker-enabled=true|false',
                '/scripts/addUser.php alice rand 1024 200',
            ]],
            'docs/userConfig.md' => ['required' => [
                './userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB',
                '--welcome-message=HTML',
                '--iops-limit=OPS',
                '--io-latency-ms=MS',
                '--io-cost-qos=SETTING',
                '--io-cost-model=SETTING',
                '1-10000',
                '250 MiB',
                '/scripts/util/userConfig.php alice 1024 200',
            ]],
        ]);
    }
}
