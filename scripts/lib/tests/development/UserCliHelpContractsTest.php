<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class UserCliHelpContractsTest extends TestCase
{
    public function testAddUserLongHelpIncludesStructuredSectionsAndRanges(): void
    {
        $output = $this->pmssRunRepoPhpScript('scripts/addUser.php', ['--help']);

        $this->assertStringContainsAllStrings([
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
        ], $output);
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
        $this->assertHelpCommandContains('scripts/util/userConfig.php', ['ghostuser', '--help'], [
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
        $this->assertHelpCommandContains('scripts/util/userConfigCgroup.php', ['--help'], [
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
        $result = $this->assertHelpCommandContains('scripts/util/userResourcesList.php', ['--help'], [
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
        $this->pmssAssertRepoFileContainsAllStrings(
            'docs/addUser.md',
            [
                'addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB',
                '--cpu-weight=WEIGHT',
                '--io-latency-ms=MS',
                '--io-cost-qos=SETTING',
                '--io-cost-model=SETTING',
                '250 MiB',
                '--docker-enabled=true|false',
                '/scripts/addUser.php alice rand 1024 200',
            ]
        );
        $this->pmssAssertRepoFileContainsAllStrings(
            'docs/userConfig.md',
            [
                './userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB',
                '--welcome-message=HTML',
                '--iops-limit=OPS',
                '--io-latency-ms=MS',
                '--io-cost-qos=SETTING',
                '--io-cost-model=SETTING',
                '1-10000',
                '250 MiB',
                '/scripts/util/userConfig.php alice 1024 200',
            ]
        );
    }

    private function assertHelpCommandContains(string $script, array $args, array $needles): array
    {
        $result = $this->pmssRunRepoPhpScriptCommand($script, $args);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsAllStrings($needles, $result['output']);

        return $result;
    }
}
