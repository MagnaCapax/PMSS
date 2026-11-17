<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigCli.php';
require_once dirname(__DIR__, 2).'/user/userConfigRuntime.php';

class UserConfigRuntimeTest extends TestCase
{
    public function testRtorrentLockPidParsesCanonicalAndRejectsMalformedValues(): void
    {
        foreach ([
            ["12345:+session\n", 12345],
            ['', null],
            ['0:+session', null],
            ['1:+session', null],
            ['abc:+session', null],
            ['-5:+session', null],
        ] as [$content, $expected]) {
            $lockFile = $this->pmssMakeTempFile('pmss-rtorrent-lock-');
            $this->pmssWriteFile($lockFile, $content);
            $this->assertSame($expected, \pmssUserConfigRtorrentLockPid($lockFile));
        }
    }

    public function testRtorrentLockPidRejectsSymlink(): void
    {
        $target = $this->pmssMakeTempFile('pmss-rtorrent-lock-target-');
        $this->pmssWriteFile($target, "12345:+session\n");
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
        ] as $label => [$name, $uid, $comm, $ownerUid, $owned]) {
            $procRoot = $this->pmssMakeTempDir('pmss-proc-');
            $this->pmssWriteFiles($procRoot.'/12345', [
                'status' => "Name:\t{$name}\nUid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\n", 'comm' => $comm."\n"]);

            $this->assertSame($uid, \pmssUserConfigProcStatusUid(12345, $procRoot), $label.' uid');
            $this->assertSame($owned, \pmssUserConfigRtorrentProcessOwnedBy(12345, $ownerUid, $procRoot), $label);
        }
    }

    public function testCgroupApplyFailureMessageIsSingleLine(): void
    {
        $message = \pmssUserConfigCgroupApplyFailureMessage("fixtur\n01", 7);

        $this->assertSame(
            'Warning: cgroup configuration failed for fixtur?01 (rc=7); update-step2 will check and retry slice policy drift',
            $message
        );
    }

    public function testSparseModeHelpersPreserveLegacyContracts(): void
    {
        $modes = array();
        foreach ([[array('', 'fixtur01', '1024', '200'), false, false], [array('', 'fixtur01'), false, true], [array('', 'fixtur01'), true, false], [array('', 'fixtur01'), true, true], [array('', 'fixtur01', '0', '200'), false, false], [array(''), true, true]] as $case) {
            $modes[] = \pmssUserConfigInvocationMode($case[0], $case[1], $case[2]);
        }
        $this->assertSame(array('full', 'named', 'welcome', 'named', '', ''), $modes);

        $this->assertSame(null, \pmssUserConfigBaselineError(array('ramMiB' => '1024', 'quota' => 200), array('ramMiB', 'quota')));
        $this->assertSame('Error: missing existing quota; rerun full userConfig.php first.', \pmssUserConfigBaselineError(array('ramMiB' => '1024', 'quota' => 'bad'), array('ramMiB', 'quota')));

        $existing = array('ramMiB' => 2048, 'quota' => 500, 'CPUWeight' => 100, 'trafficCapMbit' => 90, 'trafficLimit' => 999);
        $result = \pmssUserConfigNamedModeUser(array('name' => 'fixtur01', 'memory' => 0, 'quota' => 0, 'trafficLimit' => null), $existing, array('CPUWeight' => 400, 'IOWeight' => 300));

        $this->pmssAssertArraySubsetSame(array('memory' => 2048, 'quota' => 500, 'CPUWeight' => 400,
            'IOWeight' => 300, 'trafficCapMbit' => 90, 'trafficLimit' => null), $result);

        $store = new class { public function applyFallbacks(string $username, array $payload): array { return array_merge($payload, array('billingServiceId' => $username === 'fixtur01' ? 123 : 0, 'billingClientId' => 456)); } };
        $payload = \pmssUserConfigPayloadBuild($store, array('rtorrentPort' => '5050', 'welcomeMessage' => 'kept'), array('name' => 'fixtur01', 'memory' => 1024, 'quota' => 200, 'CPUWeight' => 300, 'trafficCapMbit' => 150), array('CPUWeight' => true, 'trafficCapMbit' => true), false);

        $this->pmssAssertArraySubsetSame(array('ramMiB' => 1024, 'rtorrentPort' => 5050, 'quota' => 200,
            'quotaBurst' => 250, 'trafficLimit' => 0, 'CPUWeight' => 300, 'trafficCapMbit' => 150,
            'billingServiceId' => 123, 'billingClientId' => 456, 'dockerEnabled' => false, 'welcomeMessage' => 'kept'), $payload);
    }

    public function testDiskQuotaApplyIsReservedForFullMode(): void
    {
        foreach (['full' => true, 'named' => false, 'welcome' => false, '' => false, 'invalid' => false] as $mode => $expected) {
            $this->assertSame($expected, \pmssUserConfigDiskQuotaShouldApply($mode), 'disk quota apply policy for '.$mode);
        }
    }
}
