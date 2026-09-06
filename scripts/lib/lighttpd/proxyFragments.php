<?php
/**
 * PMSS-managed per-user lighttpd reverse proxy fragments.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/userFileWrite.php';
require_once __DIR__.'/../portManager.php';

function pmssLighttpdProxyRuleFragment(
    string $pattern,
    int $port,
    array $pathMap = [],
    bool $disableAuth = false,
    bool $forwardForwardedHeaders = false,
    bool $injectZeroContentLengthOnEmptyPost = false,
    bool $preserveRequestHostHeader = false
): string {
    $hasPathMap = count($pathMap) > 0;
    $fragment = '$HTTP["url"] =~ "'.$pattern."\" {\n";
    if ($disableAuth) {
        $fragment .= "  auth.require = ()\n";
    }
    if ($injectZeroContentLengthOnEmptyPost) {
        $fragment .= '  $HTTP["request-method"] == "POST" {'."\n"
            .'    $REQUEST_HEADER["Content-Length"] == "" {'."\n"
            .'      setenv.set-request-header = ( "Content-Length" => "0" )'."\n"
            .'    }'."\n"
            .'  }'."\n";
    }

    $fragment .= "  proxy.server = ( \"\" => ( (\n    \"host\" => \"127.0.0.1\",\n    \"port\" => {$port}\n  ) ) ),\n";
    if ($preserveRequestHostHeader) {
        // qBittorrent validates Host/Origin against the browser-visible origin.
        $fragment .= "  proxy.replace-http-host = \"disable\"\n";
    }
    if ($forwardForwardedHeaders) {
        $fragment .= "  proxy.forwarded = ( \"for\" => 1,\n                      \"host\" => 1,\n                      \"by\" => 1\n  )";
        $fragment .= ",\n";
    }
    $fragment .= "  proxy.header = (\n      \"upgrade\" => \"enable\"";
    if ($hasPathMap) {
        $mappings = [];
        $lastSource = array_key_last($pathMap);
        foreach ($pathMap as $source => $target) {
            $mappings[] = '         "'.$source.'"'.(substr($source, -1) === '/' ? '  ' : ' ').'=> "'.$target.'"'.($source === $lastSource ? '' : ',');
        }
        $fragment .= ",\n      \"map-urlpath\" => (\n".implode("\n", $mappings)."\n       )";
    }
    $fragment .= "\n  )\n";

    return $fragment.'}';
}

function pmssLighttpdProxyExactRedirectFragment(string $sourcePath, string $targetPath): string
{
    return '$HTTP["url"] == "'.$sourcePath."\" {\n"
        .'  url.redirect = ( "" => "'.$targetPath."\" )\n"
        .'}';
}

function pmssLighttpdManagedProxyFragment(string $proxyName, string $user, int $port): string
{
    $pathMap = static function (string $basePath): array {
        return [$basePath.'/' => '/', $basePath => ''];
    };
    $definitions = [
        'deluge' => [
            "# PMSS-managed: Deluge reverse proxy.\n# Legacy path /deluge-{$user}/ kept for compatibility until at least 2028-01-28.\n\n",
            [
                ['^/user-'.$user.'/deluge($|/)', $pathMap('/user-'.$user.'/deluge'), true, false],
                ['^/deluge-'.$user.'($|/)', $pathMap('/deluge-'.$user), true, false],
            ],
        ],
        'rclone' => ["# PMSS-managed: rclone reverse proxy.\n\n", [['^/user-'.$user.'/rclone/', [], true, false, true]]],
        'qbittorrent' => [
            "# PMSS-managed: qBittorrent reverse proxy.\n\n",
            [
                ['redirect', '/user-'.$user.'/qbittorrent', '/user-'.$user.'/qbittorrent/'],
                ['^/user-'.$user.'/qbittorrent/', ['/user-'.$user.'/qbittorrent/' => '/'], false, true, false, true],
            ],
        ],
        'invidious' => [
            "# PMSS-managed: Invidious reverse proxy.\n\n",
            [
                ['^/public-'.$user.'/invidious($|/)', $pathMap('/public-'.$user.'/invidious'), false, true],
                ['^/user-'.$user.'/apps/invidious($|/)', $pathMap('/user-'.$user.'/apps/invidious'), false, true],
            ],
        ],
    ];
    if (!isset($definitions[$proxyName])) {
        return '';
    }

    $fragment = $definitions[$proxyName][0];
    foreach ($definitions[$proxyName][1] as $rule) {
        if ($rule[0] === 'redirect') {
            $fragment .= pmssLighttpdProxyExactRedirectFragment($rule[1], $rule[2])."\n\n";
            continue;
        }
        $fragment .= pmssLighttpdProxyRuleFragment($rule[0], $port, $rule[1], $rule[2], $rule[3], $rule[4] ?? false, $rule[5] ?? false)."\n\n";
    }

    return $fragment;
}

/** Return the regex strings used by lighttpd URL conditionals. */
function pmssLighttpdProxyFragmentUrlConditionPatterns(string $fragment): array
{
    $matches = [];
    if (preg_match_all('/^[ \t]*\$HTTP\["url"\][ \t]*=~[ \t]*"([^"\r\n]+)"[ \t]*\{/m', $fragment, $matches) < 1) {
        return [];
    }

    return array_values(array_unique($matches[1]));
}

/**
 * Detect an exact URL-conditional collision in a sibling fragment.
 *
 * A null result means the directory could not be scanned safely and callers
 * must not create or replace the managed fragment.
 */
function pmssLighttpdProxyFragmentSiblingConflict(string $managedPath, string $managedFragment): ?bool
{
    $patterns = pmssLighttpdProxyFragmentUrlConditionPatterns($managedFragment);
    $directory = dirname($managedPath);
    if ($patterns === [] || !pmssPathTargetIsSafe($directory, true)) {
        return $patterns === [] ? false : null;
    }

    $siblings = @glob($directory.'/*.conf');
    if ($siblings === false) {
        return null;
    }
    foreach ($siblings as $siblingPath) {
        if ($siblingPath === $managedPath) {
            continue;
        }
        if (!pmssPathTargetIsSafe($siblingPath, false) || !is_readable($siblingPath)) {
            return null;
        }
        $siblingFragment = @file_get_contents($siblingPath);
        if (!is_string($siblingFragment)) {
            return null;
        }
        if (array_intersect($patterns, pmssLighttpdProxyFragmentUrlConditionPatterns($siblingFragment)) !== []) {
            return true;
        }
    }

    return false;
}

function pmssLighttpdWriteManagedProxyFragment(string $proxyName, string $user, int $port, string $path): bool
{
    $fragment = pmssLighttpdManagedProxyFragment($proxyName, $user, $port);
    $siblingConflict = pmssLighttpdProxyFragmentSiblingConflict($path, $fragment);
    if ($siblingConflict !== false) {
        if ($siblingConflict === null) {
            fwrite(STDERR, "[user:{$user}] Skipping {$proxyName} lighttpd fragment; sibling fragments could not be scanned safely\n");
            return false;
        }
        if ((file_exists($path) || is_link($path))
            && (!pmssUserFilePathIsSafe($path) || !is_file($path) || !@unlink($path))
        ) {
            fwrite(STDERR, "[user:{$user}] Failed to yield {$proxyName} lighttpd fragment to an existing URL conditional\n");
            return false;
        }
        fwrite(STDERR, "[user:{$user}] Skipping {$proxyName} lighttpd fragment; sibling fragment already defines its URL conditional\n");
        return true;
    }
    if (pmssWriteUserFile($path, $fragment, $user, 0640)) {
        return true;
    }
    fwrite(STDERR, "[user:{$user}] Failed to write {$proxyName} lighttpd fragment\n");

    return false;
}

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
        $proxyPort = (!pmssPathTargetIsSafe($portDir, true) || !is_dir($portDir) || is_link($portDir))
            ? null
            : pmssPortManagerSelectAvailablePort(pmssPortManagerUsedPorts($portDir, $portDir.'/.pmss-no-legacy'));
    }
    if ($proxyPort === null || !pmssNetworkPortFileWrite($proxyPortFile, $proxyPort, 1024, 65500, 0640)) {
        return 0;
    }

    return $proxyPort;
}

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
