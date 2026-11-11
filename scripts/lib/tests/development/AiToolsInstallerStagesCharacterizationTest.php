<?php
namespace PMSS\Tests\AiToolsStagesFixture;

/** All stage effects are observations; these functions never reach installed tools. */
function event(string $kind, ...$values): void
{
    $GLOBALS['pmssAiStages']['events'][] = array_merge([$kind], $values);
    if (in_array($kind, ['unlink', 'rmdir'], true)
        && isset($GLOBALS['pmssAiStages']['cleanupFaults'][$values[0]])) {
        throw $GLOBALS['pmssAiStages']['cleanupFaults'][$values[0]];
    }
}
function is_dir(string $path): bool { return $GLOBALS['pmssAiStages']['directory'] ?? false; }
function is_file(string $path): bool { return $GLOBALS['pmssAiStages']['files'][$path] ?? true; }
function is_link(string $path): bool { return $GLOBALS['pmssAiStages']['links'][$path] ?? false; }
function is_executable(string $path): bool { return $GLOBALS['pmssAiStages']['executables'][$path] ?? true; }
function pmssDirectoryEntriesRead(string $path): array { event('inventory', $path); return $GLOBALS['pmssAiStages']['entries'] ?? []; }
function pmssRootOwnedTreeNeedsRefresh(string $path): bool { event('refresh', $path); return $GLOBALS['pmssAiStages']['refresh'] ?? false; }
function pmssRootOwnedTreeRemove(string $path): bool { event('remove', $path); return $GLOBALS['pmssAiStages']['remove'][$path] ?? true; }
function pmssCommandPath(string $name): string { return $GLOBALS['pmssAiStages']['commands'][$name] ?? '/usr/bin/'.$name; }
function pmssAppVersionProbeMatch(array $commands, string $pattern, int $group) { event('version', $commands, $pattern, $group); return $GLOBALS['pmssAiStages']['major'] ?? '22'; }
function pmssBuildCommand(string $binary, array $args): string { return json_encode([$binary, $args]); }
function runStep(string $label, string $command): int
{
    event('step', $label, $command);
    if (($GLOBALS['pmssAiStages']['throwAt'] ?? '') === $label) throw $GLOBALS['pmssAiStages']['throwable'];
    return $GLOBALS['pmssAiStages']['rc'][$label] ?? 0;
}
function pmssPinnedRemoteArtifactTempFileUse(string $label, string $url, string $hash, callable $use, bool $required, array $options)
{
    event('download', $label, $url, $hash, $required, $options);
    return array_key_exists('artifactResult', $GLOBALS['pmssAiStages'])
        ? $GLOBALS['pmssAiStages']['artifactResult'] : $use('/fixture/archive');
}
function file_get_contents(string $path) { event('read', $path); return 'launcher fixture'; }
function pmssJsonDecodeAssoc(string $raw): ?array { return $GLOBALS['pmssAiStages']['package'] ?? ['version' => '2.1.112']; }
function getenv(string $name) { return $GLOBALS['pmssAiStages']['path'] ?? false; }
function putenv(string $value): bool { event('env', $value); return true; }
function pmssAiToolsGeminiInstallNeeded(...$args): bool { event('needed', 'gemini'); return $GLOBALS['pmssAiStages']['launcherNeeded'] ?? true; }
function pmssAiToolsClaudeInstallNeeded(...$args): bool { event('needed', 'claude'); return $GLOBALS['pmssAiStages']['launcherNeeded'] ?? true; }
function pmssAiToolsCodexInstallNeeded(...$args): bool { event('needed', 'codex', $args[4]); return $GLOBALS['pmssAiStages']['launcherNeeded'] ?? true; }
function pmssAiToolsGeminiDefaults(): string { return 'defaults fixture'; }
function pmssAiToolsGeminiStub(): string { return 'gemini fixture'; }
function pmssAiToolsClaudeStub(): string { return 'claude fixture'; }
function pmssAiToolsCodexStub(): string { return 'codex fixture'; }
function pmssAiToolsFilesPublish(array $files, callable $logger): void { event('publish', $files); }
function pmssAiToolsCodexFallbackConfigEnsure(bool $dryRun): void { event('fallback', $dryRun); }
function php_uname(string $mode): string { return $GLOBALS['pmssAiStages']['kernel'] ?? '6.1'; }
function pmssCreatePrivateTempDir(string $prefix): ?string { event('allocate', $prefix); return array_key_exists('downloadDir', $GLOBALS['pmssAiStages']) ? $GLOBALS['pmssAiStages']['downloadDir'] : '/fixture/download'; }
function tempnam(string $directory, string $prefix) { event('temporary', $directory, $prefix); return $GLOBALS['pmssAiStages']['temporary'] ?? $directory.'/.pmss-fixture'; }
function rename(string $source, string $destination): bool { event('rename', $source, $destination); return $GLOBALS['pmssAiStages']['rename'] ?? true; }
function unlink(string $path): bool { event('unlink', $path); return empty($GLOBALS['pmssAiStages']['cleanupFalse']['unlink']); }
function rmdir(string $path): bool { event('rmdir', $path); return empty($GLOBALS['pmssAiStages']['cleanupFalse']['rmdir']); }

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../testing/filesystem.php';
require_once __DIR__.'/../../update/apps/aiTools/node.php';
require_once __DIR__.'/../../update/apps/aiTools/npm.php';
require_once __DIR__.'/../../update/apps/aiTools/codexInstall.php';

class AiToolsInstallerStagesCharacterizationTest extends TestCase
{
    /** Export the production functions with only their public pin constants substituted. */
    protected function setUp(): void
    {
        if (!function_exists(__NAMESPACE__.'\\AiToolsStagesFixture\\pmssAiToolsNodeRuntimeResolve')) {
            foreach (['pmssAiToolsNodeRuntimeResolve', 'pmssAiToolsNpmInstall', 'pmssAiToolsCodexInstall'] as $name) {
                eval(\pmssTestingFunctionExport($name, __NAMESPACE__.'\\AiToolsStagesFixture', [
                    '__DIR__' => var_export(dirname((new \ReflectionFunction($name))->getFileName()), true),
                    'PMSS_AI_CODEX_CHAT_TAGS' => "['rust-v0.93.0']",
                    'PMSS_AI_CODEX_TAG' => "(\$GLOBALS['pmssAiStages']['tag'] ?? 'rust-v0.93.0')",
                ]));
            }
        }
        $GLOBALS['pmssAiStages'] = ['events' => []];
    }

    protected function tearDown(): void { unset($GLOBALS['pmssAiStages']); }
    private function resetFixture(array $values = []): void { $GLOBALS['pmssAiStages'] = $values + ['events' => []]; }
    private function events(string $kind): array
    {
        return array_values(array_filter($GLOBALS['pmssAiStages']['events'], static function (array $event) use ($kind): bool {
            return $event[0] === $kind;
        }));
    }
    private function logger(): callable { return static function (string $message): void { AiToolsStagesFixture\event('log', $message); }; }

    public function testEntryPointRetainsStageOrder(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/apps/aiToolsInstall.php', [
            "require_once __DIR__.'/../rootOwnedTree.php';",
            "pmssRequireRelativeFiles(__DIR__.'/aiTools', ['node.php', 'npm.php', 'codexInstall.php'])",
            "pmssEnvFlagEnabled('PMSS_FORCE_AI_TOOLS_REFRESH')", "pmssEnvFlagEnabled('PMSS_DRY_RUN')",
            'pmssPinnedRemoteAmd64ArtifactsSupported(php_uname(',
            'pmssAiToolsNodeRuntimeResolve(', 'pmssAiToolsNpmInstall(', 'pmssAiToolsCodexInstall(',
        ]);
    }

    public function testNodeCleanupPreservesInventoryOrderNamesAndFailures(): void
    {
        $root = '/opt/pmss/ai-tools';
        $staged = $root.'/.pmss-remove-0123456789abcdef';
        $old = $root.'/node-v20.1-linux-x64';
        $this->resetFixture(['directory' => true, 'remove' => [$staged => false], 'entries' => [
            '.pmss-remove-0123456789abcdef', 'node-v22.23.4-linux-x64', 'node-v20.1-linux-x64',
            '.pmss-remove-short', 'node-v20-linux-arm64', 'unrelated',
        ]]);
        $this->assertSame('/usr/bin/node', AiToolsStagesFixture\pmssAiToolsNodeRuntimeResolve(false, false, $this->logger()));
        $this->assertSame([
            ['inventory', $root],
            ['log', '[INFO] Removing staged AI tools Node.js runtime: '.$staged], ['remove', $staged],
            ['log', '[WARN] Unable to remove staged AI tools Node.js runtime: '.$staged],
            ['log', '[INFO] Removing unused AI tools Node.js runtime: '.$old], ['remove', $old],
            ['version', ["'/usr/bin/node' --version 2>/dev/null"], '/^v?([0-9]+)/', 1],
        ], $GLOBALS['pmssAiStages']['events']);
    }

    public function testNodeSelectionAndDownloadGates(): void
    {
        $binary = '/opt/pmss/ai-tools/node-v22.23.4-linux-x64/bin/node';
        foreach ([[false, false, '21', false, null, ''], [true, true, '21', false, null, $binary],
            [false, true, '21', true, null, $binary], [false, true, '21', false, false, ''],
            [false, true, '21', false, '/fixture/selected', '/fixture/selected'],
            [false, false, '22', false, null, '/usr/bin/node']] as $case) {
            [$dryRun, $supported, $major, $executable, $artifactResult, $expected] = $case;
            $values = ['major' => $major, 'directory' => true, 'executables' => [$binary => $executable]];
            if ($artifactResult !== null) $values['artifactResult'] = $artifactResult;
            $this->resetFixture($values);
            $this->assertSame($expected, AiToolsStagesFixture\pmssAiToolsNodeRuntimeResolve($dryRun, $supported, $this->logger()));
            $download = $supported && $major === '21' && !$executable;
            $this->assertSame($download ? [['download', 'Node.js runtime for AI CLI tools',
                'https://nodejs.org/dist/v22.23.4/node-v22.23.4-linux-x64.tar.xz',
                '1805bcd35e6815559bd2eb97db438d3fdb0eb3a5e6a977b7c1badca76337e9ce', true,
                ['tempPrefix' => 'pmss-ai-node-', 'downloadDescription' => 'Downloading pinned Node.js runtime for AI CLI tools']]] : [], $this->events('download'));
            if ($dryRun) $this->assertSame([], $this->events('inventory'));
        }
    }

    public function testNpmMissingNodeAndMissingNpmNeverPublish(): void
    {
        AiToolsStagesFixture\pmssAiToolsNpmInstall('', false, false, $this->logger());
        $this->assertSame([], $GLOBALS['pmssAiStages']['events']);
        $this->resetFixture(['commands' => ['npm' => ''], 'executables' => ['/fixture/bin/npm' => false]]);
        AiToolsStagesFixture\pmssAiToolsNpmInstall('/fixture/bin/node', true, false, $this->logger());
        $this->assertSame([['env', 'PATH=/fixture/bin'],
            ['log', '[WARN] Skipping Gemini CLI install: npm not available'],
            ['log', '[WARN] Skipping Claude Code install: npm not available']], $GLOBALS['pmssAiStages']['events']);
    }

    public function testNpmPublicationRetainsToolOrderPayloadsAndModes(): void
    {
        $root = '/opt/pmss/ai-tools';
        $source = dirname(__DIR__, 2).'/update/apps/templates/pmss-ai-launch.php';
        $this->resetFixture(['path' => '/usr/bin:/bin']);
        AiToolsStagesFixture\pmssAiToolsNpmInstall('/fixture/bin/node', false, false, $this->logger());
        $this->assertSame([['env', 'PATH=/fixture/bin:/usr/bin:/bin']], $this->events('env'));
        $this->assertSame([['needed', 'gemini'], ['needed', 'claude']], $this->events('needed'));
        $this->assertSame([
            ['publish', [[$root.'/pmss-ai-launch.php', 'launcher fixture', 0644, 'Unable to install Gemini CLI launcher'],
                [$root.'/gemini-virsilipas-defaults.json', 'defaults fixture', 0644, 'Unable to install Gemini defaults'],
                ['/usr/local/bin/gemini', 'gemini fixture', 0755, 'Unable to install Gemini CLI command stub']]],
            ['publish', [[$root.'/pmss-ai-launch.php', 'launcher fixture', 0644, 'Unable to install Claude Code launcher'],
                ['/usr/local/bin/claude', 'claude fixture', 0755, 'Unable to install Claude Code command stub']]],
        ], $this->events('publish'));
        $this->assertSame([['read', $source],
            ['read', $root.'/npm/claude/lib/node_modules/@anthropic-ai/claude-code/package.json'],
            ['read', $source]], $this->events('read'));
    }

    public function testNpmFailureDryRunAndUnchangedLauncherGates(): void
    {
        foreach (['install-failure', 'directory-failure', 'dry-run', 'unchanged', 'stale-version'] as $case) {
            $this->resetFixture(['package' => ['version' => $case === 'stale-version' ? 'old' : '2.1.112'],
                'rc' => ['Installing Gemini CLI' => $case === 'install-failure' ? 1 : 0,
                    'Ensuring Gemini defaults directory is root-owned' => $case === 'directory-failure' ? 1 : 0],
                'launcherNeeded' => $case !== 'unchanged']);
            AiToolsStagesFixture\pmssAiToolsNpmInstall('/fixture/bin/node', $case === 'install-failure', $case === 'dry-run', $this->logger());
            $this->assertSame(in_array($case, ['dry-run', 'unchanged'], true) ? 0
                : (in_array($case, ['install-failure', 'directory-failure'], true) ? 1 : 2), count($this->events('publish')), $case);
            if ($case === 'stale-version') {
                $steps = $this->events('step');
                $this->assertSame('Installing Claude Code', $steps[2][1]);
                $this->assertSame('["\/fixture\/bin\/npm",["install","--prefix","\/opt\/pmss\/ai-tools\/npm\/claude","-g","--no-audit","--no-fund","@anthropic-ai\/claude-code@2.1.112"]]', $steps[2][2]);
            }
        }
    }

    public function testCodexPrerequisitesRefuseBeforeDownloadOrPublication(): void
    {
        foreach (['unchanged', 'unsupported', 'unverified', 'allocation-failure'] as $case) {
            $this->resetFixture(['launcherNeeded' => $case !== 'unchanged',
                'tag' => $case === 'unverified' ? 'unverified' : 'rust-v0.93.0', 'downloadDir' => null]);
            AiToolsStagesFixture\pmssAiToolsCodexInstall($case === 'allocation-failure', false, $case !== 'unsupported', $this->logger());
            $this->assertSame([], $this->events('download'), $case);
            $this->assertSame([], $this->events('publish'), $case);
            $this->assertSame([], $this->events('fallback'), $case);
            $message = $case === 'unsupported' ? 'no pinned binary for this CPU architecture'
                : ($case === 'unverified' ? 'pinned tag lacks a live Virsilipas chat check' : null);
            $this->assertSame($message === null ? [] : [['log', '[WARN] Skipping Codex install: '.$message]], $this->events('log'));
        }
    }

    public function testCodexExistingBinaryAndKernelFallbackGates(): void
    {
        foreach ([['5.12', false, true], ['5.13', false, false], ['4.19', true, true],
            ['6.1', false, false], ['invalid', false, false]] as [$kernel, $dryRun, $fallback]) {
            $this->resetFixture(['kernel' => $kernel]);
            AiToolsStagesFixture\pmssAiToolsCodexInstall(false, $dryRun, true, $this->logger());
            $this->assertSame([], $this->events('download'));
            $this->assertSame($dryRun ? 0 : 1, count($this->events('publish')));
            $this->assertSame($fallback ? [['fallback', $dryRun]] : [], $this->events('fallback'));
        }
    }

    public function testCodexDownloadFailuresAndSuccessAlwaysCleanBeforePublication(): void
    {
        $root = '/opt/pmss/ai-tools/codex';
        foreach (['success', 'download-failure', 'extract-failure', 'temporary-failure', 'wrong-parent', 'install-failure', 'rename-failure'] as $case) {
            $values = ['rc' => ['Extracting Codex CLI archive' => $case === 'extract-failure' ? 1 : 0,
                'Installing Codex CLI binary' => $case === 'install-failure' ? 1 : 0],
                'temporary' => $case === 'temporary-failure' ? false : ($case === 'wrong-parent' ? '/fixture/wrong' : $root.'/.pmss-fixture'),
                'rename' => $case !== 'rename-failure'];
            if ($case === 'download-failure') $values['artifactResult'] = false;
            $this->resetFixture($values);
            AiToolsStagesFixture\pmssAiToolsCodexInstall(true, false, true, $this->logger());
            $this->assertSame([['download', 'Codex CLI archive',
                'https://github.com/openai/codex/releases/download/rust-v0.93.0/codex-x86_64-unknown-linux-musl.tar.gz',
                '3574eef71b062c17904b0761c397a97709ef28e99c616e2d1db261b2ea293d07', true,
                ['tempPrefix' => 'pmss-ai-codex-', 'downloadDescription' => 'Downloading pinned Codex CLI archive']]], $this->events('download'), $case);
            $this->assertSame([['rmdir', '/fixture/download']], $this->events('rmdir'), $case);
            $this->assertSame($case === 'success' ? 1 : 0, count($this->events('publish')), $case);
            $events = $GLOBALS['pmssAiStages']['events'];
            if ($case === 'success') {
                $this->assertSame(['publish', [
                    ['/opt/pmss/ai-tools/pmss-ai-launch.php', 'launcher fixture', 0644, 'Unable to install Codex launcher'],
                    ['/usr/local/bin/codex', 'codex fixture', 0755, 'Unable to install Codex command stub'],
                ]], end($events));
            } else {
                $this->assertSame(['rmdir', '/fixture/download'], end($events));
            }
        }
    }

    public function testCodexThrowableIdentityAndNestedCleanupOrder(): void
    {
        $error = new \RuntimeException('fixture failure');
        $this->resetFixture(['throwAt' => 'Installing Codex CLI binary', 'throwable' => $error]);
        try {
            AiToolsStagesFixture\pmssAiToolsCodexInstall(true, false, true, $this->logger());
            $this->fail('Expected the original command exception');
        } catch (\RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
        $this->assertSame([['unlink', '/opt/pmss/ai-tools/codex/.pmss-fixture'],
            ['unlink', '/fixture/download/codex-x86_64-unknown-linux-musl']], $this->events('unlink'));
        $this->assertSame([['rmdir', '/fixture/download']], $this->events('rmdir'));
        $this->assertSame([], $this->events('publish'));
    }

    public function testCodexCleanupThrowablesAttemptEveryStageBeforePropagating(): void
    {
        $cleanup = [
            ['unlink', '/opt/pmss/ai-tools/codex/.pmss-fixture'],
            ['unlink', '/fixture/download/codex-x86_64-unknown-linux-musl'],
            ['rmdir', '/fixture/download'],
        ];
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach ([[0], [1], [2], [0, 1], [0, 2], [1, 2], [0, 1, 2]] as $stages) {
                foreach ([false, true] as $installFails) {
                    $faults = [];
                    foreach ($stages as $stage) $faults[$cleanup[$stage][1]] = new $type('cleanup '.$stage);
                    $this->resetFixture(['cleanupFaults' => $faults,
                        'throwAt' => $installFails ? 'Installing Codex CLI binary' : '',
                        'throwable' => new $type('install failure')]);
                    $caught = null;
                    try {
                        AiToolsStagesFixture\pmssAiToolsCodexInstall(true, false, true, $this->logger());
                    } catch (\Throwable $error) { $caught = $error; }
                    // Later finally failures retain PHP's precedence, including combined faults.
                    $this->assertSame($faults[$cleanup[max($stages)][1]], $caught);
                    $this->assertSame($cleanup, array_slice($GLOBALS['pmssAiStages']['events'], -3));
                    $this->assertSame([], $this->events('publish'));
                    $this->assertSame([], $this->events('fallback'));
                }
            }
        }
    }

    public function testCodexCleanupFalseResultsRetainPublication(): void
    {
        foreach ([['unlink'], ['rmdir'], ['unlink', 'rmdir']] as $operations) {
            $this->resetFixture(['cleanupFalse' => array_fill_keys($operations, true)]);
            AiToolsStagesFixture\pmssAiToolsCodexInstall(true, false, true, $this->logger());
            $this->assertSame([['unlink', '/opt/pmss/ai-tools/codex/.pmss-fixture'],
                ['unlink', '/fixture/download/codex-x86_64-unknown-linux-musl']], $this->events('unlink'));
            $this->assertSame([['rmdir', '/fixture/download']], $this->events('rmdir'));
            $this->assertSame(1, count($this->events('publish')));
        }
    }

    public function testCodexDryRunAcquisitionNeverAllocatesOrPublishes(): void
    {
        AiToolsStagesFixture\pmssAiToolsCodexInstall(true, true, true, $this->logger());
        $this->assertSame([], $this->events('allocate'));
        $this->assertSame([], $this->events('temporary'));
        $this->assertSame([], $this->events('unlink'));
        $this->assertSame([], $this->events('rmdir'));
        $this->assertSame([], $this->events('publish'));
        $this->assertSame(1, count($this->events('download')));
        $steps = $this->events('step');
        $this->assertSame(['Ensuring Codex binary directory exists', 'Extracting Codex CLI archive'], array_column($steps, 1));
        $this->assertSame('umask 022 && ["tar",["--no-same-owner","--no-same-permissions","-xzf","\/fixture\/archive","-C","\/tmp"]]', $steps[1][2]);
    }
}
