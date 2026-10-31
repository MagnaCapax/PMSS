<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/watchdogCustomConfig.php';

/** Keep fragment recovery evidence-specific, reversible and confined to the account. */
class LighttpdWatchdogCustomConfigTest extends TestCase
{
    public function testParserRequiresOneExactFragmentAndAnUnsuccessfulParse(): void
    {
        $home = '/home/fixtur01';
        $path = $home.'/.lighttpd/custom.d/media-stack.conf';
        $line = $this->parseError($path);
        $failed = ['rc' => 1, 'timed_out' => false, 'stdout' => '', 'stderr' => $line];
        $cases = [
            [$failed, $path],
            [array_replace($failed, ['stderr' => '', 'stdout' => $line]), $path],
            [array_replace($failed, ['stderr' => $line."\n".$line]), $path],
            [array_replace($failed, ['rc' => 0]), null],
            [array_replace($failed, ['timed_out' => true]), null],
            [array_replace($failed, ['stderr' => 'Permission denied: '.$path]), null],
            [array_replace($failed, ['stderr' => $this->parseError($home.'/.lighttpd.conf')]), null],
            [array_replace($failed, ['stderr' => $this->parseError('/home/fixtur02/.lighttpd/custom.d/a.conf')]), null],
            [array_replace($failed, ['stderr' => $this->parseError($path.'/../a.conf')]), null],
            [array_replace($failed, ['stderr' => $this->parseError($path.'.disabled')]), null],
            [array_replace($failed, ['stderr' => $line."\n".$this->parseError(dirname($path).'/b.conf')]), null],
            [[], null],
        ];
        foreach ($cases as $index => $case) {
            $this->assertSame($case[1], \pmssLighttpdWatchdogCustomConfigFailure($home, $case[0]), (string) $index);
        }
    }

    public function testThreeFailuresSetAsideOnlyTheNamedFileAndAllowRestoration(): void
    {
        $result = $this->scenario('recover');
        $this->assertSame([false, false, true], $result['results']);
        $this->assertSame([1, 2, null], $result['counts']);
        $this->assertSame(false, $result['sourceExists']);
        $this->assertSame('broken', $result['backup']);
        $this->assertSame('sibling', $result['sibling']);
        $this->assertSame([0640, $result['uid']], $result['backupMetadata']);
        $this->assertSame('broken', $result['restored']);
        $this->assertSame(1, count($result['logs']));
        $this->assertStringContainsString('3 consecutive syntax failures', $result['logs'][0]);
        $this->assertStringContainsString('media-stack.conf.disabled-', $result['logs'][0]);
        $this->assertStringContainsString('rename it back', $result['logs'][0]);
        $this->assertStringContainsString("'-t' '-f'", $result['probeCommands'][0]);
        $this->assertSame(3, count($result['probeCommands']));
    }

    public function testResolvedOrUnattributedFailuresResetTheSequence(): void
    {
        foreach (['running', 'new-process', 'backoff', 'timeout', 'valid', 'generic', 'main-config', 'ambiguous'] as $scenario) {
            $result = $this->scenario($scenario);
            $this->assertSame([false, false, false], $result['results'], $scenario);
            $this->assertSame([1, 2, null], $result['counts'], $scenario);
            $this->assertSame('broken', $result['source'], $scenario);
            $this->assertSame(null, $result['backup'], $scenario);
            $this->assertSame([], $result['logs'], $scenario);
        }
    }

    public function testChangedContentsOrDifferentFragmentStartTheirOwnSequence(): void
    {
        foreach (['changed', 'different', 'replaced', 'malformed-state'] as $scenario) {
            $result = $this->scenario($scenario);
            $this->assertSame([false, false, false], $result['results'], $scenario);
            $this->assertSame([1, 2, 1], $result['counts'], $scenario);
            $this->assertSame(null, $result['backup'], $scenario);
        }
    }

    public function testUnsafeTargetsAndFailedPersistenceNeverAuthorizeRecovery(): void
    {
        foreach (['source-link', 'parent-link', 'hardlink', 'foreign-owner', 'write-failure', 'state-link', 'unsafe-runtime'] as $scenario) {
            $result = $this->scenario($scenario);
            $this->assertSame([false, false, false], $result['results'], $scenario);
            $this->assertSame(null, $result['backup'], $scenario);
            $this->assertSame([], $result['moveCommands'], $scenario);
            $this->assertSame('untouched', $result['outside'], $scenario);
        }
    }

    public function testRenameFailureChangesNoConfigurationAndIsLogged(): void
    {
        foreach (['move-failure', 'changed-at-move', 'collision'] as $scenario) {
            $result = $this->scenario($scenario);
            $this->assertSame([false, false, false], $result['results'], $scenario);
            $this->assertSame($scenario === 'changed-at-move' ? 'repaired' : 'broken', $result['source'], $scenario);
            $this->assertSame($scenario === 'collision' ? 'prior backup' : null, $result['backup'], $scenario);
            $this->assertSame(1, count($result['logs']), $scenario);
            $this->assertStringContainsString('refused or failed', $result['logs'][0], $scenario);
        }
    }

    public function testCronRetriesOnceOnlyAfterSuccessfulRecovery(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/checkLighttpdInstances.php');
        $start = strpos($source, '    for ($startAttempt = 0;');
        $this->assertTrue($start !== false);
        $end = strpos($source, '    if ($restartRequired) {', $start);
        $this->assertTrue($end !== false);
        $loop = substr($source, $start, $end - $start);
        // Exercise the actual cron block with fixed results, without launching services.
        $script = <<<'PHP'
namespace CustomConfigCronFixture;
function pmssUserWatchdogServiceSpec(...$args) { return []; }
function pmssUserWatchdogEnsureServices(...$args) { $GLOBALS['trace'][] = 'ensure'; }
function pmssLighttpdWatchdogCustomConfigRecover(...$args) {
    $GLOBALS['trace'][] = 'recover';
    return $GLOBALS['recovered'];
}
$results = [];
$thisUser = 'fixtur01';
$homeDir = '/home/fixtur01';
$configPath = $homeDir.'/.lighttpd.conf';
$lighttpdRunning = false;
foreach ([false, true] as $recovered) {
    $trace = [];
PHP;
        $script .= $loop;
        $script .= '$results[] = $trace; } echo json_encode($results);';
        $this->assertSame([['ensure', 'recover'], ['ensure', 'recover', 'ensure']], $this->pmssRunInlinePhpJson($script));
    }

    private function parseError(string $path): string
    {
        return 'source: '.$path.' line: 19 pos: 19 parser failed somehow near here: =';
    }

    /** Execute only temp-tree shell work; the real daemon and account launcher are stubbed. */
    private function scenario(string $scenario): array
    {
        $root = $this->pmssMakeTempDir('lighttpd-custom-recovery-');
        $script = <<<'PHP'
namespace CustomConfigFixture;
function pmssUserWatchdogProcessRunning($user, $process) { return $GLOBALS['process']; }
function pmssUserWatchdogBackoffActive($user, $service) { return $GLOBALS['backoff']; }
function pmssAccountPathIdentity($user, $home) { return ['uid' => $GLOBALS['accountUid']]; }
function fileowner($path) { return $path === $GLOBALS['home'] ? $GLOBALS['accountUid'] : \fileowner($path); }
function pmssCommandPath($binary) { return '/usr/sbin/lighttpd'; }
function pmssBuildUserServiceShellCommand($user, $command) { return 'account-uid '.$command; }
function pmssUserWatchdogCommandRun($command, $seconds, $user, $service) {
    $GLOBALS['probeCommands'][] = $command;
    return $GLOBALS['probe'];
}
function pmssAtomicJsonFileWrite($path, $data, $mode) {
    return !$GLOBALS['writeFailure'] && \pmssAtomicJsonFileWrite($path, $data, $mode);
}
function pmssUserLog($user, $message) { $GLOBALS['logs'][] = $message; }
function pmssAccountPathRun($user, $home, $paths, $command, $input, $seconds, &$timedOut) {
    $timedOut = false;
    $GLOBALS['moveCommands'][] = $command;
    if ($GLOBALS['scenario'] === 'move-failure') return false;
    if ($GLOBALS['scenario'] === 'changed-at-move') \file_put_contents($paths[0], 'repaired');
    if ($GLOBALS['scenario'] === 'collision') \file_put_contents($paths[1], 'prior backup');
    \exec($command, $output, $rc);
    return $rc === 0;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/watchdogCustomConfig.php', 'CustomConfigFixture');
        $script .= <<<'PHP'
[$root, $scenario] = json_decode(getenv('PMSS_TEST_CUSTOM_RECOVERY'), true);
$home = $root.'/homes/fixtur01';
mkdir($home.'/.lighttpd/custom.d', 0700, true);
mkdir($root.'/runtime', 0700);
putenv('PMSS_RUNTIME_DIR='.$root.'/runtime');
putenv('PMSS_HOME_DIR='.$root.'/homes');
$source = $home.'/.lighttpd/custom.d/media-stack.conf';
$sibling = dirname($source).'/sibling.conf';
$outside = $root.'/outside';
file_put_contents($source, 'broken');
chmod($source, 0640);
file_put_contents($sibling, 'sibling');
file_put_contents($outside, 'untouched');
$state = $root.'/runtime/checkLighttpdInstances-config-fixtur01.json';
$accountUid = posix_geteuid();
$process = $backoff = $writeFailure = false;
$logs = $probeCommands = $moveCommands = $results = $counts = [];
$error = static function ($path) { return 'source: '.$path.' line: 19 pos: 19 parser failed somehow near here: ='; };
$probe = ['rc' => 1, 'timed_out' => false, 'stdout' => '', 'stderr' => $error($source)];
for ($cycle = 0; $cycle < 3; $cycle++) {
    $running = false;
    if ($cycle === 2) {
        if ($scenario === 'running') $running = true;
        if ($scenario === 'new-process') $process = true;
        if ($scenario === 'backoff') $backoff = true;
        if ($scenario === 'timeout') $probe['timed_out'] = true;
        if ($scenario === 'valid') $probe['rc'] = 0;
        if ($scenario === 'generic') $probe['stderr'] = 'Permission denied';
        if ($scenario === 'main-config') $probe['stderr'] = $error($home.'/.lighttpd.conf');
        if ($scenario === 'ambiguous') $probe['stderr'] .= "\n".$error($sibling);
        if ($scenario === 'changed') file_put_contents($source, 'changed');
        if ($scenario === 'different') $probe['stderr'] = $error($sibling);
        if ($scenario === 'replaced') { rename($source, $root.'/original'); file_put_contents($source, 'broken'); }
        if ($scenario === 'malformed-state') file_put_contents($state, '{');
        if ($scenario === 'source-link') { unlink($source); symlink($outside, $source); }
        if ($scenario === 'parent-link') { rename(dirname($source), $root.'/fragments'); symlink($root.'/fragments', dirname($source)); }
        if ($scenario === 'hardlink') link($source, $root.'/hardlink');
        if ($scenario === 'foreign-owner') $accountUid++;
        if ($scenario === 'write-failure') $writeFailure = true;
        if ($scenario === 'state-link') { unlink($state); symlink($outside, $state); }
        if ($scenario === 'unsafe-runtime') chmod($root.'/runtime', 0777);
    }
    clearstatcache();
    $results[] = pmssLighttpdWatchdogCustomConfigRecover('fixtur01', $home, $home.'/.lighttpd.conf', $running);
    $counts[] = (\pmssJsonFileReadAssoc($state, true) ?? [])['count'] ?? null;
}
clearstatcache();
$backups = glob($source.'.disabled-*') ?: [];
$backup = $backups[0] ?? null;
$data = ['results' => $results, 'counts' => $counts, 'sourceExists' => file_exists($source),
    'source' => file_exists($source) ? file_get_contents($source) : null,
    'backup' => $backup === null ? null : file_get_contents($backup),
    'backupMetadata' => $backup === null ? null : [fileperms($backup) & 0777, \fileowner($backup)],
    'sibling' => file_get_contents($sibling), 'outside' => file_get_contents($outside),
    'logs' => $logs, 'probeCommands' => $probeCommands, 'moveCommands' => $moveCommands, 'uid' => posix_geteuid()];
if ($scenario === 'recover' && $backup !== null) { rename($backup, $source); $data['restored'] = file_get_contents($source); }
echo json_encode($data);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_CUSTOM_RECOVERY' => json_encode([$root, $scenario])]);
    }
}
