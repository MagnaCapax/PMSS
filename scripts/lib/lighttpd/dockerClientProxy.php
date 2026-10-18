<?php
/** Resolve an account's running, labelled rootless Docker WebUI port. */

require_once __DIR__.'/../runtime/environment.php';
require_once __DIR__.'/../runtime/filesystem.php';
require_once __DIR__.'/../user/identity.php';

/**
 * Accept only a running client with the expected label and loopback publish.
 *
 * @param array<string,mixed> $container
 */
function pmssLighttpdDockerClientPortFromInspect(array $container, string $app): ?int
{
    $ports = ['transmission' => '9091/tcp'];
    if (!isset($ports[$app]) || ($container['Name'] ?? null) !== '/'.$app
        || ($container['State']['Running'] ?? null) !== true
        || ($container['Config']['Labels']['pmss.client'] ?? null) !== $app) {
        return null;
    }

    $bindings = $container['NetworkSettings']['Ports'][$ports[$app]] ?? null;
    if (!is_array($bindings) || count($bindings) !== 1 || !is_array($bindings[0])
        || ($bindings[0]['HostIp'] ?? null) !== '127.0.0.1') {
        return null;
    }
    $port = $bindings[0]['HostPort'] ?? null;
    return is_string($port) && ctype_digit($port) && pmssNetworkPortInRange((int) $port, 1024)
        ? (int) $port : null;
}

/** Read Docker inspect as the account so root never consults another daemon. */
function pmssLighttpdDockerClientPort(string $user, string $app, ?callable $inspect = null): ?int
{
    if (!pmssValidateUsername($user) || !in_array($app, ['transmission'], true)) {
        return null;
    }
    if ($inspect === null) {
        $account = function_exists('posix_getpwnam') ? posix_getpwnam($user) : false;
        if (!is_array($account) || !isset($account['uid'])) return null;
        $socket = '/run/user/'.(int) $account['uid'].'/docker.sock';
        if (!is_socket($socket)) return null;
        // Select only identity and published-port fields; inspect must not expose env secrets.
        $format = '{"Name":{{json .Name}},"State":{"Running":{{json .State.Running}}},'
            .'"Config":{"Labels":{{json .Config.Labels}}},'
            .'"NetworkSettings":{"Ports":{{json .NetworkSettings.Ports}}}}';
        $command = 'DOCKER_HOST='.escapeshellarg('unix://'.$socket)
            .' timeout --kill-after=1s 5s docker container inspect --format '.escapeshellarg($format).' '.escapeshellarg($app);
        $inspect = static function () use ($user, $command): ?string {
            $output = [];
            $rc = 1;
            exec(pmssBuildUserShellCommand($user, $command).' 2>/dev/null', $output, $rc);
            return $rc === 0 && count($output) === 1 ? $output[0] : null;
        };
    }
    $raw = $inspect();
    $container = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($container) ? pmssLighttpdDockerClientPortFromInspect($container, $app) : null;
}
