<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/apps/templates/pmss-ai-launch.php';
require_once __DIR__.'/../../update/apps/aiToolsCodex.php';

class AiToolsCodexLauncherTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array
    {
        return ['tempDir', 'pmss-ai-codex-'];
    }

    public function testPrecedenceTable(): void
    {
        $env = ['HOME' => '/tmp/pmss-test-home'];
        $state = ['uid' => 1000, 'keyFileValid' => true, 'key' => 'private-test-key',
            'endpoint' => 'https://virsilipas.mcx.fi/v1', 'authExists' => false, 'configChoice' => false];
        $cases = [
            'opt off' => [['PMSS_VIRSILIPAS' => 'off'], [], []],
            'opt zero' => [['PMSS_VIRSILIPAS' => '0'], [], []],
            'opt no' => [['PMSS_VIRSILIPAS' => 'no'], [], []],
            'root' => [[], ['uid' => 0], []],
            'home absent' => [['HOME' => ''], [], []],
            'key missing' => [[], ['keyFileValid' => false], []],
            'key symlink' => [[], ['keyFileValid' => false], []],
            'key nonregular' => [[], ['keyFileValid' => false], []],
            'key other owner' => [[], ['keyFileValid' => false], []],
            'key empty' => [[], ['key' => ''], []],
            'key whitespace' => [[], ['key' => 'with space'], []],
            'key Unicode whitespace' => [[], ['key' => "bad\xC2\xA0key"], []],
            'key control' => [[], ['key' => "bad\nkey"], []],
            'key invalid encoding' => [[], ['key' => "bad\xFFkey"], []],
            'endpoint http' => [[], ['endpoint' => 'http://virsilipas.mcx.fi/v1'], []],
            'endpoint query' => [[], ['endpoint' => 'https://virsilipas.mcx.fi/v1?key=x'], []],
            'endpoint quote' => [[], ['endpoint' => 'https://bad".example/v1'], []],
            'auth' => [[], ['authExists' => true], []],
            'own key' => [['OPENAI_API_KEY' => 'own'], [], []],
            'own key zero' => [['OPENAI_API_KEY' => '0'], [], []],
            'own base' => [['OPENAI_BASE_URL' => 'https://own.example'], [], []],
            'config choice' => [[], ['configChoice' => true], []],
            'cli provider' => [[], [], ['-c', 'model_provider="own"']],
            'cli provider table' => [[], [], ['--config', 'model_providers.own={}']],
            'cli model' => [[], [], ['--config=model = "own"']],
            'cli profile' => [[], [], ['-p', 'own']],
            'cli long profile' => [[], [], ['--profile=own']],
            'cli oss' => [[], [], ['--oss']],
            'cli model short' => [[], [], ['-m', 'own']],
            'cli model long' => [[], [], ['--model=own']],
            'login' => [[], [], ['login']],
            'logout' => [[], [], ['logout']],
            'completion' => [[], [], ['completion']],
            'help' => [[], [], ['help']],
        ];
        foreach ($cases as $label => [$envChange, $stateChange, $args]) {
            $inputEnv = array_merge($env, $envChange);
            $result = \pmssAiCodexDecision($args, $inputEnv, array_merge($state, $stateChange));
            $this->assertSame(array_merge([\PMSS_AI_CODEX_BINARY], $args), $result['argv'], $label);
            $this->assertSame($inputEnv, $result['env'], $label.' env');
            $this->assertFalse($result['notice'], $label.' notice');
        }
        $this->assertFalse(\pmssAiCodexDecision([], [], $state)['notice'], 'unset HOME');
        $result = \pmssAiCodexDecision(['exec', 'hello'], $env, $state);
        $this->assertTrue($result['notice']);
        $this->assertSame('private-test-key', $result['env']['VIRSILIPAS_API_KEY']);
        $this->assertStringNotContainsString('private-test-key', implode(' ', $result['argv']));
        $this->assertStringContainsString('wire_api="chat"', implode(' ', $result['argv']));
        $this->assertSame(['exec', 'hello'], array_slice($result['argv'], -2));
    }

    public function testConfigOnlyTopLevelChoicesWin(): void
    {
        $this->assertFalse(\pmssAiCodexHasTopLevelChoice("# model=other\n[profiles.x]\nmodel=other\n"));
        $this->assertTrue(\pmssAiCodexHasTopLevelChoice("# comment\n model_provider = 'own'\n[profiles.x]\n"));
        $this->assertTrue(\pmssAiCodexHasTopLevelChoice("profile='own'\n"));
        $this->assertTrue(\pmssAiCodexHasTopLevelChoice("model = 'own'\n"));
    }

    public function testKeyFileStateChecksAndNoHomeWrite(): void
    {
        $env = ['HOME' => $this->tempDir];
        $keyFile = $this->tempDir.'/.virsilipasKey';
        $this->assertFalse(\pmssAiCodexState($env)['keyFileValid']);
        symlink($this->tempDir.'/missing', $keyFile);
        $this->assertFalse(\pmssAiCodexState($env)['keyFileValid']);
        unlink($keyFile);
        mkdir($keyFile);
        $this->assertFalse(\pmssAiCodexState($env)['keyFileValid']);
        rmdir($keyFile);
        file_put_contents($keyFile, "endpoint=https://virsilipas.mcx.fi/v1\nkey=private-test-key\n");
        chmod($keyFile, 0600);
        $this->assertTrue(\pmssAiCodexState($env)['keyFileValid']);
        mkdir($this->tempDir.'/.codex');
        file_put_contents($this->tempDir.'/.codex/auth.json', '{}');
        $this->assertTrue(\pmssAiCodexState($env)['authExists']);
        $source = file_get_contents(__DIR__.'/../../update/apps/templates/pmss-ai-launch.php');
        $this->assertStringContainsString('@fileowner($file) !== $uid', $source);
    }

    public function testOldLayoutConvergesAndStubContent(): void
    {
        $binary = $this->tempDir.'/codex-rust-v0.93.0';
        $launcher = $this->tempDir.'/pmss-ai-launch.php';
        $stub = $this->tempDir.'/codex';
        $source = __DIR__.'/../../update/apps/templates/pmss-ai-launch.php';
        file_put_contents($stub, 'old binary');
        $this->assertTrue(\pmssAiToolsCodexInstallNeeded($binary, $launcher, $stub, $source, false));
        file_put_contents($binary, 'pinned binary fixture');
        chmod($binary, 0755);
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $this->assertTrue(\pmssAiToolsCodexAtomicWrite($launcher, file_get_contents($source), 0644, $uid, $gid));
        $this->assertTrue(\pmssAiToolsCodexAtomicWrite($stub, \pmssAiToolsCodexStub(), 0755, $uid, $gid));
        $expectedStub = "#!/bin/sh\nif [ -x /usr/bin/php ] && [ -r /opt/pmss/ai-tools/pmss-ai-launch.php ]; then\n"
            ."    exec /usr/bin/php /opt/pmss/ai-tools/pmss-ai-launch.php codex \"\$@\"\n"
            ."fi\nexec /opt/pmss/ai-tools/codex/codex-".\PMSS_AI_CODEX_TAG." \"\$@\"\n";
        $this->assertSame($expectedStub, file_get_contents($stub));
        $this->assertSame(0644, fileperms($launcher) & 0777);
        $this->assertSame(0755, fileperms($stub) & 0777);
        $this->assertSame(0755, fileperms($binary) & 0777);
        $this->assertSame($uid, fileowner($launcher));
        $this->assertSame($uid, fileowner($binary));
        $this->assertSame($gid, filegroup($stub));
        $installer = file_get_contents(__DIR__.'/../../update/apps/aiToolsInstall.php');
        $this->assertStringContainsString("['-o', 'root', '-g', 'root', '-m', '0755'", $installer);
        $this->assertFalse(\pmssAiToolsCodexInstallNeeded($binary, $launcher, $stub, $source, false));
        $this->assertTrue(\pmssAiToolsCodexInstallNeeded($binary, $launcher, $stub, $source, true));
        file_put_contents($launcher, 'stale');
        $this->assertTrue(\pmssAiToolsCodexInstallNeeded($binary, $launcher, $stub, $source, false));
    }

    public function testPinnedTagHasVerifiedChatSupport(): void
    {
        $this->assertTrue(in_array(\PMSS_AI_CODEX_TAG, \PMSS_AI_CODEX_CHAT_TAGS, true));
        $this->assertSame('/opt/pmss/ai-tools/codex/codex-'.\PMSS_AI_CODEX_TAG, \PMSS_AI_CODEX_BINARY);
    }

    public function testStubFallsBackToPinnedBinary(): void
    {
        $this->assertStringContainsString(
            'exec /opt/pmss/ai-tools/codex/codex-'.\PMSS_AI_CODEX_TAG.' "$@"',
            \pmssAiToolsCodexStub()
        );
    }
}
