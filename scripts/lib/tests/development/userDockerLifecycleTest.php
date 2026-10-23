<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Characterize the deployed CLI's output, exits, shell commands, signals and decision ordering. */
class UserDockerLifecycleTest extends TestCase
{
    public function testFailedContainerRuntimeBindDiagnostics(): void
    {
        // Execute only the container handler with account commands and both log sinks intercepted.
        $script = <<<'PHP'
namespace DockerMountFixture;
class UserConfigStore {}
function pmssUserDockerEnabled($user, $store) { return true; }
function pmssUserLog($user, $message) { $GLOBALS['logs'][] = $message; }
function fwrite($stream, $message) { $GLOBALS['errors'][] = $message; return strlen($message); }
function userDockerRunAs($user, $command, $timeout, &$rc = null) {
    $GLOBALS['commands'][] = [$user, $command, $timeout];
    $rc = 0;
    if (strpos($command, ' docker start ') !== false) { $rc = $GLOBALS['startRc']; return 'started'; }
    if (strpos($command, ' docker inspect ') !== false) { $rc = 1; return $GLOBALS['mounts']; }
    return '';
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/docker/containers.php', 'DockerMountFixture');
        $script .= <<<'PHP'
$bind = static function ($source, $type = 'bind') { return ['Type' => $type, 'Source' => $source]; };
$foreign = $bind('/run/user/2002/docker.sock');
$cases = [
    'foreign' => [[$foreign]],
    'own' => [[$bind('/run/user/1001/docker.sock')]],
    'volume' => [[$bind('/run/user/2002/docker.sock', 'volume')]],
    'prefix' => [[$bind('/run/user/1001-extra/docker.sock')]],
    'ordinary' => [[$bind('/srv/data')]],
    'twice' => [[$foreign, $foreign]],
    'two-containers' => [[$foreign], [$foreign]],
    'runtime-root' => [[$bind('/run/user/2002')]],
    'malformed' => [[null, ['Type' => 'bind', 'Source' => []], $bind('/run/user/no-uid/docker.sock')]],
    'invalid-json' => [],
    'partial-inspect' => [[$foreign]],
    'success' => [[$foreign]],
];
$results = [];
foreach ($cases as $name => $mounts) {
    $GLOBALS['mounts'] = $name === 'invalid-json' ? "not json\nnull\n42" : implode("\n", array_map('json_encode', $mounts));
    $GLOBALS['commands'] = $GLOBALS['logs'] = $GLOBALS['errors'] = [];
    $GLOBALS['startRc'] = $name === 'success' ? 0 : 1;
    ob_start();
    $rc = userDockerStartContainers('alice', '/run/user/1001', '/run/user/1001/docker.sock', [str_repeat('a', 64), str_repeat('b', 64)]);
    $results[$name] = [$rc, ob_get_clean(), $GLOBALS['commands'], $GLOBALS['logs'], $GLOBALS['errors']];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(12, count($results));
        foreach ($results as $name => [$rc, $stdout, $commands, $logs, $errors]) {
            $success = $name === 'success';
            $count = in_array($name, ['foreign', 'twice', 'runtime-root', 'partial-inspect'], true) ? 1 : ($name === 'two-containers' ? 2 : 0);
            $this->assertSame($success ? 0 : 1, $rc, $name);
            $this->assertSame("Started 1 of 2 container(s) for alice\n", $stdout, $name);
            $this->assertSame($success ? 3 : 4, count($commands), $name);
            if (!$success) {
                $this->assertSame('alice', $commands[2][0], $name);
                $this->assertSame(30, $commands[2][2], $name);
                $this->assertTrue(strpos($commands[2][1], "inspect --type container --format '{{json .Mounts}}'") !== false, $name);
                $this->assertTrue(strpos($commands[2][1], '2>/dev/null | head -c 1048576') !== false, $name);
            }
            $warning = sprintf("[WARN] %d of 2 container(s) bind a path under another account's runtime directory; this account's runtime directory is /run/user/1001", $count);
            $this->assertSame($count > 0 ? [$warning."\n"] : [], $errors, $name);
            $this->assertSame($count > 0 ? [$warning, 'userDocker: containers by state: '] : ['userDocker: containers by state: '], $logs, $name);
        }
    }

    public function testLifecycleBehaviorSnapshots(): void
    {
        $cases = [
            'usage' => ['argv' => ['userDocker.php']],
            'invalid-action' => ['argv' => ['userDocker.php', 'alice', 'bad']],
            'invalid-user' => ['argv' => ['userDocker.php', '../alice', 'start']],
            'unknown-user' => ['unknown' => true],
            'root-account' => ['uid' => 0],
            'status-stopped' => ['action' => 'status'],
            'status-running-debug' => ['action' => 'status', 'running' => true, 'socket' => true, 'unit' => true, 'debug' => true],
            'status-unknown' => ['action' => 'status', 'pgrep_missing' => true, 'ps_failure' => true],
            'status-ps-fallback' => ['action' => 'status', 'pgrep_missing' => true, 'ps_running' => true, 'debug' => true],
            'start-disabled' => ['disabled' => true],
            'start-running' => ['running' => true, 'debug' => true],
            'start-socket-unknown' => ['socket' => true, 'pgrep_missing' => true, 'ps_failure' => true],
            'start-fresh' => [],
            'start-same-uid' => ['euid' => 1001],
            'start-linger' => ['runtime_missing' => true],
            'stop-normal' => ['action' => 'stop'],
            'stop-unit-fails' => ['action' => 'stop', 'unit' => true, 'unit_rc' => 3],
            'stop-disabled-unit' => ['action' => 'stop', 'unit' => true, 'disabled' => true],
            'stop-survivors' => ['action' => 'stop', 'survivors' => true],
            'stop-owner-changed' => ['action' => 'stop', 'owner' => 1003],
            'restart-normal' => ['action' => 'restart'],
            'restart-scan-fails' => ['action' => 'restart', 'scan_failure' => 2],
            'containers-invalid' => ['argv' => ['userDocker.php', 'alice', 'start-containers', 'bad']],
            'containers-start' => ['action' => 'start-containers'],
            'containers-disabled' => ['action' => 'start-containers', 'disabled' => true],
            'containers-not-ready' => ['action' => 'start-containers', 'not_ready' => true],
            'containers-partial' => ['action' => 'start-containers', 'container_rc' => 1],
        ];
        // Filled from this fixture on the unmodified CLI, then held fixed across decomposition.
        $snapshots = [
            'usage' => 'c3986519cfd401439f371be9a4efb5ab1a1dada952846720f0ba5a8ed013b810',
            'invalid-action' => '2b43bb8c5f68742e7fbec702e2b3f38b6bc5368bdfa9b26a0c45489ca9a991b1',
            'invalid-user' => '58838e67fe0c62e0179e7fb63c623936a0f74efdd5a02a7e8b009b2f6acbbc39',
            'unknown-user' => 'f8d6cffad70aa2c410c2d27cf9084b29d17db5180ed514e7f54e61c4ae435604',
            'root-account' => '652aa0d354edc10e68a6c4e3471650696eb02ed8bff556bd8fca60cc831695b3',
            'status-stopped' => '36546ddd2d7984d814edc7d241e2ea772d53f0ce028973d1605633d54b29b49d',
            'status-running-debug' => 'a0422406d95009e035968c83698e518af8fad91c8e3c5a77c9830f0b3075d80c',
            'status-unknown' => 'b85df137b78c0b2e7394bbc6a3a5b270bd01ca07fc8935a26bf00c8ab41a2773',
            'status-ps-fallback' => 'bd6a2502749667fd5b8c79e9f2d3d2ee71d02d4d649ba6cb5ee62f4adfbdf332',
            'start-disabled' => 'b34c8fbbd1ea859ab7e02e58d1bd0e11e35c217b51d82f89b19f5d1a84282425',
            'start-running' => 'fb1e881209967b3ea73ece02174fbeef50a4e8eed96f63c5b6f88fa72a3a9924',
            'start-socket-unknown' => '0948aff58a00ef77eee8384234c501f7a4a4a576dd8dc3bf0fa747e0c0d9fd00',
            'start-fresh' => '353e644cfe3159c9c638d16125752614d9fa0933fc1db9c2f13edb9736bcb6ed',
            'start-same-uid' => '422d60fd586af64a657ef8f3791de861735f4c0dfba077d4e50733479f742f49',
            'start-linger' => '9995c41e4aced01286c136e6e0feccc5b202b21ca22b2fa167ad8edeea9127fb',
            'stop-normal' => 'df4d60c7cb7904ee02864ab542c57c403346109d201a94c7fea432616c12e0e9',
            'stop-unit-fails' => '675310ff644d767d4fcdaab7f89c1ed7ac6cc915cd64cac46cd63b44b0b5d105',
            'stop-disabled-unit' => 'bad5cb9c2c807d726699f63f0d248bf55476bb6e7724eaed4c6b6b6ba2f711e9',
            'stop-survivors' => '312d1fe7cbe587ec5bb3c57661f21f52ab0827ab8b94b873bacdd6a02c4cbce0',
            'stop-owner-changed' => 'a0743b8e70a33f75369a7f510d382d729f1c06bc786defceeb0f709931f6caf6',
            'restart-normal' => '480650160f7b1737d89413a91df19921fc2462429c0011cb5703515138973626',
            'restart-scan-fails' => 'b510437a5f34c4f0edc57208d11a87eddd63fbe3be8c7384444463e1b7feef31',
            'containers-invalid' => 'bdcc08d2c9776623e3ca1b61f930e481e20e86f1c993731e2ae88da272261acb',
            'containers-start' => '7beceada3c520642e88c15f51dadda5d8d291688586b5e9036195085227c25da',
            'containers-disabled' => 'df1e39d1693bc59a1ba21168db30b313e6c6db3acda3a9ef357604c9a61902e1',
            'containers-not-ready' => '048feb77e5be706720030eed18e3ba379ffd69d8860950e714b37de882f4e7e7',
            // Failed starts now add the account-scoped, bounded mount inspection (Refs #1152).
            'containers-partial' => '9b87daf60f5da646c9e6018b7cd3537598be52a7bc34d0c45c6e53a4babad556',
        ];
        foreach ($cases as $name => $case) {
            $action = $case['action'] ?? 'start';
            $case['argv'] = $case['argv'] ?? ['userDocker.php', 'alice', $action];
            if ($action === 'start-containers') $case['argv'][] = str_repeat('a', 64);
            if (!empty($case['debug'])) $case['argv'][] = '--debug';
            $result = $this->pmssExecShellCommand(escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->pmssRepoPath('scripts/lib/tests/fixtures/userDockerLifecycle.php')).' '.escapeshellarg(json_encode($case)));
            $trace = $this->pmssDecodeJsonArray($result['output']);
            $this->assertSame(null, $trace['fatal'], $name);
            $snapshot = hash('sha256', json_encode([$result['rc'], $trace]));
            $this->assertSame($snapshots[$name], $snapshot, $name.' CLI behavior changed: '.$result['output']);
        }
    }
}
