<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/apps/templates/pmss-ai-launch.php';
require_once __DIR__.'/../../update/apps/aiToolsCodex.php';
require_once __DIR__.'/../../update/apps/aiToolsClaude.php';

class AiToolsClaudeLauncherTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array
    {
        return ['tempDir', 'pmss-ai-claude-'];
    }

    public function testPassthroughPrecedence(): void
    {
        $env = ['HOME' => '/tmp/pmss-test-home'];
        $state = ['uid' => 1000, 'keyFileValid' => true, 'key' => 'private-test-key',
            'endpoint' => 'https://virsilipas.mcx.fi/v1', 'configChoice' => false];
        $cases = [
            'off' => [['PMSS_VIRSILIPAS' => 'off'], [], []],
            'zero' => [['PMSS_VIRSILIPAS' => '0'], [], []],
            'no' => [['PMSS_VIRSILIPAS' => 'no'], [], []],
            'root' => [[], ['uid' => 0], []],
            'empty home' => [['HOME' => ''], [], []],
            'missing key' => [[], ['keyFileValid' => false], []],
            'empty key' => [[], ['key' => ''], []],
            'space key' => [[], ['key' => 'bad key'], []],
            'control key' => [[], ['key' => "bad\nkey"], []],
            'unicode space' => [[], ['key' => "bad\xC2\xA0key"], []],
            'invalid key encoding' => [[], ['key' => "bad\xFFkey"], []],
            'http endpoint' => [[], ['endpoint' => 'http://example.com/v1'], []],
            'endpoint query' => [[], ['endpoint' => 'https://example.com/v1?x=1'], []],
            'config choice' => [[], ['configChoice' => true], []],
            'model' => [[], [], ['--model', 'opus']],
            'model joined' => [[], [], ['--model=opus']],
            'fallback model' => [[], [], ['--fallback-model', 'sonnet']],
            'fallback joined' => [[], [], ['--fallback-model=sonnet']],
            'settings' => [[], [], ['--settings', '/tmp/own.json']],
            'settings joined' => [[], [], ['--settings=/tmp/own.json']],
            'setup token' => [[], [], ['setup-token']],
        ];
        foreach (['ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'ANTHROPIC_BASE_URL',
            'CLAUDE_CODE_USE_BEDROCK', 'CLAUDE_CODE_USE_VERTEX', 'CLAUDE_CODE_OAUTH_TOKEN'] as $name) {
            $cases[$name] = [[$name => 'own'], [], []];
            $cases[$name.' zero'] = [[$name => '0'], [], []];
        }
        foreach ($cases as $label => [$envChange, $stateChange, $args]) {
            $input = array_merge($env, $envChange);
            $decision = \pmssAiClaudeDecision($args, $input, array_merge($state, $stateChange));
            $this->assertSame(array_merge([\PMSS_AI_CLAUDE_BINARY], $args), $decision['argv'], $label);
            $this->assertSame($input, $decision['env'], $label.' environment');
            $this->assertFalse($decision['notice'], $label.' notice');
        }
        $this->assertFalse(\pmssAiClaudeDecision([], [], $state)['notice'], 'unset HOME');
    }

    public function testInjectedEnvironmentDoesNotPutKeyInArguments(): void
    {
        $env = ['HOME' => '/tmp/pmss-test-home', 'ANTHROPIC_MODEL' => 'own',
            'ANTHROPIC_DEFAULT_HAIKU_MODEL' => ''];
        $state = ['uid' => 1000, 'keyFileValid' => true, 'key' => 'private-test-key',
            'endpoint' => 'https://virsilipas.mcx.fi/v1/', 'configChoice' => false];
        $decision = \pmssAiClaudeDecision(['-p', 'hello'], $env, $state);
        $this->assertSame([\PMSS_AI_CLAUDE_BINARY, '-p', 'hello'], $decision['argv']);
        $this->assertStringNotContainsString('private-test-key', implode(' ', $decision['argv']));
        $this->assertSame('https://virsilipas.mcx.fi', $decision['env']['ANTHROPIC_BASE_URL']);
        $this->assertSame('private-test-key', $decision['env']['ANTHROPIC_AUTH_TOKEN']);
        $this->assertSame('own', $decision['env']['ANTHROPIC_MODEL']);
        $this->assertSame('', $decision['env']['ANTHROPIC_DEFAULT_HAIKU_MODEL']);
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
            file_put_contents($bin.'/node', "#!/bin/sh\n");
            chmod($bin.'/node', 0755);
        }
        $out = \pmssAiNodeRuntimePath($env, $this->tempDir);
        $this->assertSame($this->tempDir.'/node-v22.23.4-linux-x64/bin:/usr/bin:/bin', $out['PATH']);
        $this->assertSame('/tmp/pmss-test-home', $out['HOME']);
        $bare = \pmssAiNodeRuntimePath(['HOME' => '/tmp/pmss-test-home'], $this->tempDir);
        $this->assertSame($this->tempDir.'/node-v22.23.4-linux-x64/bin', $bare['PATH']);
    }
}
