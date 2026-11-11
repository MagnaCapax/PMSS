<?php
/** Docker handover payloads and bounded container-set decoding. @license GPL-3.0-only */

require_once __DIR__.'/../user/dockerContainerIds.php';

/** Capture the same bounded container set that is stopped on the source. */
function pmssUserTransferBuildDockerQuiesce(array $cfg, string $localIdsPath): string
{
    $remote = 'R=/run/user/$(id -u); S=$R/docker.sock; '
        .'[ -S "$S" ] && command -v docker >/dev/null 2>&1 || exit 0; '
        .'ids=$(DOCKER_HOST=unix://$S docker ps -q --no-trunc | head -n 500) || exit 0; '
        .'[ -n "$ids" ] || exit 0; printf "\n"; printf "%s\n" "$ids"; '
        .'DOCKER_HOST=unix://$S docker stop -t 30 $ids >/dev/null';

    // The cap must not hide SSH/docker-stop failure behind head's successful exit.
    return pmssUserTransferBuildSshProbeScript($cfg, $remote, '', true)
        .' | head -c 65536 > '.escapeshellarg($localIdsPath)."\n"
        .'[ "${PIPESTATUS[*]}" = "0 0" ]'."\n";
}

/** Restart only a caller-validated set on the source account's daemon. */
function pmssUserTransferBuildDockerStartScript(array $cfg, array $ids): string
{
    if ($ids === [] || count($ids) > 500) {
        throw new RuntimeException('Invalid Docker container ID set');
    }
    if (!pmssUserDockerContainerIdsValid($ids)) throw new RuntimeException('Invalid Docker container ID');
    $remote = 'DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock docker start '
        .pmssCommandArgvShellQuote($ids).' >/dev/null 2>&1';
    return pmssUserTransferBuildSshProbeScript($cfg, $remote, "\n", true);
}

/** Keep unique, exact Docker IDs in source order and within the source cap. */
function pmssUserTransferValidDockerIds(string $raw, int $max = 500): array
{
    if ($max < 1) return [];
    $ids = [];
    foreach (explode("\n", $raw) as $line) {
        if (!pmssUserDockerContainerIdValid($line) || isset($ids[$line])) {
            continue;
        }
        $ids[$line] = true;
        if (count($ids) >= $max) {
            break;
        }
    }
    return array_keys($ids);
}

/** Rsync's routine live-source partial results can still contain the store. */
function pmssUserTransferDockerRcTransferred(int $rc): bool
{
    return in_array($rc, [0, 23, 24], true);
}
