<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Characterize bootstrap snapshot transport and staging without live writes. */
class UpdateSnapshotLifecycleTest extends TestCase
{
    public function testArchiveTransportsKeepCommandsLabelsAndFailureBoundaries(): void
    {
        $root = $this->pmssMakeTempDir('pmss-snapshot-archive-');
        $bootstrap = $this->bootstrapFixture($root);
        $bin = $root.'/bin';
        foreach (['curl', 'tar', 'git'] as $command) {
            $this->pmssWriteExecutableFile($bin.'/'.$command,
                '#!/bin/sh'."\n".'[ "$PMSS_SNAPSHOT_FAIL" != '.escapeshellarg($command).' ] || exit 7'."\n"
                .($command === 'git' ? 'exit 7' : 'exit 0')."\n");
        }
        foreach (['release', 'git'] as $type) {
            foreach (['', 'curl', 'tar'] as $failure) {
                $spec = ['type' => $type, 'repo' => 'https://github.com/example/project.git',
                    'branch' => 'topic/a b', 'pin' => $type === 'release' ? 'tag/a b' : ''];
                $result = $this->pmssExecInlinePhp(
                    'require '.var_export($bootstrap, true).'; echo "FETCHED=".fetchSnapshot('
                    .var_export($spec, true).', '.var_export($root, true).');', $this->pmssPathPrefixedEnvironment($bin, ['PMSS_SNAPSHOT_FAIL' => $failure]));
                $curl = 'curl -sfL '.($type === 'git' ? '--http1.1 ' : '').'-A '.escapeshellarg(\CURL_UA).' '
                    .escapeshellarg($type === 'git'
                        ? 'https://codeload.github.com/example/project/tar.gz/refs/heads/topic%2Fa%20b'
                        : 'https://api.github.com/repos/MagnaCapax/PMSS/tarball/tag%2Fa%20b')
                    .' -o '.escapeshellarg($root.'/source.tgz');
                $tar = 'tar -xzf '.escapeshellarg($root.'/source.tgz').' -C '.escapeshellarg($root).' --strip-components=1';
                $this->assertSame($failure === '' ? 0 : 12, $result['rc'], $result['output']);
                $this->assertStringContainsString('[RUN] '.$curl."\n", $result['output']);
                if ($failure === 'curl') {
                    $this->assertStringNotContainsString('[RUN] '.$tar, $result['output']);
                } else {
                    $this->assertStringContainsString('[RUN] '.$tar."\n", $result['output']);
                }
                if ($failure === '') {
                    $this->assertStringContainsString('FETCHED='.($type === 'release' ? 'release:tag/a b'
                        : 'git/https://github.com/example/project.git:topic/a b'), $result['output']);
                } else {
                    $this->assertStringNotContainsString('FETCHED=', $result['output']);
                }
            }
        }
    }

    public function testDryRunKeepsTreeOrderAndMissingOrEmptyWarnings(): void
    {
        foreach (['missing', 'empty', 'populated'] as $state) {
            $root = $this->pmssMakeTempDir('pmss-snapshot-stage-');
            $bootstrap = $this->bootstrapFixture($root);
            $this->pmssWriteFile($root.'/scripts/update.php', '<?php');
            $this->pmssWriteFile($root.'/scripts/util/update-step2.php', '<?php');
            foreach (['etc', 'var'] as $tree) {
                if ($state === 'empty') $this->pmssEnsureDir($root.'/'.$tree);
                if ($state === 'populated') $this->pmssWriteFile($root.'/'.$tree.'/.hidden', 'keep');
            }
            $outputPath = $root.'/dry-run-output';
            $result = $this->pmssExecShellCommand($this->pmssBuildInlinePhpCommand(
                'require '.var_export($bootstrap, true).'; stageSnapshot('.var_export($root, true).', true);'
            ).' > '.escapeshellarg($outputPath).' 2>&1');
            $result['output'] = (string) file_get_contents($outputPath);
            $expected = '[DRY RUN] Would atomically swap /scripts from '.$root.'/scripts'."\n";
            $expected .= $state === 'populated'
                ? '[DRY RUN] Would atomically swap /etc/skel and /etc/seedbox and overlay other /etc files from '.$root.'/etc'."\n"
                    .'[DRY RUN] Would copy var from '.$root.'/var'."\n"
                : "[WARN] Snapshot etc tree missing or empty, skipping copy\n[WARN] Snapshot var tree missing or empty, skipping copy\n";
            $this->assertSame(0, $result['rc'], $result['output']);
            $this->assertSame($expected, $result['output']);
            $this->assertSame('<?php', file_get_contents($root.'/scripts/update.php'));
        }
    }

    public function testDryRunStillRejectsUnsafeTreesBeforeDispatch(): void
    {
        foreach (['root-link', 'absolute-link', 'file-tree'] as $kind) {
            $root = $this->pmssMakeTempDir('pmss-snapshot-unsafe-');
            $bootstrap = $this->bootstrapFixture($root);
            $this->pmssWriteFile($root.'/scripts/update.php', '<?php');
            $this->pmssWriteFile($root.'/scripts/util/update-step2.php', '<?php');
            if ($kind === 'root-link') $this->pmssCreateSymlinkOrSkip($root.'/scripts', $root.'/etc');
            if ($kind === 'absolute-link') {
                $this->pmssEnsureDir($root.'/etc');
                $this->pmssCreateSymlinkOrSkip('/etc/passwd', $root.'/etc/unsafe');
            }
            if ($kind === 'file-tree') $this->pmssWriteFile($root.'/etc', 'not a directory');
            $result = $this->pmssExecInlinePhp(
                'require '.var_export($bootstrap, true).'; stageSnapshot('.var_export($root, true).', true);'
            );
            $this->assertSame(13, $result['rc'], $result['output']);
            $this->assertStringContainsString('Unsafe snapshot etc tree', $result['output']);
            $this->assertStringNotContainsString('Would copy var', $result['output']);
        }
    }

    public function testPublicationKeepsCompleteCommandOrderForEveryConfigBase(): void
    {
        foreach ([false, true] as $installed) {
            foreach (['missing', 'empty', 'populated'] as $seedbox) {
                $root = $this->pmssMakeTempDir('pmss-snapshot-publish-');
                $bootstrap = $this->bootstrapFixture($root, true);
                foreach (['scripts/update.php', 'scripts/util/update-step2.php', 'etc/skel/.hidden', 'var/state'] as $file) {
                    $this->pmssWriteFile($root.'/'.$file, 'fixture');
                }
                if ($seedbox !== 'missing') $this->pmssEnsureDir($root.'/etc/seedbox');
                if ($seedbox === 'populated') $this->pmssWriteFile($root.'/etc/seedbox/config', 'config');
                $result = $this->pmssExecInlinePhp(
                    'require '.var_export($bootstrap, true).'; stageSnapshot('.var_export($root, true).', false);'
                    .' echo json_encode($GLOBALS["snapshotCalls"]);', ['PMSS_SNAPSHOT_INSTALLED' => $installed ? '1' : '0']);
                $this->assertSame(0, $result['rc'], $result['output']);
                $trace = preg_replace('/pmss-(staging|backup)-[a-f0-9]{8}/', 'pmss-$1-RUN', $result['output']);
                $expected = [
                    ['remove', '/scripts.pmss-staging-RUN', 'scripts staging'],
                    ['run', 'cp -a '.escapeshellarg($root.'/scripts')."/. '/scripts.pmss-staging-RUN'", 13],
                    ['publish', '/scripts', '/scripts.pmss-staging-RUN', '/scripts.pmss-backup-RUN', 'scripts'],
                    ['remove', '/scripts.pmss-backup-RUN', 'scripts backup'],
                    ['remove', '/etc/skel.pmss-staging-RUN', 'skel staging'],
                    ['run', 'cp -a '.escapeshellarg($root.'/etc/skel')."/. '/etc/skel.pmss-staging-RUN'", 13],
                    ['publish', '/etc/skel', '/etc/skel.pmss-staging-RUN', '/etc/skel.pmss-backup-RUN', 'skeleton tree'],
                    ['remove', '/etc/skel.pmss-backup-RUN', 'skel backup'],
                ];
                if ($installed || $seedbox === 'populated') {
                    $expected[] = ['remove', '/etc/seedbox.pmss-staging-RUN', 'seedbox staging'];
                    $expected[] = $installed
                        ? ['run', "cp -a '/etc/seedbox' '/etc/seedbox.pmss-staging-RUN'", 13]
                        : ['mkdir', '/etc/seedbox.pmss-staging-RUN', 0755];
                    if ($seedbox === 'populated') {
                        $expected[] = ['run', 'cp -a '.escapeshellarg($root.'/etc/seedbox')."/. '/etc/seedbox.pmss-staging-RUN'", 13];
                    }
                    $expected[] = ['publish', '/etc/seedbox', '/etc/seedbox.pmss-staging-RUN', '/etc/seedbox.pmss-backup-RUN', 'seedbox config'];
                    $expected[] = ['remove', '/etc/seedbox.pmss-backup-RUN', 'seedbox backup'];
                }
                $expected[] = ['run', 'cp -a '.escapeshellarg($root.'/var').' /', 13];
                $expected[] = ['run', 'chmod -R o-rwx /scripts /root /etc/skel /etc/seedbox', 13];
                $expected[] = ['run', 'find /scripts -type f -name "*.php" -exec chmod 0750 {} +', 13];
                $expected[] = ['run', 'chmod 0750 /scripts/update.php', 13];
                $expected[] = ['flatten'];
                $this->assertSame($expected, json_decode($trace, true), $result['output']);
            }
        }
    }

    public function testSkeletonSwapReplacesTreeAndSkipsOverlay(): void
    {
        [$root, $live] = $this->skeletonFixtureTrees(true);
        $result = $this->runSkeletonStage($root, $live);

        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame('new', (string) @file_get_contents($live.'/etc/skel/new.txt'));
        $this->assertTrue(!file_exists($live.'/etc/skel/old.txt'), 'files removed from the snapshot skeleton must not survive');
        $this->assertTrue(!file_exists($live.'/etc/skel/nested'), 'removed skeleton directories must not survive');
        $this->assertSame([], glob($live.'/etc/skel.pmss-*') ?: [], 'staging and backup trees must be cleaned up');
        $this->assertStringNotContainsString('cp -rpu', $result['output'], 'skel must not be overlaid after the swap');
    }

    public function testSkeletonStagingFailureLeavesInstalledTreeIntact(): void
    {
        [$root, $live] = $this->skeletonFixtureTrees(true);
        $bin = $root.'/bin';
        // Fail the staging copy half way: one file lands in staging, then cp exits non-zero.
        $this->pmssWriteExecutableFile($bin.'/cp', '#!/bin/sh'."\n".'mkdir -p "$3" && echo partial > "$3/partial.txt"'."\n".'exit 1'."\n");
        $result = $this->runSkeletonStage($root, $live, $this->pmssPathPrefixedEnvironment($bin));

        $this->assertSame(13, $result['rc'], $result['output']);
        $this->assertInstalledSkeletonUnchanged($live);
    }

    public function testFailedSkeletonRenameRestoresInstalledTree(): void
    {
        [$root, $live] = $this->skeletonFixtureTrees(true);
        $result = $this->runSkeletonStage($root, $live, ['PMSS_SKEL_FAIL_RENAME' => '1']);

        $this->assertSame(13, $result['rc'], $result['output']);
        $this->assertStringContainsString('restored previous tree', $result['output']);
        $this->assertInstalledSkeletonUnchanged($live);
        $this->assertSame([], glob($live.'/etc/skel.pmss-backup-*') ?: [], 'backup must be renamed back into place');
    }

    public function testEmptySnapshotSkeletonDoesNotReplaceInstalledTree(): void
    {
        [$root, $live] = $this->skeletonFixtureTrees(false);
        $result = $this->runSkeletonStage($root, $live);

        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertStringContainsString('[WARN] Snapshot skel tree is empty, keeping installed /etc/skel', $result['output']);
        $this->assertInstalledSkeletonUnchanged($live);
    }

    /** @return array{0: string, 1: string} snapshot root and relocated live root */
    private function skeletonFixtureTrees(bool $populatedSnapshot): array
    {
        $root = $this->pmssMakeTempDir('pmss-snapshot-skel-');
        $live = $this->pmssMakeTempDir('pmss-snapshot-skel-live-');
        $this->pmssEnsureDir($root.'/etc/skel');
        if ($populatedSnapshot) $this->pmssWriteFile($root.'/etc/skel/new.txt', 'new');
        $this->pmssWriteFile($live.'/etc/skel/old.txt', 'old');
        $this->pmssWriteFile($live.'/etc/skel/nested/keep.txt', 'keep');
        return [$root, $live];
    }

    private function assertInstalledSkeletonUnchanged(string $live): void
    {
        $this->assertSame('old', (string) @file_get_contents($live.'/etc/skel/old.txt'), 'installed skeleton must stay intact');
        $this->assertSame('keep', (string) @file_get_contents($live.'/etc/skel/nested/keep.txt'), 'installed skeleton must stay intact');
        $this->assertTrue(!file_exists($live.'/etc/skel/new.txt'), 'snapshot files must not leak into the installed skeleton');
        $this->assertTrue(!file_exists($live.'/etc/skel/partial.txt'), 'a partial staging copy must not leak into the installed skeleton');
    }

    /** Run the real etc staging with /etc/skel and /etc/seedbox relocated under $live. */
    private function runSkeletonStage(string $root, string $live, array $environment = []): array
    {
        $source = preg_replace('/^#![^\n]*\n/', '', $this->pmssReadRepoFile('scripts/update.php'));
        $source = str_replace("const JSON_LOG              = '/var/log/pmss-update.jsonl';",
            'const JSON_LOG = '.var_export($root.'/events.jsonl', true).';', $source);
        $source = str_replace('if (!function_exists(\'logmsg\')) {',
            'function logmsg(string $message): void { echo $message."\\n"; } if (false) {', $source);
        // Relocate the live trees and their allow-listed staging/backup names together.
        $source = str_replace(["'/etc/skel", "'/etc/seedbox"], ["'".$live.'/etc/skel', "'".$live.'/etc/seedbox'], $source);
        $source = str_replace('@rename(', '@skelFixtureRename(', $source);
        $source .= <<<'PHP'

function skelFixtureRename(string $from, string $to): bool {
    if (getenv('PMSS_SKEL_FAIL_RENAME') === '1' && strpos($from, '/etc/skel.pmss-staging-') !== false) {
        return false;
    }
    return rename($from, $to);
}
PHP;
        $bootstrap = $this->pmssWriteFile($root.'/bootstrap.php', $source);
        return $this->pmssExecInlinePhp('require '.var_export($bootstrap, true).'; pmssStageSnapshotEtc('
            .var_export($root, true).', '.var_export($root.'/etc', true).', "abcd1234");', $environment);
    }

    /** Redirect bootstrap diagnostics into the fixture; do not invoke its CLI. */
    private function bootstrapFixture(string $root, bool $recordPublication = false): string
    {
        $source = preg_replace('/^#![^\n]*\n/', '', $this->pmssReadRepoFile('scripts/update.php'));
        $source = str_replace("const JSON_LOG              = '/var/log/pmss-update.jsonl';",
            'const JSON_LOG = '.var_export($root.'/events.jsonl', true).';', $source);
        $source = str_replace('if (!function_exists(\'logmsg\')) {',
            'function logmsg(string $message): void { echo $message."\\n"; } if (false) {', $source);
        if ($recordPublication) {
            // Replace only mutation boundaries. Validation and orchestration run unchanged.
            foreach (['pmssRunBootstrapCommand', 'pmssRemoveTreeBestEffort', 'pmssAtomicSwapDirectory',
                'pmssEnsureDirectory', 'flattenScriptsLayout'] as $name) {
                $source = str_replace('function '.$name.'(', 'function snapshotUnused'.$name.'(', $source);
            }
            $source = str_replace('is_dir(', 'snapshotFixtureIsDir(', $source);
            $source .= <<<'PHP'

$GLOBALS['snapshotCalls'] = [];
function snapshotFixtureIsDir(string $path): bool {
    if ($path === '/etc/skel') return true;
    if ($path === '/etc/seedbox') return getenv('PMSS_SNAPSHOT_INSTALLED') === '1';
    return is_dir($path);
}
function pmssRunBootstrapCommand(string $command, ?int $fatalCode = null): int {
    $GLOBALS['snapshotCalls'][] = ['run', $command, $fatalCode]; return 0;
}
function pmssRemoveTreeBestEffort(string $path, string $label): void {
    $GLOBALS['snapshotCalls'][] = ['remove', $path, $label];
}
function pmssAtomicSwapDirectory(string $target, string $staging, string $backup, string $label): void {
    $GLOBALS['snapshotCalls'][] = ['publish', $target, $staging, $backup, $label];
}
function pmssEnsureDirectory(string $path, int $mode = 0755): void {
    $GLOBALS['snapshotCalls'][] = ['mkdir', $path, $mode];
}
function flattenScriptsLayout(): void { $GLOBALS['snapshotCalls'][] = ['flatten']; }
PHP;
        }
        return $this->pmssWriteFile($root.'/bootstrap.php', $source);
    }
}
