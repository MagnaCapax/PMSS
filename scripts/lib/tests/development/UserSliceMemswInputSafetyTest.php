<?php
/** Verify sysfs limit parsing before scripts/cron/cgroupUserSliceMemswApply.php plans writes. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/userSliceMemsw.php';

class UserSliceMemswInputSafetyTest extends TestCase
{
    public function testCorruptLimitsKeepTheUnreadableFallback(): void
    {
        foreach (['', ' ', '0', '-1', '+4096', '4096junk', '4096.5', '4096e3',
            '0x1000', '4096 8192', 'infinity', (string) PHP_INT_MAX.'0',
            '9223372036854775808', "\0".'4096', "4096\0", "40\0".'96',
            "4096\n\0", " \0".'4096'."\0\n"] as $raw) {
            $this->assertSame(0, \pmssUserSliceMemswLimitParse($raw), bin2hex($raw));
        }
    }

    public function testValidKernelLimitsKeepTheirExactValues(): void
    {
        foreach ([['1', 1], ["4096\n", 4096], [" \t0004096\r\n", 4096],
            ['6715080704', 6715080704], ['9223372036854771712', 9223372036854771712],
            [(string) PHP_INT_MAX, PHP_INT_MAX], ['000'.PHP_INT_MAX, PHP_INT_MAX],
            ['0000', 0]] as [$raw, $expected]) {
            $this->assertSame($expected, \pmssUserSliceMemswLimitParse($raw), $raw);
        }
    }

    public function testCronRefusesEitherUnreadableLimitBeforeUsageOrWrites(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/cgroupUserSliceMemswApply.php');
        $start = strpos($source, '$DRY_RUN =');
        $this->assertTrue($start !== false);
        $script = 'namespace MemswLimitInputFixture; require_once '.
            var_export($this->pmssRepoPath('scripts/lib/cgroup/userSliceMemsw.php'), true).';';
        $script .= <<<'PHP'
// Intercept every host observation and write while preserving the real CLI exits.
$GLOBALS['limits'] = json_decode(getenv('PMSS_TEST_MEMSW_LIMITS'), true);
$GLOBALS['events'] = [];
function posix_geteuid() { return 0; }
function is_file($path) { return true; }
function pmssReadSystemdProperties($unit, $names) { return ['MemoryMax' => '4096']; }
function file_get_contents($path) {
    $file = basename($path);
    $GLOBALS['events'][] = ['read', $file];
    return $GLOBALS['limits'][$file] ?? '';
}
function pmssUserSliceMemswWrite($file, $value) {
    $GLOBALS['events'][] = ['write', $file, $value];
    return false;
}
function pmssCliReturnWithStderr($message, $code) {
    $GLOBALS['events'][] = ['error', $message, $code];
    return $code;
}
register_shutdown_function(static function () { echo json_encode($GLOBALS['events']); });
$argv = [];
PHP;
        $script .= 'eval('.var_export('declare(strict_types=1); namespace MemswLimitInputFixture;'.
            substr($source, $start), true).');';
        foreach (['memory.limit_in_bytes', 'memory.memsw.limit_in_bytes'] as $file) {
            foreach ([false, '', '0', '4096junk', '4096.5', "4096\0", (string) PHP_INT_MAX.'0'] as $raw) {
                $limits = array_fill_keys(['memory.limit_in_bytes', 'memory.memsw.limit_in_bytes'], '8192');
                $limits[$file] = $raw;
                $result = $this->pmssExecInlinePhp($script, ['PMSS_TEST_MEMSW_LIMITS' => json_encode($limits)]);
                $this->assertSame(1, $result['rc'], $result['output']);
                $memory = $file === 'memory.limit_in_bytes' ? 0 : 8192;
                $memsw = $file === 'memory.memsw.limit_in_bytes' ? 0 : 8192;
                $this->assertSame([
                    ['read', 'memory.limit_in_bytes'], ['read', 'memory.memsw.limit_in_bytes'],
                    ['error', "ERROR: unreadable user.slice MemoryMax or limits (target=4096 memory=$memory memsw=$memsw)\n", 1],
                ], json_decode($result['output'], true));
            }
        }
    }
}
