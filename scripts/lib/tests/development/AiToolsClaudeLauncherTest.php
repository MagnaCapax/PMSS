<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/AiToolsLauncherTestCase.php';
require_once __DIR__.'/../../update/apps/aiToolsClaude.php';

class AiToolsClaudeLauncherTest extends AiToolsLauncherTestCase
{
    protected const TOOL = 'claude';

    public function testPassthroughPrecedence(): void
    {
        $env = ['HOME' => '/tmp/pmss-test-home'];
        $state = $this->launcherState();
        $cases = array_merge($this->launcherPassthroughCases(), [
            'model' => [[], [], ['--model', 'opus']],
            'model joined' => [[], [], ['--model=opus']],
            'fallback model' => [[], [], ['--fallback-model', 'sonnet']],
            'fallback joined' => [[], [], ['--fallback-model=sonnet']],
            'settings' => [[], [], ['--settings', '/tmp/own.json']],
            'settings joined' => [[], [], ['--settings=/tmp/own.json']],
            'setup token' => [[], [], ['setup-token']],
        ]);
        $cases += $this->launcherEnvironmentCases(['ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'ANTHROPIC_BASE_URL',
            'CLAUDE_CODE_USE_BEDROCK', 'CLAUDE_CODE_USE_VERTEX', 'CLAUDE_CODE_OAUTH_TOKEN']);
        $this->assertLauncherPassthroughCases($cases, $state, $env);
    }

    public function testInjectedEnvironmentDoesNotPutKeyInArguments(): void
    {
        $env = ['HOME' => '/tmp/pmss-test-home', 'ANTHROPIC_MODEL' => 'own',
            'ANTHROPIC_DEFAULT_HAIKU_MODEL' => ''];
        $state = $this->launcherState(['endpoint' => 'https://virsilipas.mcx.fi/v1/']);
        $decision = \pmssAiClaudeDecision(['-p', 'hello'], $env, $state);
        $this->assertSame([\PMSS_AI_CLAUDE_BINARY, '-p', 'hello'], $decision['argv']);
        $this->assertStringNotContainsString('private-test-key', implode(' ', $decision['argv']));
        $this->pmssAssertArraySubsetSame([
            'ANTHROPIC_BASE_URL' => 'https://virsilipas.mcx.fi', 'ANTHROPIC_AUTH_TOKEN' => 'private-test-key',
            'ANTHROPIC_MODEL' => 'own', 'ANTHROPIC_DEFAULT_HAIKU_MODEL' => '',
        ], $decision['env']);
        foreach (['ANTHROPIC_DEFAULT_OPUS_MODEL', 'ANTHROPIC_DEFAULT_SONNET_MODEL',
            'ANTHROPIC_SMALL_FAST_MODEL'] as $name) {
            $this->assertSame('default', $decision['env'][$name]);
        }
        $this->assertTrue($decision['notice']);
        $state['endpoint'] = 'https://virsilipas.mcx.fi/v1/v1///';
        $this->assertSame('https://virsilipas.mcx.fi/v1',
            \pmssAiClaudeDecision([], ['HOME' => '/tmp/pmss-test-home'], $state)['env']['ANTHROPIC_BASE_URL']);
    }

    public function testAccountStateFilesAndUnreadableDataDefer(): void
    {
        $home = $this->tempDir;
        $key = $home.'/.virsilipasKey';
        $env = ['HOME' => $home];
        $this->assertFalse(\pmssAiClaudeState($env)['keyFileValid']);
        symlink($home.'/missing', $key);
        $this->assertFalse(\pmssAiClaudeState($env)['keyFileValid']);
        unlink($key);
        mkdir($key);
        $this->assertFalse(\pmssAiClaudeState($env)['keyFileValid']);
        rmdir($key);
        file_put_contents($key, "endpoint=https://virsilipas.mcx.fi/v1\nkey=private-test-key\n");
        $this->assertTrue(\pmssAiClaudeState($env)['keyFileValid']);
        $configDir = $home.'/.claude';
        mkdir($configDir);
        $credentials = $configDir.'/.credentials.json';
        symlink($home.'/missing', $credentials);
        $this->assertTrue(\pmssAiClaudeState($env)['configChoice']);
        unlink($credentials);
        $state = $home.'/.claude.json';
        foreach (['{"oauthAccount":null}', '{"primaryApiKey":null}', '{bad'] as $json) {
            file_put_contents($state, $json);
            $this->assertTrue(\pmssAiClaudeState($env)['configChoice']);
        }
        file_put_contents($state, '{}');
        $this->assertFalse(\pmssAiClaudeState($env)['configChoice']);
        $settings = $configDir.'/settings.json';
        foreach (['{"apiKeyHelper":null}', '{"env":{"ANTHROPIC_MODEL":"own"}}', '{bad'] as $json) {
            file_put_contents($settings, $json);
            $this->assertTrue(\pmssAiClaudeState($env)['configChoice']);
        }
        unlink($settings);
        mkdir($settings);
        $this->assertTrue(\pmssAiClaudeState($env)['configChoice']);
        rmdir($settings);
        $custom = $home.'/custom';
        mkdir($custom);
        $env['CLAUDE_CONFIG_DIR'] = $custom;
        file_put_contents($custom.'/.claude.json', '{"oauthAccount":{}}');
        $this->assertTrue(\pmssAiClaudeState($env)['configChoice']);
        $this->assertFalse(file_exists($custom.'/settings.json'));
        $source = file_get_contents(__DIR__.'/../../update/apps/templates/pmss-ai-launch.php');
        $this->assertStringContainsString('@fileowner($file) !== $uid', $source);
    }

    public function testOldSymlinkLayoutConverges(): void
    {
        $source = __DIR__.'/../../update/apps/templates/pmss-ai-launch.php';
        $launcher = $this->tempDir.'/pmss-ai-launch.php';
        $stub = $this->tempDir.'/claude';
        symlink('/opt/pmss/ai-tools/npm/claude/bin/claude', $stub);
        $this->assertTrue(\pmssAiToolsClaudeInstallNeeded($launcher, $stub, $source));
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $this->assertTrue(\pmssAiToolsCodexAtomicWrite($launcher, file_get_contents($source), 0644, $uid, $gid));
        $this->assertTrue(\pmssAiToolsCodexAtomicWrite($stub, \pmssAiToolsClaudeStub(), 0755, $uid, $gid));
        $this->assertFalse(is_link($stub));
        $this->assertSame(0755, fileperms($stub) & 0777);
        $this->assertSame(0644, fileperms($launcher) & 0777);
        $this->assertSame($uid, fileowner($stub));
        $this->assertStringContainsString('exec /usr/bin/php /opt/pmss/ai-tools/pmss-ai-launch.php claude "$@"', file_get_contents($stub));
        $this->assertStringContainsString('exec /opt/pmss/ai-tools/npm/claude/bin/claude "$@"', file_get_contents($stub));
        $this->assertFalse(\pmssAiToolsClaudeInstallNeeded($launcher, $stub, $source, $uid, $gid));
        chmod($stub, 0700);
        $this->assertTrue(\pmssAiToolsClaudeInstallNeeded($launcher, $stub, $source, $uid, $gid));
        chmod($stub, 0755);
        file_put_contents($stub, 'outdated');
        $this->assertTrue(\pmssAiToolsClaudeInstallNeeded($launcher, $stub, $source));
    }

    public function testNpmCliRunsOnInstalledNodeRuntime(): void
    {
        $env = ['HOME' => '/tmp/pmss-test-home', 'PATH' => '/usr/bin:/bin'];
        $this->assertSame($env, \pmssAiNodeRuntimePath($env, $this->tempDir));
        foreach (['22.9.0', '22.23.4'] as $version) {
            $bin = $this->tempDir.'/node-v'.$version.'-linux-x64/bin';
            mkdir($bin, 0755, true);
            $this->pmssWriteExecutableFile($bin.'/node', "#!/bin/sh\n");
        }
        $out = \pmssAiNodeRuntimePath($env, $this->tempDir);
        $this->pmssAssertArraySubsetSame([
            'PATH' => $this->tempDir.'/node-v22.23.4-linux-x64/bin:/usr/bin:/bin', 'HOME' => '/tmp/pmss-test-home',
        ], $out);
        $bare = \pmssAiNodeRuntimePath(['HOME' => '/tmp/pmss-test-home'], $this->tempDir);
        $this->assertSame($this->tempDir.'/node-v22.23.4-linux-x64/bin', $bare['PATH']);
    }
}
