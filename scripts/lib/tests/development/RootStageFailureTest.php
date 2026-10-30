<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise managed publication failures with fixture-only identities and I/O faults. */
class RootStageFailureTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-root-failure-']; }

    public function testEveryPrePublishFailureNamesItsStageAndPreservesOldFile(): void
    {
        foreach (['context', 'parent', 'mkdir', 'stage-check', 'open', 'write', 'flush',
            'owner', 'group', 'mode', 'single-regular', 'immutable-clear', 'publish-stderr',
            'unlink', 'publish-launch'] as $fault) {
            $result = $this->runWriter($fault);
            $stage = ['flush' => 'write', 'unlink' => 'publish-stderr'][$fault] ?? $fault;
            $this->assertFalse($result['ok'], $fault);
            $this->assertSame('stage='.$stage.' rc=not-started', $result['failure'], $fault);
            $this->assertSame('old', $result['content'], $fault);
            $this->assertSame([], $result['stages'], $fault);
            $this->assertFalse($result['captureOpen'], $fault);
        }
    }

    public function testPublishRefusalNamesPinnedParentAndPreservesBothDirectories(): void
    {
        $result = $this->runWriter('parent-swap');
        $this->assertFalse($result['ok']);
        $this->pmssAssertArraySubsetSame([
            'failure' => 'stage=publish rc=1 stderr=publish parent changed', 'movedContent' => 'old',
            'content' => 'replacement-parent', 'stages' => [],
        ], $result);
    }

    public function testPublishCapturesBoundedSanitizedStderrAndRestoresImmutableState(): void
    {
        $result = $this->runWriter('publish');
        $this->assertFalse($result['ok']);
        $this->pmssAssertArraySubsetSame([
            'failure' => 'stage=publish rc=23 stderr=quota full [31m '.str_repeat('E', 283), 'content' => 'old',
            'immutable' => ['clear', 'set'], 'descriptor' => [0, 0600],
        ], $result);
        $this->assertFalse($result['captureOpen']);
        $this->assertSame([], $result['stages']);
    }

    public function testSuccessClearsStaleFailureAndRetainsLegacyInvocation(): void
    {
        foreach (['success', 'legacy', 'immutable-success'] as $scenario) {
            $result = $this->runWriter($scenario);
            $this->assertTrue($result['ok']);
            $this->pmssAssertArraySubsetSame([
                'failure' => $scenario === 'legacy' ? 'stale' : '', 'content' => 'private-payload', 'stages' => [],
            ], $result);
            $this->assertFalse($result['captureOpen']);
        }
    }

    public function testImmutableSetFailureIsReportedAfterPublication(): void
    {
        $result = $this->runWriter('immutable-set');
        $this->assertFalse($result['ok']);
        $this->pmssAssertArraySubsetSame([
            'failure' => 'stage=immutable-set rc=not-started', 'content' => 'private-payload',
            'immutable' => ['clear', 'set'], 'stages' => [],
        ], $result);
    }

    public function testExistingMetadataLogAndSerializedCallbackReceiveCauseWithoutContent(): void
    {
        $metadata = $this->runWriter('metadata');
        $this->assertStringContainsString('(stage=group rc=not-started)', $metadata['log']);
        $this->assertFalse(strpos($metadata['log'], 'private-payload') !== false);
        $serialized = $this->runWriter('serialized');
        $this->assertFalse($serialized['ok']);
        $this->assertSame([[$serialized['path'], 'stage=group rc=not-started']], $serialized['callbacks']);
        $this->assertSame('old', $serialized['content']);
    }

    /** Run real staging and shell publication only inside a disposable fixture tree. */
    private function runWriter(string $fault): array
    {
        if (!function_exists('posix_geteuid')) throw new SkipTest('POSIX identity functions required');
        $root = $this->pmssMakeTempDir('pmss-root-stage-case-');
        $home = $this->pmssEnsureDir($root.'/alice');
        $parent = $this->pmssEnsureDir($home.'/inside');
        $path = $this->pmssWriteFile($parent.'/target', 'old');
        $script = <<<'PHP'
namespace RootStageFailureFixture;
function posix_geteuid() { return $GLOBALS['fault'] === 'context' ? -1 : 0; }
function posix_getpwnam($name) { return ['uid' => \posix_geteuid(), 'gid' => \posix_getegid()]; }
function lstat($path) {
    $stat = \lstat($path);
    if ($GLOBALS['fault'] === 'parent' && $path === $GLOBALS['parent']) return false;
    if ($path === $GLOBALS['root'] || strpos($path, '/.pmss-root-stage-') !== false || $path === $GLOBALS['path']) {
        if (is_array($stat)) $stat['uid'] = 0;
    }
    if (strpos($path, '/.pmss-root-stage-') !== false) {
        if ($GLOBALS['fault'] === 'stage-check' && is_dir($path)) return false;
        if ($GLOBALS['fault'] === 'single-regular' && substr($path, -5) === '/file') $stat['nlink'] = 2;
    }
    return $stat;
}
function mkdir($path, $mode) { return $GLOBALS['fault'] === 'mkdir' ? false : \mkdir($path, $mode); }
function fopen($path, $mode) { return $GLOBALS['fault'] === 'open' ? false : \fopen($path, $mode); }
function pmssStreamWriteAll($handle, $content) { return $GLOBALS['fault'] === 'write' ? false : \pmssStreamWriteAll($handle, $content); }
function fflush($handle) { return $GLOBALS['fault'] === 'flush' ? false : \fflush($handle); }
function chown($path, $owner) { return $GLOBALS['fault'] !== 'owner'; }
function chgrp($path, $group) { return !in_array($GLOBALS['fault'], ['group', 'metadata', 'serialized'], true); }
function chmod($path, $mode) { return $GLOBALS['fault'] === 'mode' ? false : \chmod($path, $mode); }
function tmpfile() { return $GLOBALS['fault'] === 'publish-stderr' ? false : \tmpfile(); }
function unlink($path) {
    if ($GLOBALS['fault'] === 'unlink' && strpos($path, '/.pmss-root-stage-') === false) return false;
    return \unlink($path);
}
function exec($command, &$output, &$status) {
    $step = strpos($command, '--clear ') !== false ? 'clear' : 'set';
    $GLOBALS['immutableSteps'][] = $step;
    $status = $GLOBALS['fault'] === 'immutable-'.$step ? 1 : 0;
}
function proc_open($command, $descriptors, &$pipes) {
    $GLOBALS['capture'] = $descriptors[2];
    $stat = \fstat($descriptors[2]);
    $GLOBALS['descriptor'] = [$stat['nlink'], $stat['mode'] & 0777];
    if ($GLOBALS['fault'] === 'publish-launch') return false;
    if ($GLOBALS['fault'] === 'publish') {
        $command = 'printf "quota\nfull\t\033[31m\000" >&2; head -c 262144 /dev/zero | tr "\000" E >&2; exit 23';
    }
    if ($GLOBALS['fault'] === 'parent-swap') {
        // Change the directory after stat pins it but before cd resolves the name.
        $swap = 'mv -- '.escapeshellarg($GLOBALS['parent']).' '.escapeshellarg($GLOBALS['parent'].'-moved')
            .'; mkdir -- '.escapeshellarg($GLOBALS['parent']).'; printf replacement-parent > '.escapeshellarg($GLOBALS['path']).'; ';
        $command = str_replace('( cd -P', $swap.'( cd -P', $command);
    }
    return \proc_open($command, $descriptors, $pipes);
}
PHP;
        foreach (['fault' => $fault, 'root' => $root, 'parent' => $parent, 'path' => $path] as $key => $value) {
            $script .= '$GLOBALS['.var_export($key, true).'] = '.var_export($value, true).';';
        }
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/lighttpd/userFileWrite.php'), true).';';
        foreach (['rootStage.php', 'rootStagePublish.php', 'userFilePath.php', 'userFileMetadata.php', 'userFileSerialized.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/'.$module, 'RootStageFailureFixture');
        }
        $script .= 'ini_set("error_log", '.var_export($root.'/errors.log', true).');';
        $script .= <<<'PHP'
$failure = 'stale'; $callbacks = [];
$immutable = in_array($GLOBALS['fault'], ['immutable-clear', 'immutable-set', 'immutable-success', 'publish'], true);
if ($GLOBALS['fault'] === 'metadata') {
    pmssUserFileApplyMetadata($GLOBALS['path'], 'root', 0640); $ok = false;
} elseif ($GLOBALS['fault'] === 'serialized') {
    $ok = pmssManagedSerializedTargetsWrite('private-payload', [[$GLOBALS['path'], 'root', 0640, false]],
        function ($path, $reason = '') use (&$callbacks) { $callbacks[] = [$path, $reason]; });
} elseif ($GLOBALS['fault'] === 'legacy') {
    $ok = pmssRootStageFileWrite($GLOBALS['path'], 'private-payload', 'root', 0640);
} else {
    $ok = pmssRootStageFileWrite($GLOBALS['path'], 'private-payload', 'root', 0640, $immutable, $failure);
}
echo json_encode(['ok' => $ok, 'failure' => $failure, 'path' => $GLOBALS['path'], 'callbacks' => $callbacks,
    'content' => \file_get_contents($GLOBALS['path']),
    'movedContent' => is_file($GLOBALS['parent'].'-moved/target') ? \file_get_contents($GLOBALS['parent'].'-moved/target') : null,
    'stages' => glob($GLOBALS['root'].'/.pmss-root-stage-*'), 'immutable' => $GLOBALS['immutableSteps'] ?? [],
    'descriptor' => $GLOBALS['descriptor'] ?? null, 'captureOpen' => is_resource($GLOBALS['capture'] ?? null),
    'log' => is_file($GLOBALS['root'].'/errors.log') ? \file_get_contents($GLOBALS['root'].'/errors.log') : '']);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_HOME_DIR' => $root]);
    }
}
