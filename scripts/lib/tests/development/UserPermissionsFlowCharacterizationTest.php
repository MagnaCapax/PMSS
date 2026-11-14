<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Permission convergence traces use synthetic observations and never run a repair command. */
class UserPermissionsFlowCharacterizationTest extends TestCase
{
    /** Freeze phase order, independent owner/mode guards and ordinary failed repair results. */
    public function testRepairTraceAndEveryThrowableBoundary(): void
    {
        $script = <<<'PHP'
namespace PermissionFlowFixture;
function record($row) {
    $GLOBALS['calls'][] = $row;
    if (count($GLOBALS['calls']) === $GLOBALS['throwAt']) throw $GLOBALS['failure'];
}
function pmssUserPermissionsHomePrepare($user, $home, $ids) { record(['prepare', $user, $home, $ids]); }
function pmssTrafficDataPaths($user) { return ['normal' => '/fixture/normal', 'local' => '/fixture/missing']; }
function pmssUserPermissionsPolicy($user, $paths) {
    return [
        [['/fixture/trusted', 0640], ['/fixture/untrusted', 0600], ['/fixture/config', 0770, true, true]],
        [['/fixture/trusted', 'root:fixtur01'], ['/fixture/untrusted', 'root:fixtur01'],
            ['/fixture/plain', 'root:root'], ['/fixture/lighttpd', 'fixtur01:fixtur01', true]],
    ];
}
function pmssUserRootArtifactWriters($user, $paths) {
    return ['/fixture/trusted' => 'writer-one', '/fixture/untrusted' => 'writer-two', '/fixture/normal' => 'traffic'];
}
function clearstatcache($real, $path) {}
function lstat($path) { return $path === '/fixture/missing' ? false : ['uid' => $path === '/fixture/untrusted' ? 1001 : 0]; }
function pmssUserRootArtifactRepairable($user, $path, $stat, $writer) {
    record(['gate', $path, $stat, $writer]); return $stat['uid'] === 0;
}
function pmssTrafficSetImmutable($path, $flag) { record(['immutable', $path, $flag]); return false; }
function pmssUserPermissionsOwnerWalk($user, $ids, $paths) { record(['walk', $user, $ids, $paths]); }
function pmssUserPermissionsOpenedGroupSet($user, $home, $path, $group, $uid) {
    record(['group', $path, $group, $uid]); return false;
}
function pmssUserPermissionsOwnerApply($home, $path, $owner, $predicate = '', $recursive = false) {
    record(['owner', $path, $owner, $predicate, $recursive]);
}
function pmssUserPermissionsAccountRun($user, $home, $paths, $command) { record(['account', $paths, $command]); }
function pmssUserPermissionsModeApply($user, $home, $uid, $path, $mode, $recursive = false, $strip = false) {
    record(['mode', $path, $mode, $recursive, $strip]);
}
function file_exists($path) { record(['exists', $path]); return $GLOBALS['ssh']; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/permissions.php', 'PermissionFlowFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([false, true] as $ssh) {
    $GLOBALS['calls'] = []; $GLOBALS['throwAt'] = 0;
    pmssUserPermissionsConverge('fixtur01', '/home/fixtur01', ['uid' => 1001, 'gid' => 1002]);
    $trace = $GLOBALS['calls']; $faults = [];
    for ($position = 1; $position <= count($trace); $position++) {
        foreach ([new \RuntimeException('fixture fault'), new \Error('fixture fault')] as $failure) {
            $GLOBALS['calls'] = []; $GLOBALS['throwAt'] = $position; $caught = null;
            try { pmssUserPermissionsConverge('fixtur01', '/home/fixtur01', ['uid' => 1001, 'gid' => 1002]); }
            catch (\Throwable $error) { $caught = $error; }
            $faults[] = [$position, $caught === $failure, $GLOBALS['calls']];
        }
    }
    $results[] = [$trace, $faults];
}
echo json_encode($results);
PHP;
        $ids = ['uid' => 1001, 'gid' => 1002];
        $expected = [
            ['prepare', 'fixtur01', '/home/fixtur01', $ids],
            ['gate', '/fixture/trusted', ['uid' => 0], 'writer-one'],
            ['gate', '/fixture/untrusted', ['uid' => 1001], 'writer-two'],
            ['gate', '/fixture/normal', ['uid' => 0], 'traffic'],
            ['immutable', '/fixture/normal', false],
            ['walk', 'fixtur01', $ids, ['normal' => '/fixture/normal', 'local' => '/fixture/missing']],
            ['group', '/fixture/trusted', 'fixtur01', 0],
            ['owner', '/fixture/plain', 'root:root', '', false],
            ['owner', '/fixture/lighttpd', 'fixtur01:fixtur01', "\( -not -user 'fixtur01' -o -not -group 'fixtur01' \)", true],
            ['account', ['/home/fixtur01'],
                "find '/home/fixtur01' -xdev -ignore_readdir_race \( -path '/home/fixtur01/.local' -o -path '/home/fixtur01/.config' -o -type d -not -uid 1001 \) -prune -o -type d -not -perm 0750 -exec chmod 0750 {} +"],
            ['account', ['/home/fixtur01/www'],
                "find '/home/fixtur01/www' -xdev -ignore_readdir_race -type d -not -uid 1001 -prune -o -type f -links 1 -uid 1001 -perm /0111 -exec chmod a-x {} +"],
            ['mode', '/fixture/trusted', 0640, false, false],
            ['mode', '/fixture/config', 0770, true, true],
            ['exists', '/home/fixtur01/.ssh'],
        ];
        foreach ($this->pmssRunInlinePhpJson($script) as $ssh => [$trace, $faults]) {
            $complete = $expected;
            if ($ssh) $complete[] = ['mode', '/home/fixtur01/.ssh', 0750, false, false];
            $complete[] = ['immutable', '/fixture/normal', true];
            $this->assertSame($complete, $trace);
            foreach ($faults as [$position, $identity, $calls]) {
                $this->assertTrue($identity);
                $this->assertSame(array_slice($complete, 0, $position), $calls);
            }
            $this->assertSame(count($complete) * 2, count($faults));
            if (!$ssh) {
                // Run the actual rendered directory walk only in a disposable home.
                $home = $this->pmssMakeTempDir('pmss-permission-data-');
                foreach (['data/nested/child', '.local/nested', '.config/nested'] as $relative) {
                    $this->pmssEnsureDir($home.'/'.$relative);
                }
                $this->pmssWriteFile($home.'/data/nested/file', 'payload');
                foreach (['data', 'data/nested', 'data/nested/child', '.local/nested', '.config/nested', 'data/nested/file'] as $relative) {
                    $this->assertTrue(chmod($home.'/'.$relative, 0777));
                }
                $command = str_replace(['/home/fixtur01', '-uid 1001'], [$home, '-uid '.posix_geteuid()], $trace[9][2]);
                $result = $this->pmssExecShellCommand($command);
                $this->assertSame(0, $result['rc'], $result['output']);
                foreach (['data' => 0750, 'data/nested' => 0750, 'data/nested/child' => 0750,
                    '.local/nested' => 0777, '.config/nested' => 0777, 'data/nested/file' => 0777] as $relative => $mode) {
                    clearstatcache(true, $home.'/'.$relative);
                    $this->assertSame($mode, fileperms($home.'/'.$relative) & 07777, $relative);
                }
            }
        }
    }

    /** Lock complete owner-walk command bytes, pruning order and subordinate-ID reporting. */
    public function testOwnershipWalkCommandAndSubordinateRanges(): void
    {
        $script = <<<'PHP'
namespace PermissionWalkFixture;
function pmssSubordinateIdRanges($user, $id, $kind) {
    $GLOBALS['calls'][] = ['ranges', $user, $id, $kind]; return $GLOBALS['ranges'][$kind];
}
function pmssUserLog($user, $message) { $GLOBALS['calls'][] = ['log', $user, $message]; }
function pmssUserPermissionsRun($command) { $GLOBALS['calls'][] = ['command', $command]; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/permissionsOwnership.php', 'PermissionWalkFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([['uid' => [], 'gid' => []], ['uid' => [[100000, 65536]], 'gid' => [[200000, 10]]]] as $ranges) {
    $GLOBALS['calls'] = [];
    pmssUserPermissionsOwnerWalk('fixtur01', ['uid' => 1001, 'gid' => 1002],
        ['normal' => '/fixture/normal', 'local' => '/fixture/local',
            'ingress' => '/fixture/ingress', 'ingressLocal' => '/fixture/ingress-local']);
    $results[] = $GLOBALS['calls'];
}
echo json_encode($results);
PHP;
        $command = "find '/home/fixtur01' -xdev -ignore_readdir_race -mindepth 1 -path '/home/fixtur01/.local' -prune -o"
            ." -path '/home/fixtur01/data' -prune -o";
        foreach (['/home/fixtur01/.quota', '/home/fixtur01/.resourceData', '/home/fixtur01/.throttle',
            '/home/fixtur01/.billingServiceId', '/home/fixtur01/.billingId', '/home/fixtur01/.billingClientId',
            '/home/fixtur01/.notifyEmail', '/fixture/normal', '/fixture/local', '/fixture/ingress', '/fixture/ingress-local',
            '/home/fixtur01/.rtorrent.rc', '/home/fixtur01/www/rutorrent/conf/config.php',
            '/home/fixtur01/.bashrc', '/home/fixtur01/.profile', '/home/fixtur01/.lighttpd.conf'] as $path) {
            $command .= " -not -path '".$path."'";
        }
        $command .= ' -not -type l \( ! -type f -o -links 1 \)';
        foreach ($this->pmssRunInlinePhpJson($script) as $withRanges => $trace) {
            $expected = [['ranges', 'fixtur01', 1001, 'uid'], ['ranges', 'fixtur01', 1002, 'gid']];
            if ($withRanges) $expected[] = ['log', 'fixtur01',
                'userPermissions: honoured 1 subordinate uid ranges and 1 subordinate gid ranges'];
            $suffix = $withRanges
                ? " \( -not \( -uid 1001 -o \( -uid +99999 -uid -165536 \) \) -o -not \( -gid 1002 -o \( -gid +199999 -gid -200010 \) \) \)"
                : ' \( -not -uid 1001 -o -not -gid 1002 \)';
            $expected[] = ['command', $command.$suffix." -execdir chown -h '1001:1002' {} +"];
            $this->assertSame($expected, $trace);
        }
    }

    /** Home ownership checks and bin fallbacks retain their short circuits and diagnostics. */
    public function testHomePreparationTrace(): void
    {
        $script = <<<'PHP'
namespace PermissionHomeFixture;
function fileowner($path) { return $GLOBALS['owner']; }
function filegroup($path) { return $GLOBALS['group']; }
function fwrite($stream, $message) { $GLOBALS['calls'][] = ['stderr', $message]; return strlen($message); }
function pmssUserPermissionsOwnerApply($home, $path, $owner) { $GLOBALS['calls'][] = ['owner', $path, $owner]; }
function pmssUserLog($user, $message) { $GLOBALS['calls'][] = ['log', $message]; }
function is_dir($path) { return $GLOBALS['directories']; }
function pmssAccountPathRun($user, $home, $paths, $command) {
    $GLOBALS['calls'][] = ['mkdir', $paths, $command]; return $GLOBALS['created'];
}
function pmssEnsureUserHomeDir($user, $home, $relative, $mode) {
    $GLOBALS['calls'][] = ['fallback', $relative, $mode]; return $GLOBALS['fallback'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/permissionsHome.php', 'PermissionHomeFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([[1001, 1002, true, false, false], [false, 0, true, false, false],
    [0, false, true, false, false], [0, 0, false, true, false],
    [1001, 1002, false, false, true], [1001, 1002, false, false, false]] as $case) {
    [$owner, $group, $directories, $created, $fallback] = $case;
    $GLOBALS['calls'] = [];
    pmssUserPermissionsHomePrepare('fixtur01', '/home/fixtur01', ['uid' => 1001, 'gid' => 1002]);
    $results[] = $GLOBALS['calls'];
}
echo json_encode($results);
PHP;
        $created = $fallback = $failed = [];
        foreach (['.bin', 'bin'] as $name) {
            $mkdir = ['mkdir', ['/home/fixtur01/'.$name], "mkdir -m 750 -- '/home/fixtur01/".$name."'"];
            $log = ['log', 'userPermissions: created ~/'.$name.' with safe ownership'];
            $created[] = $mkdir; $created[] = $log;
            $fallback[] = $mkdir; $fallback[] = ['fallback', $name, 0750]; $fallback[] = $log;
            $failed[] = $mkdir; $failed[] = ['fallback', $name, 0750];
        }
        $this->assertSame([[], [], [], array_merge([
            ['stderr', "[WARN] Fixing home directory ownership for /home/fixtur01 (uid=0 gid=0 expected uid=1001 gid=1002)\n"],
            ['owner', '/home/fixtur01', '1001:1002'],
            ['log', 'userPermissions: fixed home ownership (uid=1001 gid=1002, was uid=0 gid=0)'],
        ], $created), $fallback, $failed], $this->pmssRunInlinePhpJson($script));
    }

    /** Keep the complete declarative policy order and the only recursive owner contract. */
    public function testModeAndOwnerPolicySnapshot(): void
    {
        require_once $this->pmssRepoPath('scripts/lib/user/permissionsPolicy.php');
        $home = '/home/fixtur01';
        $traffic = ['normal' => '/fixture/normal', 'local' => '/fixture/local',
            'ingress' => '/fixture/ingress', 'ingressLocal' => '/fixture/ingress-local'];
        [$modes, $owners] = \pmssUserPermissionsPolicy('fixtur01', $traffic);
        $expectedModes = [
            ['', 0770], ['/.bin', 0750, true], ['/bin', 0750, true], ['/.viminfo', 0640], ['/.quota', 0640],
            ['/.billingServiceId', 0640], ['/.billingId', 0640], ['/.billingClientId', 0640], ['/.notifyEmail', 0640],
            ['/.profile', 0644], ['/.bash_history', 0640], ['/.bashrc', 0644], ['/.bashrc.user', 0640],
            ['/radarr-sonarr.txt', 0644], ['/.tmp', 0770], ['/.config', 0770, true, true],
            ['/.trafficData', 0640], ['/.trafficDataIngress', 0640], ['/.rtorrent.rc', 0644],
            ['/watch', 0750, true], ['/session', 0750, true], ['/data', 0750], ['/.*.php', 0750],
            ['/.scriptsInc.php', 0640], ['/.lighttpd.conf', 0644], ['/.lighttpd', 0775],
            ['/.lighttpd/php.ini', 0640], ['/.lighttpd/.htpasswd', 0640], ['/.lighttpd/compress', 0770],
            ['/.lighttpd/upload', 0770], ['/www/rutorrent/conf/config.php', 0754],
            ['/www/rutorrent/plugins/create/inner.sh', 0750], ['/www/rutorrent/plugins/create/buildtorrent.sh', 0750],
            ['/www/rutorrent/plugins/create/createtorrent.sh', 0750], ['/www/rutorrent/plugins/create/mktorrent.sh', 0750],
            ['/www/rutorrent/plugins/create/transmissioncli.sh', 0750], ['/www/rutorrent/plugins/create/transmissioncreate.sh', 0750],
            ['/www/rutorrent/plugins/erasedata/fin.sh', 0750], ['/www/rutorrent/plugins/unpack/unall_dir.sh', 0750],
            ['/www/rutorrent/plugins/unpack/unrar_dir.sh', 0750], ['/www/rutorrent/plugins/unpack/unrar_file.sh', 0750],
            ['/www/rutorrent/plugins/unpack/unzip_dir.sh', 0750], ['/www/rutorrent/plugins/unpack/unzip_file.sh', 0750],
            ['/.irssi', 0750], ['/.sync', 0750],
        ];
        foreach ($expectedModes as &$row) $row[0] = $home.$row[0];
        unset($row);
        $this->assertSame($expectedModes, $modes);
        $this->assertSame([
            [$home.'/.lighttpd/.htpasswd', 'fixtur01:fixtur01'], [$home.'/.lighttpd', 'fixtur01:fixtur01', true],
            [$home.'/.quota', 'root:fixtur01'], [$home.'/.billingServiceId', 'root:fixtur01'],
            [$home.'/.billingId', 'root:fixtur01'], [$home.'/.billingClientId', 'root:fixtur01'],
            [$home.'/.notifyEmail', 'root:fixtur01'], ['/fixture/normal', 'root:fixtur01'],
            ['/fixture/local', 'root:fixtur01'], ['/fixture/ingress', 'root:fixtur01'], ['/fixture/ingress-local', 'root:fixtur01'],
            [$home.'/data', 'fixtur01:fixtur01'], [$home.'/www/rutorrent/share/users/fixtur01/settings', 'fixtur01:fixtur01'],
            [$home.'/www/rutorrent/share/users/fixtur01/settings/retrackers.dat', 'fixtur01:fixtur01'],
            [$home.'/www/rutorrent/share/users/fixtur01', 'fixtur01:fixtur01'],
            [$home.'/www/rutorrent/share/users/fixtur01/torrents', 'fixtur01:fixtur01'], [$home.'/.rtorrent.rc', 'root:root'],
            [$home.'/www/rutorrent/conf/config.php', 'root:root'], [$home.'/.bashrc', 'root:root'],
            [$home.'/.profile', 'root:root'], [$home.'/.lighttpd.conf', 'root:root'],
        ], $owners);
    }
}
