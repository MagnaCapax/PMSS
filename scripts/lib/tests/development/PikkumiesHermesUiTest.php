<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/pikkumiesHermesUi.php';
require_once dirname(__DIR__, 2).'/nginxUserHosts.php';
require_once dirname(__DIR__, 2).'/nginxConfig/pikkumiesUi.php';
require_once dirname(__DIR__, 2).'/lighttpd/pikkumiesUi.php';

final class PikkumiesHermesUiTest extends TestCase
{
    public function testPrivateCredentialsAndRotation(): void
    {
        $home = $this->pmssMakeTempDir('pmss-pikkumies-hermes-', 0700);
        $paths = \pmssPikkumiesHermesPaths($home);
        $this->assertTrue(\pmssPikkumiesHermesWrite($paths, str_repeat('a', 64), str_repeat('b', 64)));
        $this->assertSame(0600, fileperms($paths['credentials']) & 0777);
        $this->assertSame([str_repeat('a', 64), str_repeat('b', 64)], \pmssPikkumiesHermesCredentials($paths));
        $this->assertTrue(\pmssPikkumiesHermesRotate($paths));
        $rotated = \pmssPikkumiesHermesCredentials($paths);
        $this->assertSame(str_repeat('a', 64), $rotated[0]);
        $this->assertFalse($rotated[1] === str_repeat('b', 64));
        chmod($paths['credentials'], 0644);
        $this->assertSame(null, \pmssPikkumiesHermesCredentials($paths));
    }

    public function testChildCommandHasCleanEnvironmentAndNoSecretArgv(): void
    {
        $home = $this->pmssMakeTempDir('pmss-pikkumies-hermes-', 0700);
        $paths = \pmssPikkumiesHermesPaths($home);
        $this->assertTrue(\pmssPikkumiesHermesWrite($paths, str_repeat('a', 64), str_repeat('b', 64)));
        $command = \pmssPikkumiesHermesCommand($paths, 'https://0123456789abcdef.mcx.fi:845', 24000);
        $this->assertStringContainsAllStrings(['/usr/bin/env -i', 'HERMES_DASHBOARD_PUBLIC_URL=',
            'run dashboard --host 127.0.0.1', $paths['credentials']], $command);
        $this->assertFalse(strpos($command, str_repeat('a', 64)) !== false);
        $this->assertFalse(strpos($command, str_repeat('b', 64)) !== false);
        $this->assertFalse(\pmssPikkumiesHermesStart($paths, 'http://0123456789abcdef.mcx.fi:845'));
    }

    public function testPortBUsesPrivateOriginAndAuthGate(): void
    {
        $live = $this->pmssMakeTempDir('pmss-pikkumies-b-cert-', 0700);
        mkdir($live.'/uitester.node.example.com');
        file_put_contents($live.'/uitester.node.example.com/fullchain.pem', 'fixture');
        file_put_contents($live.'/uitester.node.example.com/privkey.pem', 'fixture');
        $block = \pmssPikkumiesHermesNginxBlock('uitester', 'node.example.com',
            '0123456789abcdef.mcx.fi', 12345, $live);
        $this->assertStringContainsAllStrings(['listen 845 ssl;', 'server_name 0123456789abcdef.mcx.fi;',
            'auth_request /__pmss-b-auth;', 'location = /api/cron/fire { return 404; }',
            'same-origin|none', 'frame-ancestors https://node.example.com',
            'pmss_ui_b_noargs', 'proxy_set_header X-Forwarded-Proto https;'], $block);
        $this->assertFalse(strpos($block, '$args') !== false);
        $this->assertFalse(strpos($block, 'server_name uitester') !== false);
        $this->assertStringContainsString('listen 845 ssl default_server;',
            \pmssPikkumiesHermesDefaultNginxBlock("    ssl_certificate /safe/fullchain.pem;\n"));
        $lighttpd = \pmssLighttpdPikkumiesHermesBlock('uitester', 24000);
        $this->assertStringContainsAllStrings(['^/__pmss-ui-b/', '"host" => "127.0.0.1"',
            '"Authorization" => ""', 'X-Forwarded-Proto'], $lighttpd);
        $this->assertSame('', \pmssLighttpdPikkumiesHermesBlock('../uitester', 24000));
    }

    public function testAccountLifecycleStopsUiAndRotatesBothSessions(): void
    {
        $logout = $this->pmssReadRepoFile('etc/skel/www/panelSessionPage.php');
        $password = $this->pmssReadRepoFile('scripts/changePw.php');
        $this->assertStringContainsAllStrings([' ui stop', ' stop-simplified',
            'pikkumiesHermesUi.php', ' rotate-session'], $logout);
        $this->assertStringContainsAllStrings(['pmssBuildUserShellNoPamCommand',
            ' stop-simplified', ' rotate-session'], $password);
    }

    public function testAccountCronRechecksBackendGateWithoutStartingIt(): void
    {
        $runtime = $this->pmssReadRepoFile('etc/skel/bin/pikkumiesHermesRuntime.php');
        $auth = $this->pmssReadRepoFile('etc/skel/bin/pikkumiesHermesAuth.php');
        $this->assertStringContainsAllStrings(['* * * * * HOME=',
            'pmssAgentCronApply', ' ensure >/dev/null'], $runtime);
        $this->assertStringContainsAllStrings(['function pmssPikkumiesHermesEnsure',
            'pmssPikkumiesHermesGate($port)', 'pmssPikkumiesHermesStopUngated($port)'], $auth);
        $this->assertFalse(strpos($auth, 'pmssPikkumiesHermesStart($paths') !== false);
    }

    public function testRootInstallerDoesNotExecuteAccountBinary(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/apps/pikkumiesAgentboard.php');
        $this->assertStringContainsString('--no-same-owner --no-same-permissions', $source);
        $this->assertFalse(strpos($source, 'exec($root.\'/agentboard') !== false);
        $this->assertFalse(strpos($source, 'passthru($root.\'/agentboard') !== false);
    }
}
