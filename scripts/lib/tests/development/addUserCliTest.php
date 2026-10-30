<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/add/cli.php';
require_once dirname(__DIR__, 2).'/user/add/userConfigApply.php';

class addUserCliTest extends TestCase
{
    public function testProfilesUseTheDefinitionsAndRejectUnknownValues(): void
    {
        $args = array('fixtur01', 'rand', '2048', '200');
        $this->assertSame('default', $this->parseAddUserCli($args)['user']['appProfile']);
        foreach (array_keys(\pmssUserAppProfiles()) as $profile) {
            $this->assertSame($profile, $this->parseAddUserCli(array_merge($args, array('--app-profile='.$profile)))['user']['appProfile']);
        }
        foreach (array('', 'OpenClaw', 'other', 'openclaw;id', '../openclaw', str_repeat('a', 41), "default\n", "default\0") as $profile) {
            $threw = false;
            try { $this->parseAddUserCli(array_merge($args, array('--app-profile='.$profile))); }
            catch (\InvalidArgumentException $e) { $threw = true; }
            $this->assertTrue($threw, 'Invalid app profile accepted');
        }
        $names = implode(', ', array_keys(\pmssUserAppProfiles()));
        $this->assertStringContainsAllStrings(['--app-profile=PROFILE', $names], \pmssAddUserCliUsage());
        $this->assertAddUserParseFails(array_merge($args, ['--app-profile']), 'Invalid --app-profile; expected '.$names);
    }

    public function testProfileConfigUsesTheExistingCommandAndExplicitDockerOptionWins(): void
    {
        foreach (\pmssUserAppProfiles() as $profile => $apps) {
            $args = ['fixtur01', 'rand', '2048', '200', '--app-profile='.$profile];
            $user = $this->parseAddUserCli($args)['user'];
            $command = \pmssAddUserBuildUserConfigCommand($user);
            // Only a value that differs from the policy default (on) is passed, so the store keeps meaning a choice.
            if (array_key_exists('docker', $apps) && $apps['docker'] === false) {
                $this->assertStringContainsString("'--docker-enabled=false'", $command);
            } else {
                $this->assertStringNotContainsString('--docker-enabled=', $command);
            }
            foreach (['true', 'false'] as $enabled) {
                $user = $this->parseAddUserCli(array_merge($args, ['--docker-enabled='.$enabled]))['user'];
                $this->assertStringContainsString("'--docker-enabled=".$enabled."'", \pmssAddUserBuildUserConfigCommand($user));
            }
        }
    }

    public function testUsageDocumentsHelpAndResourceFlags(): void
    {
        $usage = \pmssAddUserCliUsage();

        $this->assertStringContainsAllStrings(['addUser.php USERNAME --password=PASSWORD', '--bonus-quota-gib=BONUS_QUOTA_GiB', '--cpu-weight=WEIGHT', '--io-read-bw=/dev/DEVICE:RATE', '--cpu-quota-percent=PERCENT|infinity', '--iops-limit=OPS', '--io-latency-ms=MS', '--io-cost-qos=SETTING', '--io-cost-model=SETTING', '--docker-enabled=true|false', '--help'], $usage);
    }

    public function testHelpFlagReturnsUsageWithoutUserPayload(): void
    {
        $cli = $this->parseAddUserCli(['--help']);

        $this->assertTrue($cli['help'] === true, 'help mode should be explicit');
        $this->assertStringContainsAllStrings(['Usage', 'Positional Parameters', 'Named Options', 'Examples'], $cli['usage']);
    }

    public function testWrapperParseErrorsExitNonZeroForAutomation(): void
    {
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/addUser.php', array(), array(), '2>&1');

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Usage', $result['output']);
        $this->assertStringContainsString('addUser.php USERNAME PASSWORD', $result['output']);
    }

    public function testLegacyPositionalArgumentsRemainSupported(): void
    {
        $cli = $this->parseAddUserCli(['fixtur01', 'rand', '512', '100', '500', '80', '16']);

        $this->pmssAssertArraySubsetSame([
            'name' => 'fixtur01', 'password' => '', 'memory' => '512', 'quota' => '100', 'trafficLimit' => '500',
            'trafficCapMbit' => '80', 'torrentThrottle' => 16,
        ], $cli['user']);
    }

    public function testLongOptionsPopulateAdvancedResources(): void
    {
        $cli = $this->parseAddUserCli([
            '--user=fixtur01',
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

        $this->pmssAssertArraySubsetSame([
            'CPUWeight' => '200', 'IOWeight' => '300', 'IOReadBW' => '/dev/sda:5M', 'IOWriteBW' => '/dev/sda:7M',
            'IOReadIOPS' => '/dev/sda:100', 'IOWriteIOPS' => '/dev/sda:200', 'cpuQuotaPercent' => '150',
            'iopsLimit' => '123456', 'ioLatencyMs' => '50', 'ioCostQos' => 'enable=1 ctrl=user',
            'ioCostModel' => 'ctrl=user model=linear', 'trafficCapMbit' => '80', 'dockerEnabled' => 'false',
        ], $cli['user']);
    }

    public function testDockerEnabledRequiresExplicitValue(): void
    {
        $this->assertAddUserParseFails([
            '--user=fixtur01',
            '--password=secret',
            '--ram-mib=512',
            '--disk-quota-gib=100',
            '--docker-enabled',
        ], '--docker-enabled requires true or false');
    }

    public function testBonusQuotaRequiresANonNegativeInteger(): void
    {
        $cli = $this->parseAddUserCli([
            '--user=fixtur01', '--password=secret', '--ram-mib=512', '--disk-quota-gib=100',
            '--bonus-quota-gib=25',
        ]);
        $this->assertSame(25, $cli['user']['bonusQuotaGiB']);

        $zero = $this->parseAddUserCli([
            '--user=fixtur01', '--password=secret', '--ram-mib=512', '--disk-quota-gib=100',
            '--bonus-quota-gib=0',
        ]);
        $this->assertSame(0, $zero['user']['bonusQuotaGiB']);

        foreach (['--bonus-quota-gib', '--bonus-quota-gib=-1', '--bonus-quota-gib=1.5', '--bonus-quota-gib= 1', '--bonus-quota-gib=999999999999999999999999'] as $invalid) {
            $this->assertAddUserParseFails([
                '--user=fixtur01', '--password=secret', '--ram-mib=512', '--disk-quota-gib=100', $invalid,
            ], 'Invalid --bonus-quota-gib value');
        }
    }

    public function testLongOptionsOverrideLegacyPositionals(): void
    {
        $cli = $this->parseAddUserCli([
            'fixtur01',
            'secret',
            '512',
            '100',
            '--ram-mib=1024',
            '--disk-quota-gib=250',
            '--traffic-limit-gb=900',
        ]);

        $this->pmssAssertArraySubsetSame(['memory' => '1024', 'quota' => '250', 'trafficLimit' => '900'], $cli['user']);
    }

    public function testFirstPositionalUsernameCanMixWithNamedOptions(): void
    {
        $cli = $this->parseAddUserCli([
            'fixtur01',
            '--password=secret',
            '--ram-mib=1024',
            '--disk-quota-gib=250',
            '--io-weight=300',
        ]);

        $this->pmssAssertArraySubsetSame([
            'name' => 'fixtur01', 'password' => 'secret', 'memory' => '1024', 'quota' => '250', 'IOWeight' => '300',
        ], $cli['user']);
    }

    public function testRejectsNegativeUploadThrottle(): void
    {
        $this->assertAddUserParseFails([
            '--user=fixtur01',
            '--password=secret',
            '--ram-mib=512',
            '--disk-quota-gib=100',
            '--upload-throttle-kib=-1',
        ], 'Invalid upload throttle value');
    }

    public function testUserConfigCommandPadsSkippedOptionalSlots(): void
    {
        $command = \pmssAddUserBuildUserConfigCommand([
            'name' => 'fixtur01',
            'memory' => '512',
            'quota' => '100',
            'CPUWeight' => '200',
            'cpuQuotaPercent' => '150',
            'iopsLimit' => '1234',
            'torrentThrottle' => 16,
            'dockerEnabled' => 'false',
        ]);

        $this->assertStringContainsAllStrings(["'/scripts/util/userConfig.php' 'fixtur01' '512' '100' '' '200' '' '' '' '' '' '150'", "'--upload-throttle-kib=16'", "'--iops-limit=1234'", "'--docker-enabled=false'"], $command);
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
