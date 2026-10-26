<?php
/** ARR release helpers; loaded through arr.php. @license GPL-3.0-only */
/** Extract a semantic version from ARR metadata or CLI output. */
function pmssArrVersionExtract(string $payload): ?string
{
    return preg_match('/(\d+\.\d+\.\d+(?:\.\d+)?)/', $payload, $match) === 1
        ? $match[1]
        : null;
}

/**
 * Detect the installed ARR version from on-disk metadata only.
 *
 * Metadata reads are the ONLY permitted detection method: the install tree may be a
 * leftover owned by a departed uid, and an executable-permission check is not a trust
 * check; see docs/adr/0034-install-is-not-execution-for-third-party-app-installers.md.
 * Returning null is safe -- pmssArrUpdate() reinstalls on an unknown
 * version, which is idempotent and self-healing (it also writes version.txt, so a
 * host pays this at most once).
 */
function pmssArrInstalledVersionRead(string $installPath): ?string
{
    if (!function_exists('pmssReadRegularFileContents')) {
        require_once dirname(__DIR__, 3).'/runtime/filesystem.php';
    }
    foreach ([$installPath.'/version.txt', $installPath.'/VERSION'] as $file) {
        // Reject symlinks: root must not be redirected out of the install tree by a
        // marker planted in a foreign-owned /opt directory.
        $versionPayload = pmssReadRegularFileContents($file);
        if ($versionPayload !== null && $versionPayload !== ''
            && ($version = pmssArrVersionExtract($versionPayload)) !== null) return $version;
    }

    return null;
}

/** Select the release asset matching the host architecture, or a generic build.
 * @return array{0:string,1:string,2:string}|null
 */
function pmssArrReleaseAssetSelect(array $releases, string $assetPattern, string $architecture): ?array
{
    $targetArchitectureTokens = ['arm64' => ['arm64', 'aarch64'], 'armhf' => ['armhf', 'armv7', 'arm']][$architecture] ?? ['x64', 'amd64'];
    $knownArchitectureTokens = ['x64', 'amd64', 'arm64', 'aarch64', 'armhf', 'armv7', 'arm'];
    $tokenPattern = static function (array $tokens): string { return '/(?:^|[^a-z0-9])(?:'.implode('|', array_map(static function (string $token): string { return preg_quote(strtolower($token), '/'); }, $tokens)).')(?=[^a-z0-9]|$)/'; };
    $targetArchitecturePattern = $tokenPattern($targetArchitectureTokens);
    $knownArchitecturePattern = $tokenPattern($knownArchitectureTokens);
    $genericAsset = null;

    foreach ($releases as $release) {
        if (empty($release['assets']) || !is_array($release['assets'])) { continue; }
        foreach ($release['assets'] as $candidateAsset) {
            $name = (string) ($candidateAsset['name'] ?? '');
            if (!pmssArrIsSafeConfigValue($name) || basename($name) !== $name
                || strpos($name, '/') !== false || strpos($name, '\\') !== false
                || !preg_match($assetPattern, $name, $match)) {
                continue;
            }
            $url = (string) ($candidateAsset['browser_download_url'] ?? '');
            if ($url === '') { continue; }
            // Normalize the captured version through the same extractor used to read
            // version.txt back. The greedy [0-9.]+ capture keeps the separator before
            // "linux" ("6.2.0.10390."), which would never equal the value read back and
            // would therefore reinstall every app on every update.
            $candidate = [pmssArrVersionExtract($match[1]) ?? $match[1], $url, $name];
            $nameLower = strtolower($name);
            if (strpos($nameLower, 'musl') !== false) {
                continue; // skip musl builds: dynamic loader /lib/ld-musl-* is absent on glibc Debian -> non-executable (#489, libc filter missing post-#447)
            }
            if (preg_match($targetArchitecturePattern, $nameLower) === 1) {
                return $candidate;
            }
            if ($genericAsset === null && preg_match($knownArchitecturePattern, $nameLower) !== 1) {
                $genericAsset = $candidate;
            }
        }
    }

    return $genericAsset;
}
