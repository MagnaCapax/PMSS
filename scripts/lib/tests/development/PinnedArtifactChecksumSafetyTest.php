<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/remoteBinary/artifact.php';

/** Exercise checksum acquisition without downloads or installation. */
class PinnedArtifactChecksumSafetyTest extends TestCase
{
    public function testRegularLinksAndEmptyFilesKeepTheirChecksums(): void
    {
        $root = $this->pmssMakeTempDir('pmss-checksum-safety-');
        $path = $root.'/artifact with spaces';
        file_put_contents($path, 'payload');
        link($path, $root.'/hardlink');
        symlink($path, $root.'/symlink');
        foreach ([$path, $root.'/hardlink', $root.'/symlink'] as $file) {
            $this->assertSame(hash('sha256', 'payload'), \pmssPinnedRemoteChecksum($file));
        }
        file_put_contents($path, '');
        $this->assertSame(hash('sha256', ''), \pmssPinnedRemoteChecksum($path));
        $this->assertSame('', \pmssPinnedRemoteChecksum($root));
        $this->assertSame('', \pmssPinnedRemoteChecksum($root.'/missing'));
    }

    public function testAcquisitionFailuresAndThrowablesCloseEveryOpenedStream(): void
    {
        $script = <<<'PHP'
namespace PinnedArtifactChecksumFixture;
function observe($phase, $value) {
    $GLOBALS['events'][] = $phase;
    if ($GLOBALS['case'] === $phase.'-throw') throw $GLOBALS['failure'];
    return $value;
}
function stat($path) {
    $stat = ['dev' => 1, 'ino' => 2, 'mode' => 0100644, 'size' => 7];
    if ($GLOBALS['case'] === 'missing') $stat = false;
    if ($GLOBALS['case'] === 'fifo') $stat['mode'] = 0010644;
    if ($GLOBALS['case'] === 'device') $stat['mode'] = 0020644;
    return observe('stat', $stat);
}
function fopen($path, $mode) {
    observe('open', null);
    $GLOBALS['modes'][] = $mode;
    if ($GLOBALS['case'] === 'open-failed') return false;
    $handle = \fopen('php://memory', 'w+');
    $GLOBALS['handles'][] = $handle;
    \fwrite($handle, 'payload'); \rewind($handle);
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
function hash_update_stream($hash, $handle) {
    observe('read', null);
    if ($GLOBALS['case'] === 'read-failed') return false;
    // Simulate short and excess byte counts without changing host files.
    return \hash_update_stream($hash, $handle, $GLOBALS['case'] === 'short-read' ? 3 : -1)
        + ($GLOBALS['case'] === 'long-read' ? 1 : 0);
}
function fclose($handle) {
    \fclose($handle);
    return observe('close', true);
}
PHP;
        // Exercise the shared inode matcher with injected fstat failures.
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/filesystem.php', 'PinnedArtifactChecksumFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/filesystemStream.php', 'PinnedArtifactChecksumFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/apps/remoteBinary/artifact.php', 'PinnedArtifactChecksumFixture');
        $script .= <<<'PHP'
$results = [];
$cases = ['valid', 'missing', 'fifo', 'device', 'open-failed', 'stat-failed',
    'inode-changed', 'device-changed', 'fifo-substituted', 'read-failed', 'short-read', 'long-read'];
foreach (['stat', 'open', 'opened-stat', 'read', 'close'] as $phase) $cases[] = $phase.'-throw';
foreach ($cases as $case) {
    foreach (substr($case, -6) === '-throw' ? [new \RuntimeException($case), new \Error($case)] : [null] as $failure) {
        $GLOBALS['case'] = $case; $GLOBALS['failure'] = $failure;
        $GLOBALS['events'] = []; $GLOBALS['handles'] = []; $GLOBALS['modes'] = [];
        $checksum = null; $caught = null;
        try { $checksum = pmssPinnedRemoteChecksum('/fixture/artifact'); }
        catch (\Throwable $error) { $caught = $error; }
        $live = array_filter($GLOBALS['handles'], 'is_resource');
        foreach ($live as $handle) \fclose($handle); // Observe leaks before fixture cleanup.
        $results[] = [$case, $checksum, $caught === $failure, count($live), $GLOBALS['events'], $GLOBALS['modes']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(22, count($results));
        $trace = ['stat', 'open', 'opened-stat', 'read', 'close'];
        $stop = ['missing' => 1, 'fifo' => 1, 'device' => 1, 'open-failed' => 2,
            'stat-failed' => 3, 'inode-changed' => 3, 'device-changed' => 3, 'fifo-substituted' => 3];
        foreach ($results as [$case, $checksum, $sameFailure, $live, $events, $modes]) {
            $this->assertSame([true, 0], [$sameFailure, $live], $case);
            $this->assertSame(array_fill(0, count($modes), 'rbn'), $modes, $case);
            if (substr($case, -6) === '-throw') {
                $phase = substr($case, 0, -6);
                $expected = array_slice($trace, 0, array_search($phase, $trace, true) + 1);
                if (in_array($phase, ['opened-stat', 'read'], true)) $expected[] = 'close';
                $this->assertSame(null, $checksum, $case);
            } else {
                $expected = array_slice($trace, 0, $stop[$case] ?? count($trace));
                if (($stop[$case] ?? null) === 3) $expected[] = 'close';
                $this->assertSame($case === 'valid' ? hash('sha256', 'payload') : '', $checksum, $case);
            }
            $this->assertSame($expected, $events, $case);
        }
    }
}
