<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class UserCliHelpContractsTest extends TestCase
{
    public function testCliHelpIncludesStructuredSectionsAndRanges(): void
    {
        foreach ([
            ['scripts/addUser.php', ['--help'], ['Usage', 'Positional Parameters', 'Named Options', 'Examples', '--cpu-weight=WEIGHT', '--io-latency-ms=MS', '--io-cost-qos=SETTING', '--io-cost-model=SETTING', '1-10000', '250 MiB']],
            ['scripts/util/userConfig.php', ['ghostuser', '--help'], ['Usage', '--welcome-message=HTML', '--iops-limit=OPS', '--io-latency-ms=MS', '--io-cost-qos=SETTING', '--io-cost-model=SETTING', 'MemoryHigh', 'Examples', '250 MiB']],
            ['scripts/util/userConfigCgroup.php', ['--help'], ['Resource Options', '--defaults', '--io-profile=hdd|nvme|bulk', '--io-latency-ms=MS', '--io-cost-qos=SETTING', '--io-cost-model=SETTING', '1-10000', '250 MiB', 'Examples']],
            ['scripts/util/userResourcesList.php', ['--help'], ['--brief', '--full', '--json', '--jsonl', '--help works without root'], ['must be run as root']],
        ] as $case) {
            $result = $this->pmssAssertRepoPhpScriptOutputContains($case[0], $case[1], $case[2]);
            foreach ($case[3] ?? [] as $forbidden) {
                $this->pmssAssertStringNotContainsString($forbidden, $result['output']);
            }
        }
    }

    public function testAddUserShortHelpMatchesLongHelp(): void
    {
        $this->assertSame($this->pmssRunRepoPhpScript('scripts/addUser.php', ['--help']), $this->pmssRunRepoPhpScript('scripts/addUser.php', ['-h']));
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
