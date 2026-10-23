<?php
/** Legacy process, unit and socket status presentation. Loaded by cli.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Report observations without querying the systemd user bus. */
function userDockerStatus(string $user, string $home, string $runtimeDir, string $dockerSock, string $dockerUnitPath, bool $serviceExists, array $dockerPids, bool $processCheckOk, bool $debug): int
{
    sort($dockerPids);
    $socketPresent = file_exists($dockerSock);
    echo !empty($dockerPids)
        ? "docker process: running (pid(s): ".implode(', ', $dockerPids).")\n"
        : ($processCheckOk ? "docker process: not running\n" : "docker process: unknown (process check failed)\n");
    echo $serviceExists ? "systemd user service unit: present at {$dockerUnitPath}\n" : "systemd user service unit: missing\n";
    echo $socketPresent ? "docker socket: present at {$dockerSock}\n" : "docker socket: not found at {$dockerSock}\n";
    if ($debug) {
        echo "debug: home={$home}\n";
        echo "debug: runtime_dir={$runtimeDir}\n";
        echo "debug: unit_path={$dockerUnitPath}\n";
        echo "debug: process_check_ok=".($processCheckOk ? 'yes' : 'no')."\n";
        pmssUserLog($user, sprintf(
            'userDocker: debug status home=%s runtime_dir=%s unit_path=%s socket=%s process_check_ok=%s',
            $home,
            $runtimeDir,
            $dockerUnitPath,
            $socketPresent ? 'present' : 'missing',
            $processCheckOk ? 'yes' : 'no'
        ));
    }
    return 0;
}
