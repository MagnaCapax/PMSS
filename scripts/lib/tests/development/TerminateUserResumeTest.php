<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise refusal boundaries with deterministic stat/NSS snapshots, never real accounts. */
class TerminateUserResumeTest extends TestCase
{
    public function testLeftoverProofMatrix(): void
    {
        $script = 'namespace ResumeProofFixture;';
        $script .= <<<'PHP'
function pmssUsernameIsValid($name) { return $name === 'user1234'; }
function stat($path) { return $GLOBALS['case'] === 'home_stat_failed' ? false : ['dev' => 7]; }
function lstat($path) {
    $case = $GLOBALS['case'];
    if ($case === 'none') return false;
    $index = array_search($path, array_values($GLOBALS['paths']), true);
    if (in_array($case, ['home_only', 'backup_only', 'superseded_only'], true)
        && $index !== array_search($case, ['home_only', 'backup_only', 'superseded_only'], true)) return false;
    return ['mode' => $case === 'symlink'.$index ? 0120777 : ($case === 'file' ? 0100600 : 0040700),
        'dev' => $case === 'foreign'.$index ? 8 : 7,
        'uid' => $case === 'root' ? 0 : ($case === 'low' ? 999 : ($case === 'mismatch' && $index === 2 ? 1001 : 1000))];
}
function realpath($path) { return $GLOBALS['case'] === 'redirected' ? '/elsewhere' : $path; }
function function_exists($name) { return $GLOBALS['case'] !== 'missing_'.$name; }
function is_readable($path) { return $GLOBALS['case'] !== 'unreadable'; }
function file_get_contents($path) { return $GLOBALS['case'] === 'read_failed' ? false : 'passwd'; }
function posix_getpwnam($name) {
    if ($GLOBALS['case'] === 'name_exception') throw new \RuntimeException('NSS');
    return $GLOBALS['case'] === 'name_present' ? ['uid' => 1000] : false;
}
function posix_getpwuid($uid) {
    if ($GLOBALS['case'] === 'uid_error') throw new \Error('NSS');
    if ($GLOBALS['case'] === 'reassigned') return ['uid' => 1000, 'name' => 'liveuser'];
    if ($GLOBALS['case'] === 'malformed') return [];
    return false;
}
function posix_get_last_error() { return $GLOBALS['case'] === 'lookup_errno' ? 5 : ($GLOBALS['case'] === 'enoent' ? 2 : 0); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/termination/filesystem.php', 'ResumeProofFixture');
        $cases = ['none' => 'nothing_to_resume', 'home_stat_failed' => 'lookup_failed',
            'home_only' => '', 'backup_only' => '', 'superseded_only' => '', 'all' => '', 'enoent' => '',
            'symlink0' => 'symlink', 'symlink1' => 'symlink', 'symlink2' => 'symlink',
            'foreign0' => 'not_same_fs', 'foreign1' => 'not_same_fs', 'foreign2' => 'not_same_fs',
            'redirected' => 'unsafe_path', 'file' => 'not_directory', 'root' => 'uid_out_of_range',
            'low' => 'uid_out_of_range', 'mismatch' => 'owner_mismatch',
            'missing_posix_getpwnam' => 'lookup_failed', 'missing_posix_getpwuid' => 'lookup_failed',
            'missing_posix_get_last_error' => 'lookup_failed', 'unreadable' => 'lookup_failed',
            'read_failed' => 'lookup_failed', 'name_exception' => 'lookup_failed', 'uid_error' => 'lookup_failed',
            'name_present' => 'lookup_failed', 'lookup_errno' => 'lookup_failed', 'malformed' => 'lookup_failed',
            'reassigned' => 'uid_reassigned:liveuser'];
        $script .= '$paths = ["home" => "/home/user1234", "user_backup" => "/home/backup-user1234",'
            .'"user_backup_superseded" => "/home/backup-user1234.superseded"]; $results = [];';
        $script .= 'foreach ('.var_export(array_keys($cases), true).' as $case) $results[] = pmssTerminateUserResumeUid("user1234", $paths);';
        $script .= '$case = "all"; $results[] = pmssTerminateUserResumeUid("../root", $paths);'
            .'$paths["home"] = "/home/user12345"; $results[] = pmssTerminateUserResumeUid("user1234", $paths); echo json_encode($results);';
        $expected = [];
        foreach ($cases as $reason) $expected[] = ['uid' => $reason === '' ? 1000 : null, 'reason' => $reason];
        $expected[] = $expected[] = ['uid' => null, 'reason' => 'unsafe_path'];
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }

    public function testRunningTerminateUsesExactArgvAndExcludesSelf(): void
    {
        $script = 'namespace ResumeProcessFixture;';
        $script .= <<<'PHP'
function glob($pattern, $flags) { return $GLOBALS['paths']; }
function getmypid() { return 42; }
function file_get_contents($path) { return $GLOBALS['raw']; }
function is_dir($path) { return $GLOBALS['exists']; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/termination/filesystem.php', 'ResumeProcessFixture');
        $cases = [
            [false, '', true, null], [[], '', true, null],
            [['/proc/42'], "php\0/scripts/terminateUser.php\0user1234\0", true, false],
            [['/proc/43'], "php\0/scripts/terminateUser.php\0--confirm\0user1234\0", true, true],
            [['/proc/43'], "php7.3\0/scripts/terminateUser.php\0--dry-run\0user1234\0", true, true],
            [['/proc/43'], "php\0/scripts/terminateUser.php\0user12345\0", true, false],
            [['/proc/43'], "php\0/scripts/terminateUser.php\0other\0user1234\0", true, false],
            [['/proc/43'], "sh\0-c\0php /scripts/terminateUser.php user1234\0", true, false],
            [['/proc/43'], "php\0/scripts/other.php\0user1234\0", true, false],
            [['/proc/43'], '', true, false], [['/proc/43'], false, true, null], [['/proc/43'], false, false, false],
        ];
        $script .= '$results = []; foreach ('.var_export($cases, true).' as [$paths, $raw, $exists])'
            .' $results[] = pmssTerminateUserResumeRunning("user1234"); echo json_encode($results);';
        $this->assertSame(array_column($cases, 3), $this->pmssRunInlinePhpJson($script));
    }

    /** Exercise the real libc/PHP absent-record errno, with only filesystem metadata stubbed. */
    public function testRealPosixLookupCanProveAnUnassignedUid(): void
    {
        if (!function_exists('posix_getpwuid') || !function_exists('posix_getpwnam')) throw new SkipTest('POSIX unavailable');
        $uid = 2000000;
        while (posix_getpwuid($uid) !== false) ++$uid;
        if (posix_getpwnam('qatstzz') !== false) throw new SkipTest('Fixture name already assigned');
        $script = 'namespace ResumeNativeNssFixture; $uid = '.$uid.';';
        $script .= <<<'PHP'
function pmssUsernameIsValid($name) { return $name === 'qatstzz'; }
function stat($path) { return ['dev' => 7]; }
function lstat($path) { return ['dev' => 7, 'mode' => 0040700, 'uid' => $GLOBALS['uid']]; }
function realpath($path) { return $path; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/termination/filesystem.php', 'ResumeNativeNssFixture');
        $script .= 'echo json_encode(pmssTerminateUserResumeUid("qatstzz", ["home" => "/home/qatstzz",'
            .'"user_backup" => "/home/backup-qatstzz", "user_backup_superseded" => "/home/backup-qatstzz.superseded"]));';
        $this->assertSame(['uid' => $uid, 'reason' => ''], $this->pmssRunInlinePhpJson($script));
    }
}
