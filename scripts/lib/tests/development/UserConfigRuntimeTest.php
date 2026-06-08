<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigCli.php';
require_once dirname(__DIR__, 2).'/user/userConfigRuntime.php';

class UserConfigRuntimeTest extends TestCase
{
    private function writeRtorrentProcFixture(string $name = 'rtorrent', int $uid = 1500, string $comm = 'rtorrent'): string
    {
        $procRoot = $this->pmssMakeTempDir('pmss-proc-');
        mkdir($procRoot.'/12345');
        file_put_contents($procRoot.'/12345/status', "Name:\t{$name}\nUid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\n");
        file_put_contents($procRoot.'/12345/comm', $comm."\n");
        return $procRoot;
    }

    private function assertRtorrentLockPid(?int $expected, string $content): void
    {
        $lockFile = $this->pmssMakeTempFile('pmss-rtorrent-lock-');
        file_put_contents($lockFile, $content);

        $this->assertSame($expected, \pmssUserConfigRtorrentLockPid($lockFile));
    }

    public function testRtorrentLockPidParsesCanonicalAndRejectsMalformedValues(): void
    {
        foreach ([
            ["12345:+session\n", 12345],
            ['', null],
            ['0:+session', null],
            ['1:+session', null],
            ['abc:+session', null],
            ['-5:+session', null],
        ] as $case) {
            $this->assertRtorrentLockPid($case[1], $case[0]);
        }
    }

    public function testRtorrentLockPidRejectsSymlink(): void
    {
        $target = $this->pmssMakeTempFile('pmss-rtorrent-lock-target-');
        file_put_contents($target, "12345:+session\n");
        $link = $this->pmssMakeTempPath('pmss-rtorrent-lock-link-');
        symlink($target, $link);

        $this->assertSame(null, \pmssUserConfigRtorrentLockPid($link));
    }

    public function testRtorrentProcFixtureSupportsUidAndOwnershipChecks(): void
    {
        foreach ([
            'matching rtorrent process' => ['rtorrent', 1500, 'rtorrent', 1500, true],
            'wrong command' => ['bash', 1500, 'bash', 1500, false],
            'wrong uid' => ['rtorrent', 1500, 'rtorrent', 1600, false],
        ] as $label => $case) {
            $procRoot = $this->writeRtorrentProcFixture($case[0], $case[1], $case[2]);

            $this->assertSame($case[1], \pmssUserConfigProcStatusUid(12345, $procRoot), $label.' uid');
            $this->assertSame($case[4], \pmssUserConfigRtorrentProcessOwnedBy(12345, $case[3], $procRoot), $label);
        }
    }

    public function testCgroupApplyFailureMessageIsSingleLine(): void
    {
        $message = \pmssUserConfigCgroupApplyFailureMessage("ali\nce", 7);

        $this->assertSame(
            'Warning: cgroup configuration failed for ali?ce (rc=7); update-step2 will check and retry slice policy drift',
            $message
        );
    }

    public function testSparseModeHelpersPreserveLegacyContracts(): void
    {
        $modes = array();
        foreach ([[array('', 'alice', '1024', '200'), false, false], [array('', 'alice'), false, true], [array('', 'alice'), true, false], [array('', 'alice'), true, true], [array('', 'alice', '0', '200'), false, false], [array(''), true, true]] as $case) {
            $modes[] = \pmssUserConfigInvocationMode($case[0], $case[1], $case[2]);
        }
        $this->assertSame(array('full', 'named', 'welcome', 'named', '', ''), $modes);

        $this->assertSame(null, \pmssUserConfigBaselineError(array('ramMiB' => '1024', 'quota' => 200), array('ramMiB', 'quota')));
        $this->assertSame('Error: missing existing quota; rerun full userConfig.php first.', \pmssUserConfigBaselineError(array('ramMiB' => '1024', 'quota' => 'bad'), array('ramMiB', 'quota')));

        $existing = array('ramMiB' => 2048, 'quota' => 500, 'CPUWeight' => 100, 'trafficCapMbit' => 90, 'trafficLimit' => 999);
        $result = \pmssUserConfigNamedModeUser(array('name' => 'alice', 'memory' => 0, 'quota' => 0, 'trafficLimit' => null), $existing, array('CPUWeight' => 400, 'IOWeight' => 300));

        $this->assertSame(
            array(2048, 500, 400, 300, 90, null),
            array($result['memory'], $result['quota'], $result['CPUWeight'], $result['IOWeight'], $result['trafficCapMbit'], $result['trafficLimit'])
        );

        $store = new class { public function applyFallbacks(string $username, array $payload): array { return array_merge($payload, array('billingServiceId' => $username === 'alice' ? 123 : 0, 'billingClientId' => 456)); } };
        $payload = \pmssUserConfigPayloadBuild($store, array('rtorrentPort' => '5050', 'welcomeMessage' => 'kept'), array('name' => 'alice', 'memory' => 1024, 'quota' => 200, 'CPUWeight' => 300, 'trafficCapMbit' => 150), array('CPUWeight' => true, 'trafficCapMbit' => true), false);

        $this->assertSame(
            array(1024, 5050, 200, 250, 0, 300, 150, 123, 456, false, 'kept'),
            array($payload['ramMiB'], $payload['rtorrentPort'], $payload['quota'], $payload['quotaBurst'], $payload['trafficLimit'], $payload['CPUWeight'], $payload['trafficCapMbit'], $payload['billingServiceId'], $payload['billingClientId'], $payload['dockerEnabled'], $payload['welcomeMessage'])
        );
    }
}
