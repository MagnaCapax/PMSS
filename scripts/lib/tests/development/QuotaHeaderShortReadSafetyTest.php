<?php
/** Verify short quota reads cannot authorize fresh files without opened-inode size evidence. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class QuotaHeaderShortReadSafetyTest extends TestCase
{
    public function testShortReadsRequireVerifiedTruncationAndAlwaysClose(): void
    {
        $cases = [];
        foreach (['user' => '111fc0d9', 'group' => '2719c0d9'] as $type => $magic) {
            $valid = $magic.'01000000';
            // Every short length is tested against absent, malformed and sufficient sizes.
            foreach (range(0, 7) as $length) {
                $header = substr($valid, 0, $length * 2);
                foreach ([false, [], ['size' => null], ['size' => -1], ['size' => '0'],
                    ['size' => 0.0], ['size' => false], ['size' => []],
                    ['size' => 8], ['size' => 4096], ['size' => PHP_INT_MAX]] as $stat) {
                    $cases[] = [$type, $header, $stat, null, null, true, false];
                }
                foreach (range(0, 7) as $size) {
                    $cases[] = [$type, $header, ['size' => $size], null, null, true, true];
                }
                // Refusals and genuine truncation both retain the close-result guard.
                foreach ([false, ['size' => 0], ['size' => 8]] as $stat) {
                    $cases[] = [$type, $header, $stat, null, null, false, false];
                }
                foreach (['exception', 'error'] as $kind) {
                    foreach ([null, $kind] as $statFault) {
                        foreach ([null, $kind] as $closeFault) {
                            $cases[] = [$type, $header, ['size' => 0], $statFault, $closeFault, true, true];
                        }
                    }
                }
            }
            // Full reads never need a size probe, even when that probe would throw.
            foreach ([$magic.'00000000' => false, $valid => false,
                $magic.'02000000' => true, '0000000000000000' => true] as $header => $fresh) {
                $cases[] = [$type, $header, false, 'error', null, true, $fresh];
                $cases[] = [$type, $header, false, 'error', null, false, false];
            }
            $cases[] = [$type, null, false, 'error', null, true, false];
        }

        $script = <<<'PHP'
namespace QuotaHeaderShortReadFixture;
// Only private memory streams are acquired; quota commands and live files are never used.
function is_file($path) { return true; }
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode];
    return $GLOBALS['stream'] = \fopen('php://temp', 'w+');
}
function fread($handle, $length) {
    $GLOBALS['events'][] = ['read', $handle === $GLOBALS['stream'], $length];
    return $GLOBALS['header'] === null ? false : hex2bin($GLOBALS['header']);
}
function fstat($handle) {
    $GLOBALS['events'][] = ['stat', $handle === $GLOBALS['stream']];
    if ($GLOBALS['statFailure'] !== null) throw $GLOBALS['statFailure'];
    return $GLOBALS['stat'];
}
function fclose($handle) {
    $GLOBALS['events'][] = ['close', $handle === $GLOBALS['stream']];
    \fclose($handle);
    if ($GLOBALS['closeFailure'] !== null) throw $GLOBALS['closeFailure'];
    return $GLOBALS['closed'];
}
function pmssQuotaHomeDeclaredTypes(...$args) { return [$GLOBALS['type']]; }
function pmssQuotaCommandRun($command, $runner) {
    return ['rc' => 0, 'output' => $GLOBALS['type'].' quota on /home (/dev/fixture0) is off'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/quota/recovery.php', 'QuotaHeaderShortReadFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_QUOTA_SHORT_READ'), true) as [$type, $header, $stat, $statFault, $closeFault, $closed]) {
    $GLOBALS['type'] = $type; $GLOBALS['header'] = $header; $GLOBALS['stat'] = $stat;
    $GLOBALS['closed'] = $closed; $GLOBALS['events'] = [];
    foreach (['stat' => $statFault, 'close' => $closeFault] as $stage => $fault) {
        $GLOBALS[$stage.'Failure'] = $fault === null ? null
            : ($fault === 'error' ? new \Error($stage) : new \RuntimeException($stage));
    }
    $commands = $caught = null;
    try { $commands = pmssQuotaFixCheckCommands(false, null, null, '/fixture/fstab', '/fixture/quota'); }
    catch (\Throwable $error) { $caught = $error; }
    $short = $header !== null && strlen($header) < 16;
    $failure = $GLOBALS['closeFailure'] ?? ($short ? $GLOBALS['statFailure'] : null);
    $results[] = [$commands, $caught === $failure, is_resource($GLOBALS['stream']), $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_QUOTA_SHORT_READ' => json_encode($cases, JSON_PRESERVE_ZERO_FRACTION),
        ]);
        $this->assertSame(count($cases), count($results));
        foreach ($cases as $index => [$type, $header, $stat, $statFault, $closeFault, $closed, $fresh]) {
            $short = $header !== null && strlen($header) < 16;
            $throws = $closeFault !== null || ($short && $statFault !== null);
            $events = [['open', '/fixture/quota/aquota.'.$type, 'rb'], ['read', true, 8]];
            if ($short) $events[] = ['stat', true];
            $events[] = ['close', true];
            $commands = $throws ? null : ['quotacheck -v'.$type[0].'mn'.($fresh ? 'cb' : '').' /home'];
            $this->assertSame([$commands, true, false, $events], $results[$index], 'case '.$index);
        }
    }
}
