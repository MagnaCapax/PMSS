<?php
/** Service-port adoption and allocation for managed proxies. @license GPL-3.0-only */
require_once dirname(__DIR__).'/portManager.php';

/** Adopt a valid live port or allocate and persist its replacement; zero means failure. */
function pmssLighttpdProxyPortEnsure(string $user, string $proxyName, string $proxyPortFile): int
{
    $proxyPort = pmssReadRegularFileInt($proxyPortFile);
    if (pmssNetworkPortInRange($proxyPort, 1024, 65500)) {
        // Preserve live service ports while adopting them into the shared allocator
        // when the reservation slot is still free.
        if ($user !== '') {
            pmssPortManagerAssignServicePort($user, $proxyName, $proxyPort);
        }
        return $proxyPort;
    }

    if ($user !== '') {
        $proxyPort = pmssPortManagerAssignServicePort($user, $proxyName);
    } else {
        $portDir = dirname($proxyPortFile);
        $proxyPort = null;
        if (pmssPathTargetIsSafe($portDir, true) && is_dir($portDir) && !is_link($portDir)) {
            $used = pmssPortManagerUsedPorts($portDir, $portDir.'/.pmss-no-legacy', $scanComplete);
            if ($scanComplete) $proxyPort = pmssPortManagerSelectAvailablePort($used);
        }
    }
    if ($proxyPort === null || !pmssNetworkPortFileWrite($proxyPortFile, $proxyPort, 1024, 65500, 0640)) {
        return 0;
    }

    return $proxyPort;
}

/** Resolve proxy ports with either the explicit account or the legacy path-derived account. */
function pmssLighttpdProxyPortsEnsure($userOrProxyPortFiles, ?array $proxyPortFiles = null): array
{
    $user = is_string($userOrProxyPortFiles) ? $userOrProxyPortFiles : '';
    if ($proxyPortFiles === null) {
        $proxyPortFiles = is_array($userOrProxyPortFiles) ? $userOrProxyPortFiles : [];
    }

    $proxyPorts = [];
    foreach ($proxyPortFiles as $proxyName => $proxyPortFile) {
        $proxyFile = (string) $proxyPortFile;
        $candidateUser = basename(dirname($proxyFile));
        $proxyUser = $user !== ''
            ? $user
            : (pmssValidateUsername($candidateUser) ? $candidateUser : '');
        $proxyPorts[$proxyName] = pmssLighttpdProxyPortEnsure($proxyUser, (string) $proxyName, $proxyFile);
    }

    return $proxyPorts;
}
