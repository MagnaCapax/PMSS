<?php
/** Exercise pinned version-marker acquisition and cleanup without installing applications. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/remoteBinary.php';

class PinnedVersionMarkerSafetyTest extends TestCase
{
    public function testRegularMarkersKeepWhitespaceHardLinksAndBinarySymlinks(): void
    {
        $root = $this->pmssMakeTempDir('pmss-version-marker-');
        $binary = $this->pmssWriteFile($root.'/binary', 'binary');
        $marker = $this->pmssWriteFile($root.'/marker with spaces', " \t1.2.3\r\n");
        link($marker, $root.'/hardlink');
        symlink($binary, $root.'/binary-link');
        symlink($marker, $root.'/marker-link');
        foreach ([$marker, $root.'/hardlink'] as $path) {
            $this->assertTrue(\pmssPinnedRemoteVersionMatches($root.'/binary-link', $path, '1.2.3'));
            $this->assertFalse(\pmssPinnedRemoteVersionMatches($binary, $path, '1.2.4'));
        }
        foreach ([$root, $root.'/missing', $root.'/marker-link'] as $path) {
            $this->assertFalse(\pmssPinnedRemoteVersionMatches($binary, $path, ''));
        }
        $this->pmssWriteFile($marker, '');
        $this->assertTrue(\pmssPinnedRemoteVersionMatches($binary, $marker, ''));
    }

    public function testReadFailuresAndThrowablesNeverCertifyMarkersOrLeakStreams(): void
    {
        $script = <<<'PHP'
namespace PinnedVersionMarkerFixture;
function observe($phase, $value) {
    $GLOBALS['events'][] = $phase;
    if ($GLOBALS['case'] === $phase.'-throw') throw $GLOBALS['failure'];
    return $value;
}
function is_file($path) { return observe('binary', $GLOBALS['case'] !== 'binary-missing'); }
function lstat($path) {
    $stat = ['dev' => 1, 'ino' => 2, 'mode' => 0100644, 'size' => 7];
    if ($GLOBALS['case'] === 'missing') $stat = false;
    foreach (['fifo' => 0010644, 'device' => 0020644, 'symlink' => 0120777] as $case => $mode) {
        if ($GLOBALS['case'] === $case) $stat['mode'] = $mode;
    }
    return observe('stat', $stat);
}
function fopen($path, $mode) {
    observe('open', null);
    $GLOBALS['modes'][] = $mode;
    if ($GLOBALS['case'] === 'open-failed') return false;
    $handle = \fopen('php://memory', 'w+');
    $GLOBALS['handles'][] = $handle;
    \fwrite($handle, " 1.2.3\n"); \rewind($handle);
    return $handle;
}
function fstat($handle) {
    $stat = ['dev' => 1, 'ino' => 2, 'mode' => 0100644, 'size' => 7];
    if ($GLOBALS['case'] === 'inode-changed') $stat['ino'] = 3;
    if ($GLOBALS['case'] === 'device-changed') $stat['dev'] = 3;
    if ($GLOBALS['case'] === 'fifo-substituted') $stat['mode'] = 0010644;
    if ($GLOBALS['case'] === 'stat-failed') $stat = false;
    return observe('opened-stat', $stat);
}
function stream_get_contents($handle) {
    observe('read', null);
    if ($GLOBALS['case'] === 'read-failed') return false;
    if ($GLOBALS['case'] === 'empty-read') return '';
    if ($GLOBALS['case'] === 'short-read') return '1.2.3';
    return \stream_get_contents($handle).($GLOBALS['case'] === 'long-read' ? ' ' : '');
}
function fclose($handle) {
    \fclose($handle);
    return observe('close', $GLOBALS['case'] !== 'close-failed');
}
PHP;
        // Inline the existing opener so real inode checks run against injected metadata.
        foreach (['runtime/filesystem.php', 'runtime/filesystemStream.php', 'update/apps/remoteBinary.php'] as $file) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/'.$file, 'PinnedVersionMarkerFixture');
        }
        $script .= <<<'PHP'
$results = [];
$cases = ['valid', 'mismatch', 'binary-missing', 'missing', 'fifo', 'device', 'symlink',
    'open-failed', 'stat-failed', 'inode-changed', 'device-changed', 'fifo-substituted',
    'read-failed', 'empty-read', 'short-read', 'long-read', 'close-failed'];
foreach (['binary', 'stat', 'open', 'opened-stat', 'read', 'close'] as $phase) $cases[] = $phase.'-throw';
foreach ($cases as $case) {
    foreach (substr($case, -6) === '-throw' ? [new \RuntimeException($case), new \Error($case)] : [null] as $failure) {
        $GLOBALS['case'] = $case; $GLOBALS['failure'] = $failure;
        $GLOBALS['events'] = []; $GLOBALS['handles'] = []; $GLOBALS['modes'] = [];
        $matches = null; $caught = null;
        try { $matches = pmssPinnedRemoteVersionMatches('/fixture/binary', '/fixture/marker', $case === 'empty-read' ? '' : ($case === 'mismatch' ? '2.0' : '1.2.3')); }
        catch (\Throwable $error) { $caught = $error; }
        $live = array_filter($GLOBALS['handles'], 'is_resource');
        foreach ($live as $handle) \fclose($handle); // Observe leaks before fixture cleanup.
        $results[] = [$case, $matches, $caught === $failure, count($live), $GLOBALS['events'], $GLOBALS['modes']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(29, count($results));
        $trace = ['binary', 'stat', 'open', 'opened-stat', 'read', 'close'];
        $stop = ['binary-missing' => 1, 'missing' => 2, 'fifo' => 2, 'device' => 2, 'symlink' => 2,
            'open-failed' => 3, 'stat-failed' => 4, 'inode-changed' => 4, 'device-changed' => 4, 'fifo-substituted' => 4];
        foreach ($results as [$case, $matches, $sameFailure, $live, $events, $modes]) {
            $this->assertSame([true, 0], [$sameFailure, $live], $case);
            $this->assertSame(array_fill(0, count($modes), 'rbn'), $modes, $case);
            if (substr($case, -6) === '-throw') {
                $phase = substr($case, 0, -6);
                $expected = array_slice($trace, 0, array_search($phase, $trace, true) + 1);
                if (in_array($phase, ['opened-stat', 'read'], true)) $expected[] = 'close';
                $this->assertSame(null, $matches, $case);
            } else {
                $expected = array_slice($trace, 0, $stop[$case] ?? count($trace));
                if (($stop[$case] ?? null) === 4) $expected[] = 'close';
                $this->assertSame($case === 'valid', $matches, $case);
            }
            $this->assertSame($expected, $events, $case);
        }
    }
}
