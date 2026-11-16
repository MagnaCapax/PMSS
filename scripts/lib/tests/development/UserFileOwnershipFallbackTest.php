<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';

/** Ownership policy in scripts/lib/lighttpd/userFileReplace.php; no host metadata is changed. */
class UserFileOwnershipFallbackTest extends TestCase
{
    public function testFreshPortMarkersInheritParentOwnershipBeforePublication(): void
    {
        $script = <<<'PHP'
namespace UserFileOwnershipFixture;
// Synthetic entries distinguish the directory's metadata from existing file metadata.
function pmssManagedAccountHomePath($path) { return $GLOBALS['managed']; }
function posix_geteuid() { return $GLOBALS['uid']; }
function is_file($path) { return $GLOBALS['existing'] !== false; }
function pmssPathExistsOrLink($path) { return $GLOBALS['occupied']; }
function stat($path) {
    return $path === '/fixture' ? $GLOBALS['parent'] : $GLOBALS['existing'];
}
function chmod($path, $mode) {
    $GLOBALS['events'][] = ['mode', $path, $mode];
    return $GLOBALS['failure'] !== 'mode';
}
function chown($path, $owner) {
    $GLOBALS['events'][] = ['owner', $path, $owner];
    return $GLOBALS['failure'] !== 'owner';
}
function chgrp($path, $group) {
    $GLOBALS['events'][] = ['group', $path, $group];
    return $GLOBALS['failure'] !== 'group';
}
function pmssUserFileOpenedPathIs($path) { return $path === '/proc/self/fd/73'; }
// Existing writer safety/cleanup has separate integration coverage; intercept publication here.
function pmssReplaceUserFile($path, $content, $prepare) {
    if ($GLOBALS['occupied'] && $GLOBALS['existing'] === false) return false;
    $GLOBALS['bytes'] = $content;
    return $GLOBALS['published'] = $prepare('/proc/self/fd/73');
}
function pmssRootStageContext($path) { return ['/fixture', '/fixture/fixtur01', 'fixtur01']; }
function pmssAccountHomeIdentity($path) { return ['/fixture/fixtur01', 'fixtur01', 1201]; }
function posix_getpwnam($user) { return ['uid' => 1201, 'gid' => 1301]; }
function lstat($path) { return false; }
function pmssReplaceAccountFile($user, $home, $path, $content, $mode, $legacy) {
    $GLOBALS['events'][] = ['account', $user, $home, $path, $mode, $legacy];
    $GLOBALS['bytes'] = $content;
    return $GLOBALS['published'] = true;
}
PHP;
        foreach (['pmssReplaceUserFilePreservingMetadata', 'pmssUserFilePrepareMetadata',
            'pmssUserFileOwnershipSet', 'pmssNetworkPortInRange', 'pmssNetworkPortFileWrite'] as $function) {
            $script .= \pmssTestingFunctionExport($function, 'UserFileOwnershipFixture');
        }
        $script .= <<<'PHP'
$rows = [];
foreach (['fresh', 'existing', 'nonroot', 'parent-unreadable', 'occupied',
    'mode', 'owner', 'group', 'invalid-mode', '.rclonePort', '.qbittorrentPort'] as $case) {
    $GLOBALS['managed'] = $case[0] === '.';
    $GLOBALS['uid'] = $case === 'nonroot' ? 1201 : 0;
    $GLOBALS['existing'] = $case === 'existing' ? ['mode' => 0100600, 'uid' => 1202, 'gid' => 1302] : false;
    $GLOBALS['occupied'] = $case === 'existing' || $case === 'occupied';
    $GLOBALS['parent'] = $case === 'parent-unreadable' ? false : ['mode' => 0040750, 'uid' => 1201, 'gid' => 1301];
    $GLOBALS['failure'] = $case;
    $GLOBALS['events'] = []; $GLOBALS['published'] = false; $GLOBALS['bytes'] = null;
    $path = $GLOBALS['managed'] ? '/fixture/fixtur01/'.$case : '/fixture/port';
    $ok = pmssNetworkPortFileWrite($path, 25001, 1024, 65500, $case === 'invalid-mode' ? -1 : 0640);
    $rows[$case] = [$ok, $GLOBALS['published'], $GLOBALS['bytes'], $GLOBALS['events']];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(11, count($rows));
        $opened = '/proc/self/fd/73';
        foreach ($rows as $case => $row) {
            $events = [['mode', $opened, $case === 'existing' ? 0600 : 0640]];
            if (!in_array($case, ['nonroot', 'parent-unreadable', 'occupied', 'mode'], true)) {
                $events[] = ['owner', $opened, $case === 'existing' ? 1202 : 1201];
                $events[] = ['group', $opened, $case === 'existing' ? 1302 : 1301];
            }
            if ($case === 'invalid-mode') $events = [];
            if ($case[0] === '.') {
                $events = [['account', 'fixtur01', '/fixture/fixtur01', '/fixture/fixtur01/'.$case, 0640, true]];
            }
            if ($case === 'occupied') $events = [];
            $success = !in_array($case, ['mode', 'owner', 'group', 'invalid-mode', 'occupied'], true);
            $this->assertSame([$success, $success, $case === 'occupied' ? null : '25001', $events], $row, $case);
        }
    }
}
