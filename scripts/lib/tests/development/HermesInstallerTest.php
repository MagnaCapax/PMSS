<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/pmssHermesCli.php';

/** Installer decisions are tested without network or account writes. */
final class HermesInstallerTest extends TestCase
{
    public function testPinnedReleaseAndHashesMustAllMatch(): void
    {
        $this->assertSame('v2026.9.24', \HERMES_TAG);
        $this->assertTrue(\pmssHermesPinMatches(\HERMES_COMMIT,
            \HERMES_INSTALL_SH_SHA256, \HERMES_LOCK_JSON_SHA256));
        $this->assertFalse(\pmssHermesPinMatches(str_repeat('0', 40), \HERMES_INSTALL_SH_SHA256, \HERMES_LOCK_JSON_SHA256));
        $this->assertFalse(\pmssHermesPinMatches(\HERMES_COMMIT, str_repeat('0', 64), \HERMES_LOCK_JSON_SHA256));
        $this->assertFalse(\pmssHermesPinMatches(\HERMES_COMMIT, \HERMES_INSTALL_SH_SHA256, str_repeat('0', 64)));
    }

    public function testInstallFailureToleranceIsOnlyForProductsPathMessage(): void
    {
        $this->assertTrue(\pmssHermesInstallStageAccepted('products', 0, '', false));
        $this->assertTrue(\pmssHermesInstallStageAccepted('products', 1, 'cannot update PATH in profile', true));
        $this->assertFalse(\pmssHermesInstallStageAccepted('products', 1, 'cannot update PATH in profile', false));
        $this->assertFalse(\pmssHermesInstallStageAccepted('path', 1, 'cannot update PATH in profile', true));
        $this->assertFalse(\pmssHermesInstallStageAccepted('products', 1, 'dependency failed', true));
    }

    public function testProviderEndpointAndEnvironmentWiring(): void
    {
        $endpoint = 'https://models.example.test/v1';
        $this->assertSame('HERMES_CUSTOM_MODELS_EXAMPLE_TEST_API_KEY', \pmssHermesCustomKeyEnv($endpoint));
        $this->assertSame('HERMES_CUSTOM_MODELS_EXAMPLE_TEST_8443_API_KEY',
            \pmssHermesCustomKeyEnv('https://models.example.test:8443/v1'));
        $this->assertSame(array('key' => 'x', 'endpoint' => $endpoint),
            \pmssHermesProviderSeed(array('key' => 'x', 'endpoint' => $endpoint), false));
        $this->assertSame(null, \pmssHermesProviderSeed(array('key' => 'x', 'endpoint' => $endpoint), true));
        $this->assertSame(null, \pmssHermesProviderSeed(null, false));
        $source = $this->pmssReadRepoFile('etc/skel/bin/pmssHermesInstall.php');
        $this->assertStringContainsString("'model.base_url', \$provider['endpoint']", $source);
        $this->assertStringContainsString("'model.api_key', '\${'.\$keyEnv.'}'", $source);
        $this->assertFalse(strpos($source, "rtrim(\$provider['endpoint'], '/').'/v1'") !== false);
    }

    public function testMarkedKeyOnlyIsReconciled(): void
    {
        $marked = "HERMES_CUSTOM_MODELS_EXAMPLE_TEST_API_KEY=old # pmss-hermes-virsilipas\n";
        $this->assertSame("HERMES_CUSTOM_MODELS_EXAMPLE_TEST_API_KEY=new # pmss-hermes-virsilipas\n",
            \pmssHermesKeyReconcile($marked, 'new'));
        $this->assertSame("HERMES_CUSTOM_MODELS_EXAMPLE_TEST_API_KEY=old\n",
            \pmssHermesKeyReconcile("HERMES_CUSTOM_MODELS_EXAMPLE_TEST_API_KEY=old\n", 'new'));
        $this->assertSame("HERMES_CUSTOM_MODELS_EXAMPLE_TEST_API_KEY=\$new # pmss-hermes-virsilipas\n",
            \pmssHermesKeyReconcile($marked, '$new'));
    }

    public function testWrapperUsesCloseOnExecLockAndNoCronSupervisor(): void
    {
        $wrapper = $this->pmssReadRepoFile('etc/skel/bin/install-hermes');
        $cli = $this->pmssReadRepoFile('etc/skel/bin/pmssHermesCli.php');
        $this->assertStringContainsString('flock -x -w 10 -o', $wrapper);
        $this->assertStringContainsString('/.hermes/pmss.lock', $wrapper);
        $this->assertFalse(strpos($cli, "'@reboot php") !== false);
        $this->assertFalse(strpos($cli, "'* * * * * php") !== false);
    }

    public function testServiceCredentialsStayOutOfProcessCommandLine(): void
    {
        $home = $this->pmssMakeTempDir('pmss-hermes-env-');
        $paths = \pmssHermesPaths($home);
        $this->pmssWriteFile($paths['env'],
            'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD='.str_repeat('a', 64)."\n"
            .'HERMES_DASHBOARD_BASIC_AUTH_SECRET='.str_repeat('b', 64)."\n");
        chmod($paths['env'], 0600);
        $env = \pmssHermesEnv($paths, 'https://0123456789abcdef.mcx.fi:846/');
        $this->assertSame($paths['base'].'/locks', $env['HERMES_GATEWAY_LOCK_DIR']);
        $this->assertSame('https://0123456789abcdef.mcx.fi:846/', $env['HERMES_DASHBOARD_PUBLIC_URL']);
        $source = $this->pmssReadRepoFile('etc/skel/bin/pmssHermesRuntime.php');
        $this->assertStringContainsString('proc_open($command,', $source);
        $this->assertStringContainsString('$pipes, null, array())', $source);
        $command = \pmssHermesStartCommand($paths, $env['HERMES_DASHBOARD_PUBLIC_URL'], 24000, $paths['base'].'/dashboard.log');
        $this->assertStringContainsString('/usr/bin/env -i', $command);
        // Its own session: php-cgi signals its whole process group when it recycles (live QA 2026-10-21).
        $this->assertSame(0, strpos($command, '/usr/bin/setsid nohup '), $command);
        $this->assertFalse(strpos($command, str_repeat('a', 64)) !== false);
        $this->assertFalse(strpos($command, str_repeat('b', 64)) !== false);
        $process = proc_open('exec sleep 2', array(0 => array('pipe', 'r'),
            1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
        $this->assertTrue(is_resource($process));
        $pid = proc_get_status($process)['pid'];
        $cmdline = (string) @file_get_contents('/proc/'.$pid.'/cmdline');
        $this->assertFalse(strpos($cmdline, str_repeat('a', 64)) !== false);
        $this->assertFalse(strpos($cmdline, str_repeat('b', 64)) !== false);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_terminate($process);
        proc_close($process);
    }
}
