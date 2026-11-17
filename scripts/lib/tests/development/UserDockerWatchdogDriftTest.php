<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Version drift can restart only a positively observed idle account daemon. */
class UserDockerWatchdogDriftTest extends TestCase
{
    public function testDriftDecisionsWithHermeticAccountAndCommandObservations(): void
    {
        $script = <<<'PHP'
namespace DockerWatchdogFixture;
function userDockerAccountInfo($user) { return $GLOBALS['account']; }
function pmssUserWatchdogBackoffActive($user, $service) { return $GLOBALS['backoff']; }
function file_exists($path) { $GLOBALS['paths'][] = $path; return $GLOBALS['socket']; }
function pmssBuildUserShellCommand($user, $command) { return 'account:'.$user.':'.$command; }
function pmssUserWatchdogCommandRun($command, $seconds, $user, $service) {
    $GLOBALS['commands'][] = [$command, $seconds, $user, $service];
    if ($GLOBALS['replies'] === []) throw new \RuntimeException('Unexpected probe');
    return array_shift($GLOBALS['replies']);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/docker/watchdog.php', 'DockerWatchdogFixture');
        $script .= <<<'PHP'
$ok = static function ($out) { return ['rc' => 0, 'stdout' => $out]; };
$failed = ['rc' => 1, 'stdout' => ''];
$timeout = ['rc' => 124, 'stdout' => ''];
$installed = $ok('Docker version 29.0.1, build abc1234');
$old = $ok('28.0.1');
$cases = [
    'same' => [[$ok('29.0.1'), $installed], 'start', 2],
    'idle' => [[$old, $installed, $ok('')], 'restart', 3],
    'busy' => [[$old, $installed, $ok("abc123\ndef456")], 'start', 3],
    'list-failed' => [[$old, $installed, $failed], 'start', 3],
    'list-timeout' => [[$old, $installed, $timeout], 'start', 3],
    'server-failed' => [[$failed], 'start', 1],
    'server-timeout' => [[$timeout], 'start', 1],
    'empty-server' => [[$ok('')], 'start', 1],
    'bad-server' => [[$ok("28.0.1\ninjected")], 'start', 1],
    'installed-failed' => [[$old, $failed], 'start', 2],
    'bad-installed' => [[$old, $ok('Docker version invalid, build abc1234')], 'start', 2],
    'empty-installed' => [[$old, $ok('')], 'start', 2],
    'prerelease' => [[$ok('29.0.1-rc.1'), $ok('Docker version 29.0.1-rc.1, build abc1234')], 'start', 2],
    'downgrade-idle' => [[$ok('30.0.0'), $installed, $ok('')], 'restart', 3],
    'missing-account' => [[], null, 0],
    'backoff' => [[], null, 0],
    'missing-socket' => [[], 'start', 0],
];
$results = [];
foreach ($cases as $name => [$replies, $expected, $count]) {
    $GLOBALS['account'] = $name === 'missing-account' ? null : ['uid' => 1057];
    $GLOBALS['backoff'] = $name === 'backoff';
    $GLOBALS['socket'] = $name !== 'missing-socket';
    $GLOBALS['commands'] = $GLOBALS['paths'] = $logs = [];
    $GLOBALS['replies'] = $replies;
    $actual = userDockerWatchdogAction('fixtur01', static function ($line) use (&$logs) { $logs[] = $line; });
    $results[$name] = [$expected, $actual, $count, $GLOBALS['commands'], $logs, $GLOBALS['paths']];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        foreach ($results as $name => [$expected, $actual, $count, $commands]) {
            $this->assertSame($expected, $actual, $name);
            $this->assertSame($count, count($commands), $name.' probe count');
            foreach ($commands as [$command, $seconds, $user, $service]) {
                $this->assertSame(5, $seconds, $name.' timeout');
                $this->assertSame('fixtur01', $user);
                $this->assertSame('docker', $service);
                $this->assertStringContainsString('account:fixtur01:', $command);
            }
        }
        $this->assertStringContainsAllStrings(["/usr/bin/docker --host='unix:///run/user/1057/docker.sock' version --format '{{.Server.Version}}'",
            'XDG_RUNTIME_DIR=\'/run/user/1057\''], $results['idle'][3][0][0]);
        $this->assertStringContainsString('/usr/bin/dockerd --version', $results['idle'][3][1][0]);
        $this->assertStringContainsString('ps --quiet', $results['idle'][3][2][0]);
        $this->assertStringContainsString('decision=restart reason=idle', implode('\n', $results['idle'][4]));
        $this->assertStringContainsString('reason=running_containers', implode('\n', $results['busy'][4]));
        $this->assertStringContainsString('reason=container_probe_failed', implode('\n', $results['list-failed'][4]));
        $this->assertSame(['/run/user/1057/docker.sock'], $results['idle'][5]);
    }

    public function testCronKeepsPolicyGatesBeforeProbeAndUsesExistingLifecycleHelper(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/checkRootlessDocker.php');
        $this->assertOrderedStrings([
            'pmssUserServicesParked($user)',
            'pmssUserDockerEnabled($user, $userConfigStore)',
            'userDockerWatchdogAction($user, $logDockerMessage)',
            'if ($action === null) continue;',
            "sprintf('php /scripts/util/userDocker.php %s %s'",
            'runCommand($cmd, false, $logDockerMessage)',
        ], $source);
        $this->assertStringContainsString("['command.php', 'watchdog.php']", $source);
    }
}
