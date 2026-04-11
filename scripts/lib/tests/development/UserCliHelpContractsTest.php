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
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/userConfig.php', ['ghostuser', '--help']);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsAllStrings([
            'Usage',
            '--welcome-message=HTML',
            'MemoryHigh',
            'Examples',
            '250 MiB',
        ], $result['output']);
    }

    public function testUserConfigCgroupHelpIncludesProfilesAndRanges(): void
    {
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/userConfigCgroup.php', ['--help']);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsAllStrings([
            'Resource Options',
            '--defaults',
            '--io-profile=hdd|nvme|bulk',
            '1-10000',
            '250 MiB',
            'Examples',
        ], $result['output']);
    }

    public function testUserResourcesListHelpBypassesRootGuard(): void
    {
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/userResourcesList.php', ['--help']);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsAllStrings([
            '--brief',
            '--full',
            '--json',
            '--jsonl',
            '--help works without root',
        ], $result['output']);
        $this->pmssAssertStringNotContainsString('must be run as root', $result['output']);
    }

    public function testOperatorDocsStayAlignedWithStructuredHelp(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'docs/addUser.md',
            [
                'addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB',
                '--cpu-weight=WEIGHT',
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
                '1-10000',
                '250 MiB',
                '/scripts/util/userConfig.php alice 1024 200',
            ]
        );
    }
}
