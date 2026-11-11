<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';
require_once dirname(__DIR__, 2).'/lighttpd/watchdogNginxLogReader.php';

/** Log acquisition must finish before consumers or recovery-state writers run. */
class LogReaderAcquisitionSafetyTest extends TestCase
{
    public function testBothReadersRefuseReplacementsAndCloseAcquiredStreams(): void
    {
        $root = $this->pmssMakeTempDir('pmss-log-acquisition-');
        $path = $root.'/events.log';
        $this->pmssWriteFile($path, "{\"event\":\"ready\"}\n");
        $stat = stat($path);
        $this->pmssWriteFile($root.'/state.json', json_encode([
            'device' => $stat['dev'], 'inode' => $stat['ino'], 'offset' => 0, 'users' => [],
        ]));
        // Memory streams and namespaced metadata faults never open a FIFO or change the log.
        $script = <<<'PHP'
namespace LogReaderAcquisitionFixture;
function observe($phase, $value) {
    $GLOBALS['events'][] = $phase;
    if ($GLOBALS['case'] === $phase.'-throw') throw $GLOBALS['failure'];
    return $value;
}
function lstat($path) {
    $stat = $GLOBALS['snapshot'] = \lstat($path);
    if ($GLOBALS['case'] === 'missing') $stat = false;
    if ($GLOBALS['case'] === 'empty-metadata') $stat = [];
    foreach (['fifo' => 0010600, 'directory' => 0040700, 'symlink' => 0120777] as $case => $mode) {
        if ($GLOBALS['case'] === $case) $stat['mode'] = $mode;
    }
    if ($GLOBALS['case'] === 'hardlink') $stat['nlink'] = 2;
    return observe('snapshot', $stat);
}
function fopen($path, $mode) {
    $GLOBALS['modes'][] = $mode;
    observe('open', null);
    if ($GLOBALS['case'] === 'open-false') return false;
    $handle = \fopen('php://memory', 'w+');
    \fwrite($handle, "{\"event\":\"ready\"}\n");
    \rewind($handle);
    return $GLOBALS['handle'] = $handle;
}
function fstat($handle) {
    $stat = $GLOBALS['snapshot'];
    if ($GLOBALS['case'] === 'inode-changed' || $GLOBALS['case'] === 'fifo-substituted') $stat['ino']++;
    if ($GLOBALS['case'] === 'device-changed') $stat['dev']++;
    if ($GLOBALS['case'] === 'fifo-substituted') $stat['mode'] = 0010600;
    if ($GLOBALS['case'] === 'stat-false') $stat = false;
    return observe('stat', $stat);
}
function fgets($handle) { observe('read', null); return \fgets($handle); }
function fclose($handle) { \fclose($handle); return observe('close', true); }
function pmssAtomicJsonFileWrite($path, $payload, $mode) { $GLOBALS['published'][] = $payload; return true; }
PHP;
        // Use the real inode matcher, JSON decoder and nginx cursor logic in both paths.
        foreach (['runtime/filesystem.php', 'runtime/filesystemStream.php', 'log.php', 'log/text.php', 'log/json.php', 'log/append.php', 'log/jsonFiles.php', 'lighttpd/watchdogNginxLogReader.php'] as $library) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/'.$library, 'LogReaderAcquisitionFixture');
        }
        $script .= <<<'PHP'
[$path, $statePath] = json_decode(getenv('PMSS_TEST_LOG_ACQUISITION'), true);
$results = [];
foreach (['json', 'nginx'] as $reader) {
    foreach (['valid', 'hardlink', 'missing', 'fifo', 'directory', 'symlink', 'empty-metadata',
        'open-false', 'stat-false', 'inode-changed', 'device-changed', 'fifo-substituted',
        'snapshot-throw', 'open-throw', 'stat-throw', 'read-throw'] as $case) {
        foreach (substr($case, -6) === '-throw' ? [new \RuntimeException($case), new \Error($case)] : [null] as $failure) {
            $GLOBALS['case'] = $case; $GLOBALS['failure'] = $failure; $GLOBALS['handle'] = null;
            $GLOBALS['events'] = $GLOBALS['modes'] = $GLOBALS['published'] = $seen = [];
            $caught = null; $result = $reader === 'json' ? false : [];
            try {
                $result = $reader === 'json' ? pmssJsonLineFileEach($path, static function ($row) use (&$seen) { $seen[] = $row; })
                    : pmssLighttpdWatchdogNginxActionsRead($path, $statePath, [25000 => 'fixtur01']);
            } catch (\Throwable $error) { $caught = $error; }
            $live = is_resource($GLOBALS['handle']);
            if ($live) \fclose($GLOBALS['handle']); // Observe a leak before fixture cleanup.
            $results[] = [$reader, $case, $result, $caught === $failure, $live,
                $GLOBALS['events'], $GLOBALS['modes'], $seen, count($GLOBALS['published'])];
        }
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_LOG_ACQUISITION' => json_encode([$path, $root.'/state.json']),
        ]);
        $this->assertSame(40, count($results));
        foreach ($results as [$reader, $case, $result, $sameFailure, $live, $events, $modes, $seen, $published]) {
            $success = in_array($case, ['valid', 'hardlink'], true);
            $opened = !in_array($case, ['missing', 'fifo', 'directory', 'symlink', 'empty-metadata', 'snapshot-throw'], true);
            $acquired = $opened && !in_array($case, ['open-false', 'open-throw'], true);
            $expected = ['snapshot'];
            if ($opened) $expected[] = 'open';
            if ($acquired) $expected[] = 'stat';
            if ($success || $case === 'read-throw') $expected[] = 'read';
            if ($success) $expected[] = 'read';
            if ($acquired) $expected[] = 'close';
            $this->assertSame([$reader === 'json' ? $success : [], true, false, $expected,
                $opened ? ['rbn'] : [], $success && $reader === 'json' ? [['event' => 'ready']] : [],
                $success && $reader === 'nginx' ? 1 : 0],
                [$result, $sameFailure, $live, $events, $modes, $seen, $published], $reader.':'.$case);
        }
        $this->assertSame("{\"event\":\"ready\"}\n", file_get_contents($path));
    }

    public function testRealHardLinkedLogsRetainRecordsAndCursorBytes(): void
    {
        $root = $this->pmssMakeTempDir('pmss-log-hardlink-');
        $path = $root.'/events.log';
        $content = "{\"event\":\"ready\"}\ninvalid\nnull\n{\"event\":\"last\"}";
        $this->pmssWriteFile($path, $content);
        $this->assertTrue(link($path, $root.'/alias.log'));
        foreach ([$path, $root.'/alias.log'] as $log) {
            $this->assertSame([['event' => 'ready'], ['event' => 'last']], \pmssJsonLineFileRead($log));
            $stat = stat($log);
            $state = ['device' => $stat['dev'], 'inode' => $stat['ino'], 'offset' => 0, 'users' => []];
            $this->pmssWriteFile($root.'/state.json', json_encode($state));
            $this->assertSame([], \pmssLighttpdWatchdogNginxActionsRead($log, $root.'/state.json', []));
            // The incomplete final line remains available for the next watchdog pass.
            $state['offset'] = strrpos($content, "\n") + 1;
            $this->assertSame($state, json_decode(file_get_contents($root.'/state.json'), true));
        }
        $this->assertSame($content, file_get_contents($path));
    }
}
