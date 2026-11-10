<?php
/** Account identity observations for support reports. @license GPL-3.0-only */
require_once dirname(__DIR__).'/runtime.php';

/**
 * Read the caller's own billing marker copy from its home.
 *
 * The support command runs as the account, which cannot read the root store
 * (ADR 0016). The request is the account's own report, so its own marker copy
 * is used when the store is unreadable. Nothing root-side reads this value.
 *
 * @param list<string> $fileNames
 */
function pmssSupportOwnBillingMarkerRead(string $home, array $fileNames): ?string
{
    $home = rtrim($home, '/');
    if ($home === '' || $home[0] !== '/') return null;
    foreach ($fileNames as $fileName) {
        $raw = pmssReadRegularFileContentsVerified($home.'/'.$fileName, 0, 256);
        $digits = is_string($raw) ? trim($raw, " \t\r\n") : '';
        $id = pmssUnsignedDecimalIntParse($digits);
        if ($id !== null && $id > 0) return $digits;
    }
    return null;
}

/**
 * Resolve the current caller identity from trusted process state.
 *
 * @return array<string,string>
 */
function pmssSupportIdentityRead(): array
{
    $isSafeUsername = static function (string $username): bool {
        $username = trim($username);
        if ($username === '' || strpos($username, "\0") !== false || strpos($username, '/') !== false
            || strpos($username, '\\') !== false || strpos($username, '..') !== false) {
            return false;
        }
        return preg_match('/[[:space:][:cntrl:]]/', $username) !== 1;
    };

    $homeRoot = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/');
    $homeRoot = ($homeRoot !== '' && $homeRoot[0] === '/' && strpos($homeRoot, "\0") === false) ? $homeRoot : '';
    $envUser = getenv('USER');
    $envUser = is_string($envUser) ? trim($envUser) : '';
    $envHome = getenv('HOME');
    $envHome = is_string($envHome) ? rtrim($envHome, '/') : '';
    $username = '';

    if ($homeRoot !== '' && $isSafeUsername($envUser)
        && ($expectedHome = $homeRoot.'/'.$envUser) !== '' && $envHome !== '' && $envHome === $expectedHome) {
        $username = $envUser;
    }

    if ($username === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $entry = @posix_getpwuid(posix_geteuid());
        $name = is_array($entry) && !empty($entry['name']) ? (string) $entry['name'] : '';
        if ($isSafeUsername($name)) {
            $username = $name;
        }
    }

    $expectedHome = ($username !== '' && $homeRoot !== '') ? $homeRoot.'/'.$username : '';
    $home = $expectedHome;
    if ($expectedHome !== '' && $envHome !== '' && $envHome === $expectedHome && pmssPathTargetIsSafe($envHome, true)) {
        $home = $envHome;
    } elseif ($username !== '' && function_exists('posix_getpwnam')) {
        $entry = @posix_getpwnam($username);
        $dir = is_array($entry) && !empty($entry['dir']) ? rtrim((string) $entry['dir'], '/') : '';
        if ($dir !== '' && pmssPathTargetIsSafe($dir, true)) {
            $home = $dir;
        }
    }

    return ['username' => $username, 'home' => $home];
}
