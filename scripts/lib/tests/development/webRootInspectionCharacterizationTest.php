<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users.php';

/** Lock the inspection contracts shared by migration and recovery before restructuring. */
class WebRootInspectionCharacterizationTest extends TestCase
{
    public function testContentAndIdentitySnapshotSchemasAndOrdering(): void
    {
        $root = $this->pmssMakeTempDir('pmss-web-inspection-');
        $this->pmssWriteFile($root.'/nested/zero', '');
        $this->pmssWriteFile($root.'/a', "payload\0\n");
        foreach ([false, true] as $identity) {
            $snapshot = \pmssUserWebRootMigrationSnapshot($root, $identity);
            $this->assertSame(['', 'a', 'nested', 'nested/zero'], array_keys($snapshot));
            foreach ($snapshot as $relative => $entry) {
                $stat = lstat($root.($relative === '' ? '' : '/'.$relative));
                $expected = ['type' => is_dir($root.'/'.$relative) ? 'dir' : 'file',
                    'mode' => $stat['mode'] & 07777, 'uid' => $stat['uid'], 'gid' => $stat['gid']];
                if ($identity) {
                    $expected += ['dev' => $stat['dev'], 'ino' => $stat['ino']];
                    if ($entry['type'] === 'file') $expected += ['size' => $stat['size'], 'mtime' => $stat['mtime']];
                } elseif ($entry['type'] === 'file') {
                    $expected['sha256'] = hash('sha256', $relative === 'a' ? "payload\0\n" : '');
                }
                $this->assertSame($expected, $entry);
            }
        }
    }

    public function testFailedWalkRetainsOnlyEntriesVisitedBeforeRejectedLink(): void
    {
        $root = $this->pmssMakeTempDir('pmss-web-inspection-');
        $this->pmssWriteFile($root.'/a', 'first');
        $this->pmssCreateSymlinkOrSkip('missing', $root.'/z');
        $snapshot = ['prior' => []];
        $this->assertFalse(\pmssUserWebRootMigrationSnapshotWalk($root, 'prefix', $snapshot, false));
        $this->assertSame(['prior', 'prefix', 'prefix/a'], array_keys($snapshot));
        $this->assertSame(null, \pmssUserWebRootMigrationSnapshot($root));
    }

    public function testLinkInspectionPoliciesRemainDistinct(): void
    {
        $home = $this->pmssMakeTempDir('pmss-web-inspection-');
        $this->pmssEnsureDir($home.'/www');
        $link = $home.'/www/link';
        foreach (['../missing' => [true, true], '../../outside' => [true, false], '/outside' => [false, false]] as $target => $expected) {
            $this->pmssCreateSymlinkOrSkip($target, $link);
            $this->assertSame($expected[0], \pmssUserWebRootReconcileTreeIsSafe($link, $home, false));
            $this->assertSame($expected[1], \pmssUserWebRootReconcileTreeIsSafe($link, $home));
            $this->assertTrue(\pmssUserWebRootReconcileApplyOwnership($link, 'unused'));
            $this->assertSame(null, \pmssUserWebRootMigrationSnapshot($link));
            unlink($link);
        }
    }

    public function testMissingAndRegularFileInspectionResults(): void
    {
        $home = $this->pmssMakeTempDir('pmss-web-inspection-');
        $file = $this->pmssWriteFile($home.'/file', 'payload');
        $this->assertTrue(\pmssUserWebRootReconcileTreeIsSafe($file, $home));
        $this->assertFalse(\pmssUserWebRootReconcileTreeIsSafe($home.'/missing', $home));
        $this->assertFalse(\pmssUserWebRootReconcileApplyOwnership($home.'/missing', $this->pmssCurrentOwner()));
        $this->assertSame(null, \pmssUserWebRootMigrationSnapshot($file));
    }
}
