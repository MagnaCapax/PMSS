<?php
/** Verify corrupt usage cannot authorize lowering in scripts/lib/cgroup/userSliceMemsw.php. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/userSliceMemsw.php';

class UserSliceMemswUsageInputSafetyTest extends TestCase
{
    public function testCorruptionAnywhereSaturatesBeforeCounterMatching(): void
    {
        $stat = "total_rss 5000\ntotal_shmem 2000\ntotal_swap 1000\ncache 0\n";
        // Include field names, separators, values, line endings and unrelated rows.
        for ($offset = 0; $offset <= strlen($stat); ++$offset) {
            $raw = substr($stat, 0, $offset)."\0".substr($stat, $offset);
            $this->assertSame(PHP_INT_MAX, \pmssUserSliceMemswAnonPlusSwap($raw), bin2hex($raw));
        }
        foreach (["\0", "garbage\0", "total_rss 5000\0\ntotal_rss 1\n",
            "total_rss 1\ntotal_swap 5000\0", "total_shmem 5000\0\ntotal_swap 0\n"] as $raw) {
            $this->assertSame(PHP_INT_MAX, \pmssUserSliceMemswAnonPlusSwap($raw), bin2hex($raw));
        }
    }

    public function testOrdinaryUsageKeepsExistingDefaultsAndFirstMatch(): void
    {
        foreach ([['', 0], ['garbage', 0], ["total_rss 0000\n", 0],
            ["total_rss 5\ntotal_shmem 2\ntotal_swap 1\n", 8],
            ["rss 999\ntotal_swap 7\n", 7], ["total_rss 3\ntotal_rss 5000\n", 3],
            ['total_swap 7', 7], ['total_rss '.PHP_INT_MAX, PHP_INT_MAX]] as [$raw, $expected]) {
            $this->assertSame($expected, \pmssUserSliceMemswAnonPlusSwap($raw), $raw);
        }
    }

    public function testMalformedFirstCounterCannotAuthorizeLowering(): void
    {
        foreach (['total_rss', 'total_shmem', 'total_swap'] as $key) {
            foreach (['', ' ', '-5000', '+5000', '5e3', '5000.0', '5000bytes',
                '5000 junk', '5000 ', ' 5000', "\t5000", "5000\r", "5000\v"] as $value) {
                // A later usable duplicate must not conceal the malformed observation.
                foreach ([$key.' '.$value."\n", $key.' '.$value."\n".$key." 1\n"] as $raw) {
                    $held = \pmssUserSliceMemswAnonPlusSwap($raw);
                    $this->assertSame(PHP_INT_MAX, $held, bin2hex($raw));
                    $this->assertSame([], \pmssUserSliceMemswPlan(4096, 8192, 8192, $held)['writes']);
                    foreach ([[8192, 4096, 4096], [-1, 4096, 4096], [4096, 4096, 4096]] as [$target, $memory, $memsw]) {
                        $this->assertSame(\pmssUserSliceMemswPlan($target, $memory, $memsw, 0),
                            \pmssUserSliceMemswPlan($target, $memory, $memsw, $held));
                    }
                }
            }
            foreach ([$key, $key."\n", $key."\t5000\n", $key."\r\n", $key."\v5000\n"] as $raw) {
                $this->assertSame(PHP_INT_MAX, \pmssUserSliceMemswAnonPlusSwap($raw), bin2hex($raw));
            }
        }
    }

    public function testCorruptionRetainsLoweringRefusalAndOtherPlans(): void
    {
        $held = \pmssUserSliceMemswAnonPlusSwap("total_rss 5000\0\n");
        $this->assertSame(['writes' => [], 'note' => 'tenants hold '.PHP_INT_MAX
            .' bytes of anonymous memory plus swap, above the bound 4096: memsw limit not written'],
            \pmssUserSliceMemswPlan(4096, 8192, 8192, $held));
        // Corrupt usage cannot block raising, rollback or an already matching bound.
        foreach ([[8192, 4096, 4096], [-1, 4096, 4096], [4096, 4096, 4096]] as [$target, $memory, $memsw]) {
            $this->assertSame(\pmssUserSliceMemswPlan($target, $memory, $memsw, 0),
                \pmssUserSliceMemswPlan($target, $memory, $memsw, $held));
        }
    }

    public function testCronRefusesCorruptUsageBeforeLiveOrDryRunWrites(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/cgroupUserSliceMemswApply.php');
        $start = strpos($source, '$DRY_RUN =');
        $this->assertTrue($start !== false);
        $script = <<<'PHP'
namespace MemswUsageInputFixture;
// No host account, systemd, sysfs or process operations may escape this fixture.
function posix_geteuid() { return 0; }
function is_file($path) { return true; }
function pmssReadSystemdProperties($unit, $names) { return ['MemoryMax' => getenv('PMSS_TEST_MEMSW_TARGET') ?: '4096']; }
function date($format) { return 'fixture-time'; }
function file_get_contents($path) {
    $GLOBALS['events'][] = ['read', basename($path)];
    return basename($path) === 'memory.stat' ? json_decode(getenv('PMSS_TEST_MEMSW_USAGE'), true) : '8192';
}
function pmssUserSliceMemswWrite($file, $value) {
    $GLOBALS['events'][] = ['write', basename($file), $value];
    return true;
}
function pmssCliReturnWithStderr($message, $code) {
    throw new \LogicException('Unexpected preflight refusal');
}
$GLOBALS['events'] = [];
ob_start();
register_shutdown_function(static function () {
    $output = ob_get_clean();
    echo json_encode([$GLOBALS['events'], $output]);
});
$argv = getenv('PMSS_TEST_MEMSW_DRY_RUN') === '1' ? ['--dry-run'] : [];
PHP;
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/cgroup/userSliceMemsw.php'), true).';';
        $script .= 'eval('.var_export('declare(strict_types=1); namespace MemswUsageInputFixture;'.
            substr($source, $start), true).');';
        foreach ([false, "\0total_rss 5000\n", "total_rss 5000\0\n", "total_shmem 5000\0\n",
            "total_swap 5000\0\n", "total_rss 5000\ncache 0\0\n",
            "total_rss -5000\n", "total_shmem 5e3\n", "total_swap 5000bytes\n",
            "total_rss\n", "total_shmem\t5000\n", "total_swap 5000 \ntotal_swap 1\n"] as $raw) {
            foreach (['0', '1'] as $dryRun) {
                $result = $this->pmssExecInlinePhp($script, [
                    'PMSS_TEST_MEMSW_USAGE' => json_encode($raw), 'PMSS_TEST_MEMSW_DRY_RUN' => $dryRun,
                    'PMSS_TEST_MEMSW_TARGET' => '4096',
                ]);
                $this->assertSame(0, $result['rc'], $result['output']);
                $this->assertSame([
                    [['read', 'memory.limit_in_bytes'], ['read', 'memory.memsw.limit_in_bytes'], ['read', 'memory.stat']],
                    'fixture-time tenants hold '.PHP_INT_MAX
                        ." bytes of anonymous memory plus swap, above the bound 4096: memsw limit not written\n",
                ], json_decode($result['output'], true));
            }
        }
        // Failed reads still permit raises, rollback and convergence; successful reads keep their plans.
        foreach ([[false, '16384', '16384', ['memory.memsw.limit_in_bytes', 'memory.limit_in_bytes']],
            [false, 'infinity', '-1', ['memory.memsw.limit_in_bytes', 'memory.limit_in_bytes']],
            [false, '8192', '8192', []], ['', '4096', '4096', ['memory.limit_in_bytes', 'memory.memsw.limit_in_bytes']],
            ["total_rss 4096\n", '4096', '4096', ['memory.limit_in_bytes', 'memory.memsw.limit_in_bytes']]] as [$raw, $target, $value, $files]) {
            foreach (['0', '1'] as $dryRun) {
                $events = [['read', 'memory.limit_in_bytes'], ['read', 'memory.memsw.limit_in_bytes'], ['read', 'memory.stat']];
                $output = '';
                foreach ($files as $file) {
                    if ($dryRun === '0') $events[] = ['write', $file, $value];
                    $output .= ($dryRun === '1' ? '[DRY-RUN]' : 'fixture-time')." user.slice $file: 8192 -> $value\n";
                }
                $result = $this->pmssExecInlinePhp($script, [
                    'PMSS_TEST_MEMSW_USAGE' => json_encode($raw), 'PMSS_TEST_MEMSW_DRY_RUN' => $dryRun,
                    'PMSS_TEST_MEMSW_TARGET' => $target,
                ]);
                $this->assertSame(0, $result['rc'], $result['output']);
                $this->assertSame([$events, $output], json_decode($result['output'], true));
            }
        }
    }
}
