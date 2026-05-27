<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/add/cli.php';
require_once dirname(__DIR__, 2).'/user/add/userConfigApply.php';

class addUserCliTest extends TestCase
{
    public function testUsageDocumentsHelpAndResourceFlags(): void
    {
        $usage = \pmssAddUserCliUsage();

        $this->assertStringContainsAllStrings(['addUser.php USERNAME --password=PASSWORD', '--cpu-weight=WEIGHT', '--io-read-bw=/dev/DEVICE:RATE', '--cpu-quota-percent=PERCENT|infinity', '--iops-limit=OPS', '--io-latency-ms=MS', '--io-cost-qos=SETTING', '--io-cost-model=SETTING', '--docker-enabled=true|false', '--help'], $usage);
    }

    public function testHelpFlagReturnsUsageWithoutUserPayload(): void
    {
        $cli = $this->parseAddUserCli(['--help']);

        $this->assertTrue($cli['help'] === true, 'help mode should be explicit');
        $this->assertStringContainsAllStrings(['Usage', 'Positional Parameters', 'Named Options', 'Examples'], $cli['usage']);
    }

    public function testLegacyPositionalArgumentsRemainSupported(): void
    {
        $cli = $this->parseAddUserCli(['alice', 'rand', '512', '100', '500', '80', '16']);

        $this->assertSame('alice', $cli['user']['name']);
        $this->assertSame('', $cli['user']['password']);
        $this->assertSame('512', $cli['user']['memory']);
        $this->assertSame('100', $cli['user']['quota']);
        $this->assertSame('500', $cli['user']['trafficLimit']);
        $this->assertSame('80', $cli['user']['trafficCapMbit']);
        $this->assertSame(16, $cli['user']['torrentThrottle']);
    }

    public function testLongOptionsPopulateAdvancedResources(): void
    {
        $cli = $this->parseAddUserCli([
            '--user=alice',
            '--password=secret',
            '--ram-mib=512',
            '--disk-quota-gib=100',
            '--cpu-weight=200',
            '--io-weight=300',
            '--io-read-bw=/dev/sda:5M',
            '--io-write-bw=/dev/sda:7M',
            '--io-read-iops=/dev/sda:100',
            '--io-write-iops=/dev/sda:200',
            '--cpu-quota-percent=150',
            '--iops-limit=123456',
            '--io-latency-ms=50',
            '--io-cost-qos=enable=1 ctrl=user',
            '--io-cost-model=ctrl=user model=linear',
            '--traffic-cap-mbit=80',
            '--docker-enabled=false',
        ]);

        $this->assertSame('200', $cli['user']['CPUWeight']);
        $this->assertSame('300', $cli['user']['IOWeight']);
        $this->assertSame('/dev/sda:5M', $cli['user']['IOReadBW']);
        $this->assertSame('/dev/sda:7M', $cli['user']['IOWriteBW']);
        $this->assertSame('/dev/sda:100', $cli['user']['IOReadIOPS']);
        $this->assertSame('/dev/sda:200', $cli['user']['IOWriteIOPS']);
        $this->assertSame('150', $cli['user']['cpuQuotaPercent']);
        $this->assertSame('123456', $cli['user']['iopsLimit']);
        $this->assertSame('50', $cli['user']['ioLatencyMs']);
        $this->assertSame('enable=1 ctrl=user', $cli['user']['ioCostQos']);
        $this->assertSame('ctrl=user model=linear', $cli['user']['ioCostModel']);
        $this->assertSame('80', $cli['user']['trafficCapMbit']);
        $this->assertSame('false', $cli['user']['dockerEnabled']);
    }

    public function testDockerEnabledRequiresExplicitValue(): void
    {
        $this->assertAddUserParseFails([
            '--user=alice',
            '--password=secret',
            '--ram-mib=512',
            '--disk-quota-gib=100',
            '--docker-enabled',
        ], '--docker-enabled requires true or false');
    }

    public function testLongOptionsOverrideLegacyPositionals(): void
    {
        $cli = $this->parseAddUserCli([
            'alice',
            'secret',
            '512',
            '100',
            '--ram-mib=1024',
            '--disk-quota-gib=250',
            '--traffic-limit-gb=900',
        ]);

        $this->assertSame('1024', $cli['user']['memory']);
        $this->assertSame('250', $cli['user']['quota']);
        $this->assertSame('900', $cli['user']['trafficLimit']);
    }

    public function testFirstPositionalUsernameCanMixWithNamedOptions(): void
    {
        $cli = $this->parseAddUserCli([
            'alice',
            '--password=secret',
            '--ram-mib=1024',
            '--disk-quota-gib=250',
            '--io-weight=300',
        ]);

        $this->assertSame('alice', $cli['user']['name']);
        $this->assertSame('secret', $cli['user']['password']);
        $this->assertSame('1024', $cli['user']['memory']);
        $this->assertSame('250', $cli['user']['quota']);
        $this->assertSame('300', $cli['user']['IOWeight']);
    }

    public function testRejectsNegativeUploadThrottle(): void
    {
        $this->assertAddUserParseFails([
            '--user=alice',
            '--password=secret',
            '--ram-mib=512',
            '--disk-quota-gib=100',
            '--upload-throttle-kib=-1',
        ], 'Invalid upload throttle value');
    }

    public function testUserConfigCommandPadsSkippedOptionalSlots(): void
    {
        $command = \pmssAddUserBuildUserConfigCommand([
            'name' => 'alice',
            'memory' => '512',
            'quota' => '100',
            'CPUWeight' => '200',
            'cpuQuotaPercent' => '150',
            'iopsLimit' => '1234',
            'torrentThrottle' => 16,
            'dockerEnabled' => 'false',
        ]);

        $this->assertStringContainsAllStrings(["'/scripts/util/userConfig.php' 'alice' '512' '100' '' '200' '' '' '' '' '' '150'", "'--upload-throttle-kib=16'", "'--iops-limit=1234'", "'--docker-enabled=false'"], $command);
    }

    private function parseAddUserCli(array $args): array
    {
        return \pmssAddUserParseCli(array_merge(['addUser.php'], $args));
    }

    private function assertAddUserParseFails(array $args, string $message): void
    {
        try {
            $this->parseAddUserCli($args);
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
            return;
        }

        $this->fail('Expected addUser parser failure: '.$message);
    }
}
