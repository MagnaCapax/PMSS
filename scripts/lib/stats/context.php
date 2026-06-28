<?php
/**
 * Context resolution for per-account terminal stats.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssStatsContextUserNormalize(string $user): string
{
    $user = trim($user);
    if ($user === '') return '';
    if (function_exists('pmssUsernameNormalizeIfValid')) {
        $normalized = pmssUsernameNormalizeIfValid($user);
        return is_string($normalized) ? $normalized : $user;
    }
    if (function_exists('pmssNormalizeUsername')) {
        $normalized = pmssNormalizeUsername($user);
        if (function_exists('pmssUsernameIsValid') && pmssUsernameIsValid($normalized)) return $normalized;
    }
    return $user;
}

function pmssStatsContextPathResolve($candidate, string $default, bool $directoryPath = false): string
{
    $path = trim((string) $candidate);
    if ($path === '' || preg_match('/[\r\n\0]/', $path) === 1 || $path[0] !== '/') $path = $default;
    return $directoryPath ? pmssDirPathNormalize($path) : $path;
}

function pmssStatsContextValue(array $overrides, string $key, string $envKey, string $default): string
{
    if (array_key_exists($key, $overrides)) return (string) $overrides[$key];
    return is_string($value = getenv($envKey)) && $value !== '' ? $value : $default;
}

/**
 * Resolve the effective user, home, and local PMSS paths for stats reads.
 *
 * @param array<string, string> $overrides
 * @return array<string, string>
 */
function pmssStatsResolveContext(array $overrides = []): array
{
    $user = trim((string) ($overrides['user'] ?? getenv('PMSS_STATS_USER') ?: ''));
    if ($user === '' && function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $entry = @posix_getpwuid(posix_geteuid());
        if (is_array($entry) && isset($entry['name'])) $user = trim((string) $entry['name']);
    }
    if ($user === '') $user = trim((string) (getenv('USER') ?: get_current_user()));
    $user = pmssStatsContextUserNormalize($user);

    $defaultHome = function_exists('pmssUsernameIsValid') && pmssUsernameIsValid($user) ? '/home/'.$user : '/home';
    if (array_key_exists('home', $overrides)) {
        $homeCandidate = (string) $overrides['home'];
    } elseif (is_string($statsHome = getenv('PMSS_STATS_HOME')) && trim($statsHome) !== '') {
        $homeCandidate = $statsHome;
    } else {
        $statsUser = array_key_exists('user', $overrides) ? $overrides['user'] : getenv('PMSS_STATS_USER');
        $shellHome = getenv('HOME');
        $homeCandidate = is_string($statsUser) && trim($statsUser) !== ''
            ? $defaultHome
            : ((is_string($shellHome) && trim($shellHome) !== '') ? $shellHome : $defaultHome);
    }

    $home = pmssStatsContextPathResolve($homeCandidate, $defaultHome, true);
    $configDir = pmssStatsContextPathResolve(pmssStatsContextValue($overrides, 'config_dir', 'PMSS_STATS_CONFIG_DIR', '/etc/seedbox/config'), '/etc/seedbox/config', true);
    $socketPath = pmssStatsContextPathResolve(pmssStatsContextValue($overrides, 'socket_path', 'PMSS_STATS_SOCKET_PATH', $home.'/.rtorrent.socket'), $home.'/.rtorrent.socket');
    $versionFile = pmssStatsContextPathResolve(pmssStatsContextValue($overrides, 'version_file', 'PMSS_STATS_VERSION_FILE', '/etc/seedbox/config/version'), '/etc/seedbox/config/version');
    $cgroupDir = pmssStatsContextPathResolve(pmssStatsContextValue($overrides, 'cgroup_dir', 'PMSS_STATS_CGROUP_DIR', ''), '', true);
    if ($cgroupDir === '') {
        foreach (pmssCgroupSelfEntries() as $entry) {
            foreach (['/sys/fs/cgroup'.$entry['path'], '/sys/fs/cgroup/unified'.$entry['path']] as $candidate) {
                if (is_dir($candidate)) {
                    $cgroupDir = $candidate;
                    break 2;
                }
            }
        }
    }

    return [
        'user' => $user,
        'home' => $home,
        'config_dir' => $configDir,
        'socket_path' => $socketPath,
        'version_file' => $versionFile,
        'cgroup_dir' => $cgroupDir,
    ];
}
