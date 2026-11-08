<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/apps/aiToolsClaude.php';
require_once __DIR__.'/../../update/apps/aiToolsGemini.php';

class AiToolsPublicationCharacterizationTest extends TestCase
{
    /** Real legacy entrypoints exercise the shared checker with each tool's policy. */
    private function fixture(string $tool): array
    {
        $root = $this->pmssMakeTempDir('pmss-ai-publication-');
        chmod($root, 0755);
        $files = ['root' => $root, 'source' => $root.'/source', 'launcher' => $root.'/launcher',
            'stub' => $root.'/command', 'binary' => $root.'/binary', 'defaults' => $root.'/defaults'];
        $stub = ['codex' => 'pmssAiToolsCodexStub', 'claude' => 'pmssAiToolsClaudeStub', 'gemini' => 'pmssAiToolsGeminiStub'][$tool];
        foreach (['source' => 'launcher fixture', 'launcher' => 'launcher fixture', 'stub' => $stub(),
            'binary' => 'binary fixture', 'defaults' => \pmssAiToolsGeminiDefaults()] as $key => $content) {
            file_put_contents($files[$key], $content);
            chmod($files[$key], in_array($key, ['stub', 'binary'], true) ? 0755 : 0644);
        }
        return $files;
    }

    private function needed(string $tool, array $f, bool $force = false): bool
    {
        if ($tool === 'codex') return \pmssAiToolsCodexInstallNeeded($f['binary'], $f['launcher'], $f['stub'], $f['source'], $force);
        if ($tool === 'claude') return \pmssAiToolsClaudeInstallNeeded($f['launcher'], $f['stub'], $f['source'], posix_geteuid(), posix_getegid());
        return \pmssAiToolsGeminiInstallNeeded($f['launcher'], $f['stub'], $f['source'], $f['defaults'], posix_geteuid(), posix_getegid());
    }

    public function testToolMetadataPoliciesRemainDifferent(): void
    {
        foreach (['codex', 'claude', 'gemini'] as $tool) {
            $f = $this->fixture($tool);
            $this->assertFalse($this->needed($tool, $f), $tool);
            foreach (['launcher' => 0600, 'stub' => 0700] as $key => $mode) {
                chmod($f[$key], $mode);
                clearstatcache(true, $f[$key]);
                $this->assertSame($tool !== 'codex', $this->needed($tool, $f), $tool.' '.$key);
                chmod($f[$key], $key === 'stub' ? 0755 : 0644);
                clearstatcache(true, $f[$key]);
            }
            $this->assertTrue(\pmssAiToolsClaudeInstallNeeded($f['launcher'], $f['stub'], $f['source'], posix_geteuid() + 1, posix_getegid()));
            $this->assertTrue(\pmssAiToolsGeminiInstallNeeded($f['launcher'], $f['stub'], $f['source'], $f['defaults'], posix_geteuid(), posix_getegid() + 1));
        }
    }

    public function testAbsentChangedAndRedirectedArtifactsRequireRefresh(): void
    {
        foreach (['codex', 'claude', 'gemini'] as $tool) {
            foreach (['source', 'launcher', 'stub'] as $key) {
                foreach (['missing', 'changed', 'link', 'directory'] as $fault) {
                    $f = $this->fixture($tool);
                    if ($fault === 'changed') file_put_contents($f[$key], 'stale');
                    else {
                        rename($f[$key], $f[$key].'.saved');
                        if ($fault === 'link') symlink($f[$key].'.saved', $f[$key]);
                        if ($fault === 'directory') mkdir($f[$key]);
                    }
                    // The source is allowed to be a symlink; installed artifacts are not.
                    $this->assertSame(!($key === 'source' && $fault === 'link'), $this->needed($tool, $f), $tool.' '.$key.' '.$fault);
                }
            }
        }
    }

    public function testCodexBinaryAndForceGates(): void
    {
        $f = $this->fixture('codex');
        $this->assertFalse($this->needed('codex', $f));
        $this->assertTrue($this->needed('codex', $f, true));
        chmod($f['binary'], 0644);
        clearstatcache(true, $f['binary']);
        $this->assertTrue($this->needed('codex', $f));
        rename($f['binary'], $f['binary'].'.saved');
        $this->assertTrue($this->needed('codex', $f));
        chmod($f['binary'].'.saved', 0755);
        symlink($f['binary'].'.saved', $f['binary']);
        $this->assertTrue($this->needed('codex', $f));
    }

    public function testGeminiDefaultsAndDirectoryGates(): void
    {
        $f = $this->fixture('gemini');
        chmod($f['root'], 0700);
        $this->assertTrue($this->needed('gemini', $f));
        chmod($f['root'], 0755);
        $this->assertFalse($this->needed('gemini', $f));
        foreach (['', '{}'] as $content) {
            file_put_contents($f['defaults'], $content);
            $this->assertTrue($this->needed('gemini', $f));
        }
        unlink($f['defaults']);
        $this->assertTrue($this->needed('gemini', $f));
        $f['defaults'] = $f['root'].'/missing/defaults';
        $this->assertTrue($this->needed('gemini', $f));
    }

    public function testCoincidentPathsKeepLegacyOverwriteSemantics(): void
    {
        $f = $this->fixture('gemini');
        $f['launcher'] = $f['stub'] = $f['defaults'];
        $f['source'] .= '.missing';
        $this->assertFalse($this->needed('gemini', $f));
        $f = $this->fixture('codex');
        $f['stub'] = $f['launcher'];
        $this->assertTrue($this->needed('codex', $f));
        file_put_contents($f['source'], \pmssAiToolsCodexStub());
        file_put_contents($f['launcher'], \pmssAiToolsCodexStub());
        $this->assertFalse($this->needed('codex', $f));
    }

}
