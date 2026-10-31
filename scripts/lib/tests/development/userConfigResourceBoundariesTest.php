<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigCli.php';

class userConfigResourceBoundariesTest extends TestCase
{
    public function testStoredFieldsRetainNullFalseEmptyAndSchemaOrder(): void
    {
        $this->assertSame([
            'trafficCapMbit' => 0, 'CPUWeight' => false, 'IOWeight' => null,
            'IOReadBW' => '', 'IOWriteBW' => [],
        ], \pmssUserConfigCliPersistedStoredResources([
            'IOWriteBW' => [], 'unknown' => 'kept elsewhere', 'IOWeight' => null,
            'trafficLimit' => 50, 'CPUWeight' => false, 'IOReadBW' => '',
            'trafficCapMbit' => 0, 'iopsLimit' => 100,
        ]));
    }

    public function testPresenceSelectsValuesWithoutChangingExistingPayloadOrder(): void
    {
        $payload = ['unknown' => 'original', 'CPUWeight' => 100, 'IOWeight' => 200];
        $user = ['unknown' => 'replacement', 'CPUWeight' => null, 'IOWeight' => false, 'trafficCapMbit' => 0];
        foreach ([
            [[], $payload],
            [['CPUWeight' => true], ['unknown' => 'original', 'CPUWeight' => null, 'IOWeight' => 200]],
            [['IOWeight' => 1], ['unknown' => 'original', 'CPUWeight' => 100, 'IOWeight' => false]],
            [['CPUWeight' => '0', 'IOWeight' => [], 'trafficCapMbit' => false], $payload],
            [['unknown' => true, 'IOReadBW' => true], $payload],
            [['trafficCapMbit' => 'yes'], $payload + ['trafficCapMbit' => 0]],
        ] as $case) {
            $this->assertSame($case[1], \pmssUserConfigCliApplyPersistedResources($payload, $user, $case[0]));
        }
    }

    public function testCgroupArgumentsKeepEmptyValueAndClearRequestPrecedence(): void
    {
        $base = ['/scripts/util/userConfigCgroup.php', 'fixtur01', '--apply', '--memory-high=512'];
        foreach ([
            [[], []],
            [['IOReadIOPS' => null], []],
            [['IOReadIOPS' => []], []],
            [['IOReadIOPS' => false], ['--io-read-iops=/home:max']],
            [['IOReadIOPS' => 0, 'IOWriteIOPS' => '0'], ['--io-read-iops=/home:max', '--io-write-iops=/home:max']],
            // Nonempty whitespace-bearing values retain the original direct-argument path.
            [['IOReadIOPS' => ' 0 '], ['--io-read-iops= 0 ']],
            [['CPUWeight' => 0, 'IOReadBW' => '', 'cpuQuotaPercent' => 0], ['--cpu-quota-percent=0']],
        ] as $case) {
            $this->assertSame(array_merge($base, $case[1]), \pmssUserConfigCliBuildCgroupApplyArgs('fixtur01', 512, $case[0]));
        }
    }

    public function testStoredCommandRequiresPositiveMemoryAfterLegacyConversion(): void
    {
        foreach ([null, false, '', 'invalid', -1, 0, 0.5] as $memory) {
            $this->assertSame(null, \pmssUserConfigCliBuildStoredCgroupApplyArgs('fixtur01', ['ramMiB' => $memory]));
        }
        $this->assertSame([
            '/scripts/util/userConfigCgroup.php', 'fixtur01', '--apply', '--memory-high=512',
            '--io-weight=300', '--cpu-quota-percent=infinity',
        ], \pmssUserConfigCliBuildStoredCgroupApplyArgs('fixtur01', [
            'ramMiB' => '512.9', 'name' => 'fixtur02', 'memory' => 9000,
            'IOWeight' => 300, 'cpuQuotaPercent' => 'infinity', 'unknown' => true,
        ]));
    }

    public function testSparsePositionalsKeepLegacyTraversalAndTrailingRules(): void
    {
        foreach ([
            [[], []],
            [['CPUWeight' => 0], ['', '0']],
            [['trafficCapMbit' => 0], ['', '', '', '', '', '', '', '', '0']],
            // The later schema entry owns the final index, even when its numeric slot is lower.
            [['trafficCapMbit' => 12, 'CPUWeight' => 200], ['', '200']],
            [['ioCostModel' => 'linear'], ['', '', '', '', '', '', '', '', '', '', '', 'linear']],
        ] as $case) {
            $this->assertSame($case[1], \pmssUserConfigCliBuildUserConfigPositionals($case[0]));
        }
    }

    public function testParserAndCommandModulesLoadWithoutHelpOrFacade(): void
    {
        foreach (['userConfigParse.php', 'userConfigCgroup.php'] as $module) {
            $result = $this->pmssRunRepoInlinePhpRequireJson('scripts/lib/user/'.$module,
                'echo json_encode([count(pmssUserConfigCliResourceSpecs()), function_exists("pmssUserConfigCliUsage")]);');
            $this->assertSame([13, false], $result);
        }
    }
}
