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

        $this->assertStringContainsString('--cpu-weight=WEIGHT', $usage);
        $this->assertStringContainsString('--io-read-bw=/dev/DEVICE:RATE', $usage);
        $this->assertStringContainsString('--cpu-quota-percent=PERCENT|infinity', $usage);
        $this->assertStringContainsString('--help', $usage);
    }

    public function testHelpFlagReturnsUsageWithoutUserPayload(): void
    {
        $cli = \pmssAddUserParseCli(['addUser.php', '--help']);

        $this->assertTrue($cli['help'] === true, 'help mode should be explicit');
        $this->assertStringContainsString('Usage:', $cli['usage']);
    }

    public function testLegacyPositionalArgumentsRemainSupported(): void
    {
        $cli = \pmssAddUserParseCli(['addUser.php', 'alice', 'rand', '512', '100', '500', '80', '16']);

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
        $cli = \pmssAddUserParseCli([
            'addUser.php',
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
            '--traffic-cap-mbit=80',
        ]);

        $this->assertSame('200', $cli['user']['CPUWeight']);
        $this->assertSame('300', $cli['user']['IOWeight']);
        $this->assertSame('/dev/sda:5M', $cli['user']['IOReadBW']);
        $this->assertSame('/dev/sda:7M', $cli['user']['IOWriteBW']);
        $this->assertSame('/dev/sda:100', $cli['user']['IOReadIOPS']);
        $this->assertSame('/dev/sda:200', $cli['user']['IOWriteIOPS']);
        $this->assertSame('150', $cli['user']['cpuQuotaPercent']);
        $this->assertSame('80', $cli['user']['trafficCapMbit']);
    }

    public function testLongOptionsOverrideLegacyPositionals(): void
    {
        $cli = \pmssAddUserParseCli([
            'addUser.php',
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

    public function testRejectsNegativeUploadThrottle(): void
    {
        try {
            \pmssAddUserParseCli([
                'addUser.php',
                '--user=alice',
                '--password=secret',
                '--ram-mib=512',
                '--disk-quota-gib=100',
                '--upload-throttle-kib=-1',
            ]);
            $this->fail('Negative throttles must be rejected');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Invalid upload throttle value', $exception->getMessage());
        }
    }

    public function testUserConfigCommandPadsSkippedOptionalSlots(): void
    {
        $command = \pmssAddUserBuildUserConfigCommand([
            'name' => 'alice',
            'memory' => '512',
            'quota' => '100',
            'CPUWeight' => '200',
            'cpuQuotaPercent' => '150',
            'torrentThrottle' => 16,
        ]);

        $this->assertStringContainsString("'/scripts/util/userConfig.php' 'alice' '512' '100' '' '200' '' '' '' '' '' '150'", $command);
        $this->assertStringContainsString("'--upload-throttle-kib=16'", $command);
    }
}

