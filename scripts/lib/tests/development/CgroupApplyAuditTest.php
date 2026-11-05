<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cgroup/directApply.php';

/** Audit append faults cannot become enforcement failures or expose private account rows. */
class CgroupApplyAuditTest extends TestCase
{
    public function testOldValueIsForTheSelectedDeviceOnly(): void
    {
        foreach ([['', null], ["8:1 900\n", null], ["8:1 900\n8:0 123\n", 123],
            ["8:0 0\n", 0], ["8:0 bad\n", null], ['8:0 '.str_repeat('9', 30), null],
            ["8:0 123 extra\n", null], ["8:0 123\0", null]] as [$raw, $expected]) {
            $this->assertSame($expected, \pmssCgroupDirectDeviceValueParse($raw, '8:0'));
        }
    }

    public function testNewAuditFileIsPrivateAndHasOneRowPerAppend(): void
    {
        $root = $this->pmssMakeTempDir('pmss-apply-audit-', 0700);
        $path = $root.'/audit.jsonl';
        $script = 'namespace CgroupAuditFileFixture; function posix_geteuid() { return 0; }';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/snapshot.php', 'CgroupAuditFileFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/directApply.php', 'CgroupAuditFileFixture');
        $script .= <<<'PHP'
$mask = umask(0);
$ok = pmssCgroupDirectAudit('fixture', 1000, 'read_iops', null, 200, '/fixture/cgroupIopsLimitApply.php');
$after = umask($mask);
$path = getenv('PMSS_CGROUP_APPLY_LOG');
$rows = file($path, FILE_IGNORE_NEW_LINES);
echo json_encode([$ok, fileperms($path) & 0777, $after, count($rows), json_decode($rows[0], true)]);
PHP;
        $result = $this->pmssRunInlinePhpJson($script, ['PMSS_CGROUP_APPLY_LOG' => $path, 'PMSS_DRY_RUN' => '0']);
        $this->assertSame([true, 0600, 0, 1], array_slice($result, 0, 4));
        $this->assertMatches('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $result[4]['ts']);
        unset($result[4]['ts']);
        $this->assertSame(['user' => 'fixture', 'uid' => 1000, 'control' => 'read_iops',
            'old' => null, 'new' => 200, 'source' => 'cgroupIopsLimitApply.php'], $result[4]);
    }

    public function testAuditFailuresWarnAndDryRunDoesNotOpenLog(): void
    {
        $script = <<<'PHP'
namespace CgroupAuditFaultFixture;
function syslog($level, $message) { $GLOBALS['warnings'][] = $message; }
function pmssStreamWriteAll($handle, $payload) { return false; }
function pmssRunSnapshotLogTask($script, $env, $path, $callback) {
    $GLOBALS['calls']++;
    if ($GLOBALS['mode'] === 'exception') throw new \RuntimeException('fixture');
    if ($GLOBALS['mode'] === 'error') throw new \Error('fixture');
    if ($GLOBALS['mode'] === 'short') return $callback(null);
    return 1;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/directApply.php', 'CgroupAuditFaultFixture');
        $script .= <<<'PHP'
$GLOBALS['calls'] = 0; $GLOBALS['warnings'] = []; $GLOBALS['mode'] = getenv('PMSS_TEST_AUDIT_MODE');
$ok = pmssCgroupDirectAudit('fixture', 1000, 'bfq_weight', 100, 125, 'cgroupBfqWeightApply.php');
echo json_encode([$ok, $GLOBALS['calls'], $GLOBALS['warnings']]);
PHP;
        foreach (['rc', 'short', 'exception', 'error'] as $mode) {
            $this->assertSame([false, 1, ['cgroup-apply audit log failed']], $this->pmssRunInlinePhpJson($script,
                ['PMSS_TEST_AUDIT_MODE' => $mode, 'PMSS_DRY_RUN' => '0']));
        }
        $this->assertSame([true, 0, []], $this->pmssRunInlinePhpJson($script, ['PMSS_DRY_RUN' => '1']));
    }
}
