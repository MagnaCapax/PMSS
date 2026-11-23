<?php
/** Verify GRUB detector input guards and bounded log reads with intercepted filesystem calls. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class BootGrubInputSafetyTest extends TestCase
{
    /** Load scripts/lib/update/systemPrep/bootGrubStale.php without reading host evidence. */
    private function fixture(string $body): string
    {
        $script = <<<'PHP'
namespace BootGrubInputFixture;
function is_file($path) {
    $GLOBALS['calls'][] = ['file', $path];
    return $GLOBALS['present'] ?? true;
}
function filesize($path) {
    $GLOBALS['calls'][] = ['size', $path];
    return $GLOBALS['size'];
}
function file_get_contents($path, $includePath = false, $context = null, $offset = 0, $length = null) {
    $GLOBALS['calls'][] = ['read', func_get_args()];
    $text = $GLOBALS['text'];
    return $text === false || $length === null ? $text : substr($text, $offset, $length);
}
PHP;
        return $script.$this->pmssInlinePhpLibraryInNamespace(
            'scripts/lib/update/systemPrep/bootGrubStale.php', 'BootGrubInputFixture'
        ).$body;
    }

    public function testInvalidPathsNeverReachFilesystemCallsAndValidPathsKeepTheirBytes(): void
    {
        $body = <<<'PHP'
$invalid = $valid = [];
foreach (['', "\0", "\0/fixture/log", "/fixture/lo\0g", "/fixture/log\0",
    null, false, true, 0, 1.5, [], new \stdClass()] as $path) {
    $GLOBALS['calls'] = [];
    $invalid[] = [pmssBootGrubUpgradeTimes([$path], $unusable), $unusable, $GLOBALS['calls']];
    if (is_string($path)) $invalid[] = [pmssBootGrubBootEpoch($path), $GLOBALS['calls']];
}
foreach (['/fixture/log', 'relative/log', '0', '/fixture/log space', "/fixture/log'quote"] as $path) {
    $GLOBALS['calls'] = [];
    $GLOBALS['size'] = 0;
    $GLOBALS['text'] = '';
    $log = [pmssBootGrubUpgradeTimes([$path], $unusable), $unusable, $GLOBALS['calls']];
    $GLOBALS['calls'] = [];
    $GLOBALS['text'] = "btime 42\n";
    $valid[] = [$log, pmssBootGrubBootEpoch($path), $GLOBALS['calls']];
}
echo json_encode([$invalid, $valid]);
PHP;
        $invalid = $valid = [];
        for ($i = 0; $i < 12; $i++) {
            $invalid[] = [[], 1, []];
            if ($i < 5) $invalid[] = [null, []];
        }
        foreach (['/fixture/log', 'relative/log', '0', '/fixture/log space', "/fixture/log'quote"] as $path) {
            $valid[] = [[[], 0, [['file', $path], ['size', $path],
                ['read', [$path, false, null, 0, 16777217]]]], 42, [['file', $path], ['read', [$path]]]];
        }
        $this->assertSame([$invalid, $valid], $this->pmssRunInlinePhpJson($this->fixture($body)));
    }

    public function testLogReadLimitsAndCorruptObservationsUseUnusableResults(): void
    {
        $body = <<<'PHP'
date_default_timezone_set('UTC');
$row = "2026-01-01 00:00:00 upgrade grub-pc:amd64 1 2\n";
$limit = PMSS_BOOT_GRUB_LOG_MAX_BYTES;
$results = [];
// Size refusal, read refusal, exact cap and growth after stat are independent boundaries.
foreach ([[false, $row], [$limit + 1, $row], [0, false], [0, ''],
    [$limit, str_pad($row, $limit, 'x')], [$limit, str_pad($row, $limit + 10, 'x')],
    [0, "\0"], [0, "\0".$row], [0, substr($row, 0, 20)."\0".substr($row, 20)],
    [0, $row."\0"], [0, $row], [0, "custom format\n"]] as [$size, $text]) {
    $GLOBALS['calls'] = [];
    $GLOBALS['size'] = $size;
    $GLOBALS['text'] = $text;
    $results[] = [pmssBootGrubUpgradeTimes(['/fixture/log'], $unusable), $unusable, $GLOBALS['calls']];
}
// A missing rotated log is normal; one unusable log must not discard its valid neighbor.
$GLOBALS['present'] = false;
$GLOBALS['calls'] = [];
$missing = [pmssBootGrubUpgradeTimes(['/fixture/missing'], $unusable), $unusable, $GLOBALS['calls']];
$GLOBALS['present'] = true;
$GLOBALS['size'] = 0;
$GLOBALS['text'] = $row;
$mixed = [pmssBootGrubUpgradeTimes(["\0", '/fixture/log'], $unusable), $unusable];
echo json_encode([$results, $missing, $mixed]);
PHP;
        $epoch = strtotime('2026-01-01 00:00:00 UTC');
        $probe = [['file', '/fixture/log'], ['size', '/fixture/log']];
        $read = array_merge($probe, [['read', ['/fixture/log', false, null, 0, 16777217]]]);
        $expected = [];
        foreach ([1, 1, 1, 0, 0, 1, 1, 1, 1, 1, 0, 0] as $index => $unusable) {
            $expected[] = [in_array($index, [4, 10], true) ? [$epoch] : [], $unusable, $index < 2 ? $probe : $read];
        }
        $this->assertSame([$expected, [[], 0, [['file', '/fixture/missing']]], [[$epoch], 1]],
            $this->pmssRunInlinePhpJson($this->fixture($body)));
    }

    public function testBootObservationsRejectNulsBeforeMatchingAndKeepSkipDiagnostics(): void
    {
        $body = <<<'PHP'
$results = [];
foreach ([false, '', "cpu 1\n", "\0btime 42\n", "cpu\0 1\nbtime 42\n", "btime 42\n\0",
    "btime 42\n", "cpu 1\nbtime\t42\nprocesses 9\n"] as $text) {
    $GLOBALS['text'] = $text;
    $GLOBALS['calls'] = [];
    $results[] = [pmssBootGrubBootEpoch('/fixture/stat'), $GLOBALS['calls']];
}
$GLOBALS['text'] = "btime 42\n\0";
$messages = [];
$status = pmssBootGrubStaleCoreImgCheck(static function ($message) use (&$messages) {
    $messages[] = $message;
}, ['/fixture/log'], '/fixture/stat');
echo json_encode([$results, $status, $messages]);
PHP;
        $expected = [];
        foreach ([null, null, null, null, null, null, 42, 42] as $epoch) {
            $expected[] = [$epoch, [['file', '/fixture/stat'], ['read', ['/fixture/stat']]]];
        }
        $this->assertSame([$expected, 'skip', ['[SKIP] GRUB boot-safety check: boot time unreadable']],
            $this->pmssRunInlinePhpJson($this->fixture($body)));
    }
}
