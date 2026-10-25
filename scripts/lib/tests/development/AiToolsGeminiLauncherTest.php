<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/AiToolsLauncherTestCase.php';
require_once __DIR__.'/../../update/apps/aiToolsGemini.php';

class AiToolsGeminiLauncherTest extends AiToolsLauncherTestCase
{
    protected const TOOL = 'gemini';

    public function testPassthroughPrecedence(): void
    {
        $env = ['HOME' => '/tmp/pmss-test-home'];
        $state = $this->launcherState();
        $cases = array_merge($this->launcherPassthroughCases(), [
            'short model' => [[], [], ['-m', 'own']],
            'long model' => [[], [], ['--model', 'own']],
            'joined model' => [[], [], ['--model=own']],
        ]);
        $cases += $this->launcherEnvironmentCases(['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GOOGLE_GEMINI_BASE_URL',
            'GOOGLE_GENAI_USE_VERTEXAI', 'GOOGLE_GENAI_USE_GCA', 'GOOGLE_CLOUD_PROJECT',
            'GEMINI_CLI_AUTH_OVERRIDE', 'GEMINI_DEFAULT_AUTH_TYPE',
            'GEMINI_CLI_SYSTEM_DEFAULTS_PATH', 'GEMINI_CLI_SYSTEM_SETTINGS_PATH']);
        $this->assertLauncherPassthroughCases($cases, $state, $env);
    }

    public function testInjectedEnvironmentAndArguments(): void
    {
        $state = $this->launcherState(['endpoint' => 'https://virsilipas.mcx.fi/v1/']);
        $decision = \pmssAiGeminiDecision(['-p', 'hello'], ['HOME' => '/tmp/test-home'], $state);
        $this->assertSame([\PMSS_AI_GEMINI_BINARY, '-p', 'hello'], $decision['argv']);
        $this->assertStringNotContainsString('private-test-key', implode(' ', $decision['argv']));
        $this->assertSame('private-test-key', $decision['env']['GEMINI_API_KEY']);
        $this->assertSame('https://virsilipas.mcx.fi', $decision['env']['GOOGLE_GEMINI_BASE_URL']);
        $this->assertSame(\PMSS_AI_GEMINI_DEFAULTS, $decision['env']['GEMINI_CLI_SYSTEM_DEFAULTS_PATH']);
        $this->assertTrue($decision['notice']);
        $state['endpoint'] = 'https://virsilipas.mcx.fi/v1/v1///';
        $this->assertSame('https://virsilipas.mcx.fi/v1',
            \pmssAiGeminiDecision([], ['HOME' => '/tmp/test-home'], $state)['env']['GOOGLE_GEMINI_BASE_URL']);
    }

    public function testAccountStateAndEnvFiles(): void
    {
        $home = $this->tempDir;
        $configName = '.'.'gemini';
        $config = $home.'/'.$configName;
        $key = $home.'/.virsilipasKey';
        $env = ['HOME' => $home];
        $this->assertFalse(\pmssAiGeminiState($env)['keyFileValid']);
        symlink($home.'/missing', $key);
        $this->assertFalse(\pmssAiGeminiState($env)['keyFileValid']);
        unlink($key);
        file_put_contents($key, "endpoint=https://virsilipas.mcx.fi/v1\nkey=private-test-key\n");
        $this->assertTrue(\pmssAiGeminiState($env)['keyFileValid']);
        mkdir($config);
        foreach (['oauth_creds.json', 'google_accounts.json'] as $name) {
            symlink($home.'/missing', $config.'/'.$name);
            $this->assertTrue(\pmssAiGeminiState($env)['configChoice']);
            unlink($config.'/'.$name);
        }
        $settings = $config.'/settings.json';
        foreach (['{"security":{"auth":{"selectedType":"gemini-api-key"}}}',
            '{"security":{"auth":{"selectedType":null}}}', '{bad'] as $json) {
            file_put_contents($settings, $json);
            $this->assertTrue(\pmssAiGeminiState($env)['configChoice']);
        }
        file_put_contents($settings, '{}');
        $this->assertFalse(\pmssAiGeminiState($env)['configChoice']);
        unlink($settings);
        $custom = $home.'/custom';
        mkdir($custom);
        mkdir($custom.'/'.$configName);
        file_put_contents($custom.'/'.$configName.'/settings.json', '{bad');
        $env['GEMINI_CLI_HOME'] = $custom;
        $this->assertTrue(\pmssAiGeminiState($env)['configChoice']);
        $this->assertFalse(file_exists($config.'/settings.json'));
    }

    public function testEnvSearchPrecedence(): void
    {
        $home = $this->tempDir;
        $configName = '.'.'gemini';
        $workspace = $home.'/workspace';
        mkdir($workspace);
        mkdir($home.'/'.$configName);
        mkdir($workspace.'/'.$configName);
        file_put_contents($home.'/.env', 'GEMINI_API_KEY=home');
        $this->assertTrue(\pmssAiGeminiEnvChoice($workspace, $home));
        $state = $this->launcherState(['configChoice' => \pmssAiGeminiEnvChoice($workspace, $home)]);
        $this->assertFalse(\pmssAiGeminiDecision([], ['HOME' => $home], $state)['notice']);
        unlink($home.'/.env');
        file_put_contents($workspace.'/.env', 'GOOGLE_CLOUD_PROJECT=project');
        $this->assertTrue(\pmssAiGeminiEnvChoice($workspace, $home));
        file_put_contents($workspace.'/'.$configName.'/.env', 'GOOGLE_API_KEY=own');
        $this->assertSame($workspace.'/'.$configName.'/.env', \pmssAiGeminiEnvFile($workspace, $home, true, false));
        $this->assertSame($workspace.'/.env', \pmssAiGeminiEnvFile($workspace, $home, false, false));
        $this->assertTrue(\pmssAiGeminiEnvChoice($workspace, $home));
        unlink($workspace.'/.env');
        unlink($workspace.'/'.$configName.'/.env');
        file_put_contents($home.'/'.$configName.'/.env', 'GOOGLE_GENAI_USE_VERTEXAI=true');
        $this->assertTrue(\pmssAiGeminiEnvChoice($workspace, $home));
        unlink($home.'/'.$configName.'/.env');
        file_put_contents($workspace.'/.env', 'UNRELATED=1');
        $this->assertFalse(\pmssAiGeminiEnvChoice($workspace, $home));
    }

    public function testOldSymlinkLayoutConverges(): void
    {
        chmod($this->tempDir, 0755);
        $source = __DIR__.'/../../update/apps/templates/pmss-ai-launch.php';
        $launcher = $this->tempDir.'/pmss-ai-launch.php';
        $stub = $this->tempDir.'/gemini';
        $defaults = $this->tempDir.'/gemini-virsilipas-defaults.json';
        symlink(\PMSS_AI_GEMINI_BINARY, $stub);
        $this->assertTrue(\pmssAiToolsGeminiInstallNeeded($launcher, $stub, $source, $defaults));
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $this->assertTrue(\pmssAiToolsCodexAtomicWrite($launcher, file_get_contents($source), 0644, $uid, $gid));
        $this->assertTrue(\pmssAiToolsCodexAtomicWrite($defaults, \pmssAiToolsGeminiDefaults(), 0644, $uid, $gid));
        $this->assertTrue(\pmssAiToolsCodexAtomicWrite($stub, \pmssAiToolsGeminiStub(), 0755, $uid, $gid));
        $this->assertFalse(is_link($stub));
        $this->assertSame(0755, fileperms($stub) & 0777);
        $this->assertSame(0644, fileperms($launcher) & 0777);
        $this->assertSame(0644, fileperms($defaults) & 0777);
        $this->assertSame(0755, fileperms($this->tempDir) & 0777);
        $this->assertSame($uid, fileowner($stub));
        $this->assertSame($uid, fileowner($this->tempDir));
        $this->assertSame($uid, fileowner($defaults));
        $this->assertSame(json_decode(\pmssAiToolsGeminiDefaults(), true), json_decode(file_get_contents($defaults), true));
        $this->assertSame('gemini-api-key', json_decode(file_get_contents($defaults), true)['security']['auth']['selectedType']);
        $this->assertSame('default', json_decode(file_get_contents($defaults), true)['model']['name']);
        $this->assertStringContainsString('exec /usr/bin/php /opt/pmss/ai-tools/pmss-ai-launch.php gemini "$@"', file_get_contents($stub));
        $this->assertStringContainsString('exec '.\PMSS_AI_GEMINI_BINARY.' "$@"', file_get_contents($stub));
        $this->assertFalse(\pmssAiToolsGeminiInstallNeeded($launcher, $stub, $source, $defaults, $uid, $gid));
        chmod($stub, 0700);
        $this->assertTrue(\pmssAiToolsGeminiInstallNeeded($launcher, $stub, $source, $defaults, $uid, $gid));
        chmod($stub, 0755);
        file_put_contents($defaults, '{}');
        $this->assertTrue(\pmssAiToolsGeminiInstallNeeded($launcher, $stub, $source, $defaults, $uid, $gid));
    }
}
