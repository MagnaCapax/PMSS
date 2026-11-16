<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Unpublished parts in etc/skel/www/scriptsIncBackup.php are cleaned after thrown I/O. */
class CustomerBackupPartCleanupSafetyTest extends TestCase
{
    public function testUnpublishedPartsAreRemovedAfterStreamCleanupAndPublishedArchivesStayAlone(): void
    {
        $start = ['open', 'mode', 'pipe', 'copy', 'close', 'reap'];
        $publish = array_merge($start, ['exists', 'rename']);
        $verified = array_merge($publish, ['published-mode', 'stat', 'size', 'file', 'link']);
        // Exact traces pin nested cleanup precedence and the publication ownership boundary.
        $cases = [
            'ok' => $verified,
            'open-false' => ['open'],
            'mode' => ['open', 'mode', 'close', 'unlink'],
            'pipe' => ['open', 'mode', 'pipe', 'close', 'unlink'],
            'pipe-false' => ['open', 'mode', 'pipe', 'close', 'unlink'],
            'copy' => array_merge($start, ['unlink']),
            'copy-false' => array_merge($start, ['unlink']),
            'close' => array_merge($start, ['unlink']),
            'reap' => array_merge($start, ['unlink']),
            'copy+close+reap' => array_merge($start, ['unlink']),
            'copy+unlink' => array_merge($start, ['unlink']),
            'close+reap+unlink' => array_merge($start, ['unlink']),
            'exists' => array_merge($start, ['exists', 'unlink']),
            'rename' => array_merge($publish, ['unlink']),
            'rename-false' => array_merge($publish, ['unlink']),
            'rename-false+unlink' => array_merge($publish, ['unlink']),
            'published-mode' => array_merge($publish, ['published-mode']),
            'stat' => array_merge($publish, ['published-mode', 'stat']),
            'size' => array_merge($publish, ['published-mode', 'stat', 'size']),
            'file' => array_slice($verified, 0, -1),
            'link' => $verified,
        ];
        $script = <<<'PHP'
namespace CustomerBackupPartCleanupFixture;
function function_exists($name) { return false; }
function observe($phase, $result = true) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
    return in_array($phase.'-false', explode('+', $GLOBALS['case']), true) ? false : $result;
}
// Only memory streams are real; no archive process or filesystem mutation is performed.
function fopen($path, $mode) {
    $GLOBALS['opened'] = [$path, $mode];
    return observe('open') === false ? false : ($GLOBALS['out'] = \fopen('php://memory', 'w+'));
}
function chmod($path, $mode) { return observe(substr($path, -5) === '.part' ? 'mode' : 'published-mode'); }
function popen($command, $mode) {
    return observe('pipe') === false ? false : ($GLOBALS['pipe'] = \fopen('php://memory', 'w+'));
}
function stream_copy_to_stream($in, $out) { return observe('copy', 42); }
function fclose($handle) { \fclose($handle); return observe('close'); }
function pclose($handle) { \fclose($handle); return observe('reap', 0); }
function file_exists($path) { return observe('exists', false); }
function rename($source, $target) { return observe('rename'); }
function clearstatcache($real, $path) { observe('stat'); }
function filesize($path) { return observe('size', 42); }
function is_file($path) { return observe('file'); }
function is_link($path) { return observe('link', false); }
function unlink($path) { $GLOBALS['removed'][] = $path; return observe('unlink'); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('etc/skel/www/scriptsIncBackup.php', 'CustomerBackupPartCleanupFixture');
        $script .= '$cases = '.var_export(array_keys($cases), true).';';
        $script .= <<<'PHP'
$rows = [];
foreach ($cases as $case) {
    foreach ([true, false] as $scheduled) {
        if (!$scheduled && in_array($case, ['mode', 'exists', 'published-mode', 'stat', 'size', 'file', 'link'], true)) continue;
        foreach ([\RuntimeException::class, \Error::class] as $kind) {
            $GLOBALS['case'] = $case;
            $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['removed'] = [];
            $GLOBALS['out'] = $GLOBALS['pipe'] = null;
            foreach (explode('+', $case) as $phase) {
                if ($phase !== 'ok' && strpos($phase, '-false') === false) $GLOBALS['faults'][$phase] = new $kind($phase);
            }
            $result = $caught = $size = null;
            try { $result = pmssCustomerBackupArchiveWrite('fixture-command', '/fixture/archive', $scheduled, $size); }
            catch (\Throwable $error) { $caught = $error; }
            $rows[] = [$case, $scheduled, $result, $caught === ($GLOBALS['faults'] === [] ? null : end($GLOBALS['faults'])),
                $GLOBALS['events'], $GLOBALS['removed'], $GLOBALS['opened'], $size,
                is_resource($GLOBALS['out']), is_resource($GLOBALS['pipe'])];
        }
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(count($cases) * 4 - 14, count($rows));
        foreach ($rows as $row) {
            [$case, $scheduled] = $row;
            $expected = $scheduled ? $cases[$case] : array_values(array_diff($cases[$case],
                ['mode', 'exists', 'published-mode', 'stat', 'size', 'file', 'link']));
            $error = $case === 'ok' ? '' : null;
            if ($case === 'open-false') $error = $scheduled ? 'Could not create the backup archive file.' : 'Could not create the pre-restore snapshot file.';
            if ($case === 'pipe-false') $error = $scheduled ? 'Could not start the backup archive process.' : 'Could not start the pre-restore snapshot.';
            if (in_array($case, ['copy-false', 'rename-false'], true)) $error = $scheduled ? 'Backup archive creation failed.' : 'Pre-restore snapshot failed; restore aborted.';
            $this->pmssAssertArraySubsetSame([4 => $expected, 3 => true,
                5 => in_array('unlink', $expected, true) ? ['/fixture/archive.part'] : [],
                6 => ['/fixture/archive.part', $scheduled ? 'xb' : 'wb'], 8 => false, 9 => false, 2 => $error,
                7 => $scheduled && in_array($case, ['ok', 'file', 'link'], true) ? 42 : null,
            ], $row, $case.':');
        }
    }
}
