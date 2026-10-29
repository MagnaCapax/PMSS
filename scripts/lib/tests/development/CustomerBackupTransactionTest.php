<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock archive publication and padded reads without touching account files or launching tar. */
class CustomerBackupTransactionTest extends TestCase
{
    private function fixture(string $body): array
    {
        $script = <<<'PHP'
namespace BackupTransactionFixture;
function failAt($stage) {
    if (($GLOBALS['failureStage'] ?? '') === $stage) throw $GLOBALS['failure'];
}
function function_exists($name) { return false; }
function pmssCustomerPathIsSafe($path) { return true; }
function pmssCustomerPathIsInsideHome($home, $path) { return true; }
function pmssFrontendFunctionAvailable($name) { return true; }
function is_dir($path) { return $path === '/fixture' || $path === '/fixture/.pmss-backups'; }
function is_readable($path) { return true; }
function is_writable($path) { return true; }
function is_link($path) { return isset($GLOBALS['links'][$path]); }
function realpath($path) { return $path; }
function is_executable($path) { return true; }
function gmdate($format) { return '20260102-030405'; }
function fileperms($path) { return 0700; }
function clearstatcache($real, $path) {}
function file_exists($path) {
    return isset($GLOBALS['occupied'][$path]) || (!empty($GLOBALS['published']) && $GLOBALS['case'] === 'collision');
}
function is_file($path) { return substr($path, -12) === '.rtorrent.rc' || !empty($GLOBALS['published']); }
function filesize($path) { return $GLOBALS['case'] === 'verify' ? 0 : 42; }
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode];
    return $GLOBALS['case'] === 'open' ? false : ($GLOBALS['out'] = \fopen('php://temp', 'w+'));
}
function chmod($path, $mode) { $GLOBALS['events'][] = ['mode', $path, $mode]; failAt('mode'); return true; }
function popen($command, $mode) {
    $GLOBALS['events'][] = ['pipe', $command, $mode];
    failAt('pipe');
    $GLOBALS['published'] = true;
    return $GLOBALS['case'] === 'pipe' ? false : ($GLOBALS['pipe'] = \fopen('php://temp', 'w+'));
}
function stream_copy_to_stream($in, $out) {
    $GLOBALS['events'][] = ['copy'];
    failAt('copy');
    return $GLOBALS['case'] === 'copy' ? false : ($GLOBALS['case'] === 'empty' ? 0 : 42);
}
function fclose($handle) {
    $GLOBALS['events'][] = ['close'];
    \fclose($handle);
    failAt('close');
    return $GLOBALS['case'] !== 'close';
}
function pclose($handle) {
    $GLOBALS['events'][] = ['reap'];
    \fclose($handle);
    return $GLOBALS['case'] === 'reap' ? 2 : 0;
}
function unlink($path) { $GLOBALS['events'][] = ['unlink', $path]; return true; }
function rename($from, $to) {
    $GLOBALS['events'][] = ['rename', $from, $to];
    return $GLOBALS['case'] !== 'rename';
}
function gzread($handle, $size) {
    $GLOBALS['reads'][] = $size;
    failAt($size === 512 && count($GLOBALS['reads']) === 1 ? 'header' : 'payload');
    if (isset($GLOBALS['chunks'])) return array_shift($GLOBALS['chunks']) ?? '';
    $chunk = substr($GLOBALS['payload'], 0, min($size, 333));
    $GLOBALS['payload'] = substr($GLOBALS['payload'], strlen($chunk));
    return $chunk;
}
function gzopen($path, $mode) { return $GLOBALS['gzip'] = \fopen('php://temp', 'w+'); }
function gzeof($handle) { failAt('eof'); return false; }
function gzclose($handle) { $GLOBALS['events'][] = ['gzip-close']; return \fclose($handle); }
PHP;
        foreach (['Backup', 'RestoreTar', 'Restore'] as $part) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('etc/skel/www/scriptsInc'.$part.'.php', 'BackupTransactionFixture');
        }
        return $this->pmssRunInlinePhpJson($script.$body);
    }

    public function testArchiveExceptionsCloseOutputAndReapStartedPipes(): void
    {
        $actual = $this->fixture(<<<'PHP'
$results = [];
$GLOBALS['case'] = 'ok';
foreach ([new \RuntimeException('fixture failure'), new \Error('fixture failure')] as $failure) {
    foreach (['mode', 'pipe', 'copy', 'close'] as $stage) {
        foreach ([true, false] as $scheduled) {
            if (!$scheduled && $stage === 'mode') continue;
            $GLOBALS['failureStage'] = $stage;
            $GLOBALS['failure'] = $failure;
            $GLOBALS['events'] = [];
            $GLOBALS['pipe'] = null;
            $caught = null;
            try {
                pmssCustomerBackupArchiveWrite('unused', '/fixture/archive', $scheduled);
            } catch (\Throwable $error) {
                $caught = $error;
            }
            $events = array_column($GLOBALS['events'], 0);
            $results[] = [$caught === $failure, is_resource($GLOBALS['out']),
                is_resource($GLOBALS['pipe']), count(array_keys($events, 'close', true)),
                count(array_keys($events, 'reap', true)), in_array('rename', $events, true)];
        }
    }
}
echo json_encode($results);
PHP
        );
        // Both throwable classes preserve identity; failures before pipe startup cannot reap.
        $expected = [[true, false, false, 1, 0, false]];
        $expected = array_merge($expected, array_fill(0, 2, $expected[0]),
            array_fill(0, 4, [true, false, false, 1, 1, false]));
        $this->assertSame(array_merge($expected, $expected), $actual);
    }

    public function testTarInspectionClosesOnExceptionsAndNormalReturns(): void
    {
        $actual = $this->fixture(<<<'PHP'
$results = [];
// One metadata header forces the scanner through its padded payload reader.
$header = substr_replace(str_repeat("\0", 512), '00000000001', 124, 11);
$header[156] = 'L';
foreach ([new \RuntimeException('fixture failure'), new \Error('fixture failure')] as $failure) {
    foreach (['eof', 'header', 'payload', 'short', 'size', 'truncated', 'ok'] as $stage) {
        $GLOBALS['failureStage'] = $stage;
        $GLOBALS['failure'] = $failure;
        $GLOBALS['events'] = $GLOBALS['reads'] = [];
        $GLOBALS['chunks'] = [$header, str_repeat('x', 512), str_repeat("\0", 512)];
        if ($stage === 'short') $GLOBALS['chunks'] = ['short'];
        if ($stage === 'size') $GLOBALS['chunks'] = [substr_replace($header, 'invalid-size', 124, 12)];
        if ($stage === 'truncated') $GLOBALS['chunks'] = [$header, ''];
        $caught = null;
        $result = null;
        try {
            $result = pmssCustomerBackupRestoreTarMemberTypesRead('/unused/archive');
        } catch (\Throwable $error) {
            $caught = $error;
        }
        $results[] = [$result, $caught === $failure, is_resource($GLOBALS['gzip']), $GLOBALS['events']];
    }
}
echo json_encode($results);
PHP
        );
        $closed = [['gzip-close']];
        $expected = array_merge(array_fill(0, 3, [null, true, false, $closed]),
            array_fill(0, 3, [null, false, false, $closed]), [[[], false, false, $closed]]);
        $this->assertSame(array_merge($expected, $expected), $actual);
    }

    public function testScheduledAndSnapshotPublicationKeepTheirDistinctContracts(): void
    {
        $actual = $this->fixture(<<<'PHP'
$results = [];
foreach (['ok', 'open', 'pipe', 'copy', 'empty', 'close', 'reap', 'collision', 'rename', 'verify'] as $case) {
    foreach ([true, false] as $scheduled) {
        $GLOBALS['case'] = $case;
        $GLOBALS['events'] = [];
        $GLOBALS['published'] = false;
        $error = '';
        $result = $scheduled ? pmssCustomerBackupFileCreate('/fixture')
            : pmssCustomerBackupRestoreSnapshotCreate('/fixture', $error);
        $results[] = [$case, $scheduled, $result, $error, $GLOBALS['events']];
    }
}
echo json_encode($results);
PHP
        );
        $scheduledPath = '/fixture/.pmss-backups/config-20260102-030405.tar.gz';
        $snapshotPath = '/fixture/.pmss-pre-restore-20260102-030405.tar.gz';
        $scheduledErrors = ['open' => 'Could not create the backup archive file.',
            'pipe' => 'Could not start the backup archive process.', 'verify' => 'Backup archive verification failed.'];
        $snapshotErrors = ['open' => 'Could not create the pre-restore snapshot file.',
            'pipe' => 'Could not start the pre-restore snapshot.'];
        foreach ($actual as [$case, $scheduled, $result, $error, $events]) {
            $path = $scheduled ? $scheduledPath : $snapshotPath;
            $ok = in_array($case, $scheduled ? ['ok'] : ['ok', 'empty', 'collision', 'verify'], true);
            if ($scheduled) {
                $this->assertSame(['ok' => $ok,
                    'message' => $ok ? 'created config backup' : ($scheduledErrors[$case] ?? 'Backup archive creation failed.'),
                    'bytes' => $ok ? 42 : 0, 'path' => $ok ? $path : '', 'keptCount' => 0, 'deletedCount' => 0], $result, $case);
                $this->assertSame('', $error);
            } else {
                $this->assertSame($ok ? $path : '', $result, $case);
                $this->assertSame($ok ? '' : ($snapshotErrors[$case] ?? 'Pre-restore snapshot failed; restore aborted.'), $error, $case);
            }
            $expected = [['open', $path.'.part', $scheduled ? 'xb' : 'wb']];
            if ($case !== 'open') {
                if ($scheduled) $expected[] = ['mode', $path.'.part', 0600];
                $expected[] = ['pipe', "'/bin/tar' '--create' '--gzip' '--file=-' '--directory' '/fixture' '--' '.rtorrent.rc'", 'r'];
                if ($case === 'pipe') {
                    $expected[] = ['close'];
                } else {
                    array_push($expected, ['copy'], ['close'], ['reap']);
                    if (!in_array($case, ['copy', 'close', 'reap'], true)
                        && (!$scheduled || !in_array($case, ['empty', 'collision'], true))) {
                        $expected[] = ['rename', $path.'.part', $path];
                    }
                }
                if (!$ok && $case !== 'verify') $expected[] = ['unlink', $path.'.part'];
                if ($scheduled && ($ok || $case === 'verify')) $expected[] = ['mode', $path, 0600];
                if ($scheduled && $case === 'verify') $expected[] = ['unlink', $path];
            }
            $this->assertSame($expected, $events, $case.($scheduled ? ' scheduled' : ' snapshot'));
        }
    }

    public function testPaddedMetadataAndSkippedPayloadKeepReadBoundaries(): void
    {
        $actual = $this->fixture(<<<'PHP'
$results = [];
foreach ([0, 1, 511, 512, 513, 65536, 65537, -1] as $size) {
    foreach ([false, true] as $truncated) {
        foreach ([false, true] as $capture) {
            $GLOBALS['payload'] = str_repeat('x', $truncated ? max(0, $size - 1) : (int) (ceil(max(0, $size) / 512) * 512));
            $GLOBALS['reads'] = [];
            $value = $capture ? pmssCustomerBackupRestoreTarPayloadRead(null, $size)
                : pmssCustomerBackupRestoreTarSkipPayload(null, $size);
            $results[] = [$size, $truncated, $capture, $value, $GLOBALS['reads']];
        }
    }
}
echo json_encode($results);
PHP
        );
        foreach ($actual as [$size, $truncated, $capture, $value, $reads]) {
            $invalid = $capture && ($size < 0 || $size > 65536);
            $failed = $invalid || ($truncated && $size > 0);
            $this->assertSame($failed ? false : ($capture ? str_repeat('x', $size) : true), $value);
            if ($invalid || $size <= 0) $this->assertSame([], $reads);
            foreach ($reads as $requested) $this->assertTrue($requested > 0 && $requested <= 8192);
        }
    }

    public function testArchiveNamespacesRetainCollisionOrderAndTheHundredNameLimit(): void
    {
        $actual = $this->fixture(<<<'PHP'
$results = [];
$GLOBALS['case'] = 'ok';
foreach ([true, false] as $scheduled) {
    $base = '/fixture/'.($scheduled ? '.pmss-backups/config-' : '.pmss-pre-restore-').'20260102-030405';
    foreach (['none', 'archive', 'part', 'link', 'full'] as $collision) {
        $GLOBALS['occupied'] = $GLOBALS['links'] = [];
        if ($collision === 'archive') $GLOBALS['occupied'][$base.'.tar.gz'] = true;
        if ($collision === 'part') $GLOBALS['occupied'][$base.'.tar.gz.part'] = true;
        if ($collision === 'link') $GLOBALS['links'][$base.'.tar.gz'] = true;
        if ($collision === 'full') {
            for ($i = 0; $i < 100; $i++) {
                $GLOBALS['occupied'][$base.($i === 0 ? '' : '-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)).'.tar.gz'] = true;
            }
        }
        $results[] = [$scheduled, $collision, $scheduled ? pmssCustomerBackupFilePathBuild('/fixture/')
            : pmssCustomerBackupRestoreSnapshotPathBuild('/fixture/')];
    }
}
echo json_encode($results);
PHP
        );
        foreach ($actual as [$scheduled, $collision, $path]) {
            $base = '/fixture/'.($scheduled ? '.pmss-backups/config-' : '.pmss-pre-restore-').'20260102-030405';
            $suffix = in_array($collision, ['archive', 'link'], true) || ($scheduled && $collision === 'part') ? '-01' : '';
            $this->assertSame($collision === 'full' ? '' : $base.$suffix.'.tar.gz', $path);
        }
    }
}
