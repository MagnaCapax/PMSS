<?php
/**
 * Root-side reservation bridge for customer-run media-stack applications.
 *
 * The installer cannot traverse the operator-only /scripts tree, so normal
 * per-user HTTP convergence reserves ports and publishes readable markers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/portManager.php';

/** @return array<string, array{path:string,patterns:array<int,string>}> */
function pmssMediaStackPortDefinitions(): array
{
    $iniPort = '/^[\t ]*port[\t ]*=[\t ]*"?([0-9]{1,5})"?/mi';
    $xmlPort = '/<Port>[\t\r\n ]*([0-9]{1,5})[\t\r\n ]*<\/Port>/i';

    return array(
        'sabnzbd' => array('path' => '.config/sabnzbd/sabnzbd.ini', 'patterns' => array($iniPort)),
        'radarr' => array('path' => '.config/radarr/config.xml', 'patterns' => array($xmlPort)),
        'prowlarr' => array('path' => '.config/prowlarr/config.xml', 'patterns' => array($xmlPort)),
        'sonarr' => array('path' => '.config/sonarr/config.xml', 'patterns' => array($xmlPort)),
        'autobrr' => array('path' => '.config/autobrr/config.toml', 'patterns' => array($iniPort)),
        'jellyfin' => array(
            'path' => '.config/jellyfin/config/network.xml',
            'patterns' => array(
                '/<InternalHttpPort>[\t\r\n ]*([0-9]{1,5})[\t\r\n ]*<\/InternalHttpPort>/i',
                '/<PublicHttpPort>[\t\r\n ]*([0-9]{1,5})[\t\r\n ]*<\/PublicHttpPort>/i',
            ),
        ),
    );
}

/** Read an adoptable port only from a small regular file inside the user's home. */
function pmssMediaStackConfiguredPortRead(string $home, array $definition): ?int
{
    $home = rtrim($home, '/');
    $path = $home.'/'.ltrim((string) ($definition['path'] ?? ''), '/');
    $homeReal = realpath($home);
    $pathReal = realpath($path);
    $size = @filesize($path);
    if (!is_string($homeReal) || !is_string($pathReal)
        || strpos($pathReal, $homeReal.'/') !== 0
        || !is_file($path) || is_link($path)
        || !is_int($size) || $size < 1 || $size > 1048576
    ) {
        return null;
    }

    $content = @file_get_contents($path);
    if (!is_string($content)) {
        return null;
    }
    foreach (($definition['patterns'] ?? array()) as $pattern) {
        if (preg_match((string) $pattern, $content, $matches) === 1) {
            $port = (int) $matches[1];
            return pmssNetworkPortInRange($port, PMSS_PORT_MANAGER_MIN_PORT, PMSS_PORT_MANAGER_MAX_PORT)
                ? $port
                : null;
        }
    }

    return null;
}

/** Reserve every supported media-stack port and publish its per-user marker. */
function pmssMediaStackPortsEnsure(string $user, string $home): array
{
    $ports = array();
    $home = rtrim($home, '/');
    if (!pmssValidateUsername($user) || basename($home) !== $user || !is_dir($home) || is_link($home)) {
        return $ports;
    }

    foreach (pmssMediaStackPortDefinitions() as $app => $definition) {
        $preferred = pmssMediaStackConfiguredPortRead($home, $definition);
        $port = pmssPortManagerAssignServicePort($user, 'media-stack-'.$app, $preferred);
        $marker = $home.'/.media-stack-port-'.$app;
        if ($port === null || !pmssWriteUserFile($marker, (string) $port, $user, 0644)) {
            $ports[$app] = 0;
            continue;
        }
        $ports[$app] = $port;
    }

    return $ports;
}
