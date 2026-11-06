<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/readLimitStatus.php';
require_once __DIR__.'/../../cgroup/directApply.php';
require_once __DIR__.'/../../../../etc/skel/www/readLimitNotice.php';

/** Read-limit status for the panel and private slice modes (ADR 0232); temp files only. */
class ReadLimitStatusTest extends TestCase
{
    public function testDeliveredRateIsWholeTensAndPlaceholdersAreNoLimit(): void
    {
        foreach ([[15, 10], [20, 20], [29, 20], [5, 5], [1230, 1230]] as [$cap, $rate]) {
            $this->assertSame($rate, \pmssReadLimitDelivered($cap), (string) $cap);
        }
        foreach ([null, 0, -1, 1000000, 2000000] as $cap) $this->assertSame(null, \pmssReadLimitDelivered($cap));
    }

    public function testPublishSkipsPlaceholdersAndCountsRefusedWrites(): void
    {
        // No such accounts exist, so the guarded writer refuses each real limit: two failures, placeholders skipped.
        $this->assertSame(2, \pmssReadLimitStatusPublish(['pmss-test-a' => 30, 'pmss-test-b' => 1000000, 'pmss-test-c' => null, 'pmss-test-d' => 45], ['pmss-test-a' => true], 1800000000));
        $this->assertSame(0, \pmssReadLimitStatusPublish(['pmss-test-b' => 1000000], [], 1800000000));
        $this->assertSame(['read_limit' => 30, 'held' => true, 'updated' => 1800000000],
            json_decode(\pmssReadLimitStatusJson(30, true, 1800000000), true));
    }

    public function testPanelShowsOnlyAFreshWellFormedStatus(): void
    {
        $root = $this->pmssMakeTempDir('pmss-read-limit-', 0700); $now = 1800000000;
        $good = \pmssReadLimitStatusJson(40, false, $now - 300);
        $this->pmssWriteFiles($root, ['good' => $good, 'held' => \pmssReadLimitStatusJson(40, true, $now),
            'stale' => \pmssReadLimitStatusJson(40, false, $now - 901), 'future' => \pmssReadLimitStatusJson(40, false, $now + 61),
            'zero' => \pmssReadLimitStatusJson(0, false, $now), 'text' => '{"read_limit":"40","held":false,"updated":1800000000}',
            'bad' => 'not json', 'big' => str_repeat(' ', 5000).$good]);
        $this->assertSame(['limit' => 40, 'held' => false], \pmssReadLimitStatusRead($root.'/good', $now));
        $this->assertSame(['limit' => 40, 'held' => true], \pmssReadLimitStatusRead($root.'/held', $now));
        foreach (['stale', 'future', 'zero', 'text', 'bad', 'big', 'missing'] as $name) {
            $this->assertSame(null, \pmssReadLimitStatusRead($root.'/'.$name, $now), $name);
        }
        $this->pmssCreateSymlinkOrSkip($root.'/good', $root.'/link');
        $this->assertSame(null, \pmssReadLimitStatusRead($root.'/link', $now));
    }

    public function testPanelTextShowsTheLimitAndExplainsOnlyWhenHeld(): void
    {
        $this->assertSame('', \pmssReadLimitHtmlBuild(null));
        $quiet = \pmssReadLimitHtmlBuild(['limit' => 1240, 'held' => false]);
        $this->assertTrue(strpos($quiet, 'up to 1,240 per second') !== false);
        $this->assertFalse(strpos($quiet, 'wait their turn') !== false);
        $this->assertTrue(strpos(\pmssReadLimitHtmlBuild(['limit' => 20, 'held' => true]), 'wait their turn') !== false);
    }

    public function testSlicePrivacyRefusesSystemIdsAndNeverTouchesNonRootDirectories(): void
    {
        $root = $this->pmssMakeTempDir('pmss-slice-privacy-', 0700);
        $this->pmssWriteFiles($root, ['blkio/user.slice/user-1001.slice/.keep' => '']);
        $slice = $root.'/blkio/user.slice/user-1001.slice';
        chmod($slice, 0755);
        // Under root the fixture directories would be root-owned; hand them to nobody so they stay out of scope.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) { chown($slice, 65534); chown(dirname($slice), 65534); }
        $this->assertFalse(\pmssCgroupDirectSlicePrivacyApply(999, 1001, false, $root));
        $this->assertFalse(\pmssCgroupDirectSlicePrivacyApply(1001, 0, false, $root));
        // Test directories are not root-owned, so the helper must leave them exactly as they are.
        $this->assertTrue(\pmssCgroupDirectSlicePrivacyApply(1001, 1001, false, $root));
        $this->assertSame(0755, fileperms($slice) & 07777);
    }
}
