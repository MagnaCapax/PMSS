<?php
/**
 * Distribution detection and updater self-heal helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../log.php';
require_once __DIR__.'/osRelease.php';

/** @return array<int, array{label:string,repo:string,eol:bool,sources_template:bool}> */
function pmssDebianReleaseSpecs(): array
{
    static $specs = [
        8  => ['label' => 'Jessie',   'repo' => 'jessie',   'eol' => true,  'sources_template' => true],
        9  => ['label' => 'Stretch',  'repo' => 'stretch',  'eol' => true,  'sources_template' => false],
        10 => ['label' => 'Buster',   'repo' => 'buster',   'eol' => true,  'sources_template' => true],
        11 => ['label' => 'Bullseye', 'repo' => 'bullseye', 'eol' => false, 'sources_template' => true],
        12 => ['label' => 'Bookworm', 'repo' => 'bookworm', 'eol' => false, 'sources_template' => true],
        13 => ['label' => 'Trixie',   'repo' => 'trixie',   'eol' => false, 'sources_template' => true],
    ];

    return $specs;
}
function pmssDebianCodenameFromMajor(int $major): string
{
    return pmssDebianReleaseSpecs()[$major]['repo'] ?? '';
}
function pmssDistroVersionFromEnv(?int $fallback = null): int
{
    return max(0, (int) (getenv('PMSS_DISTRO_VERSION') ?: 0)) ?: (int) ($fallback ?? 0);
}

function pmssDetectDistro(): array
{
    $name = strtolower((string) (getDistroName() ?: trim((string) @shell_exec('lsb_release -is 2>/dev/null'))));
    if ($name === '') {
        $name = 'debian'; logmsg('Could not detect distro name; defaulting to debian');
    }

    $rawVersion = (string) (getDistroVersion() ?: trim((string) @shell_exec('lsb_release -rs 2>/dev/null')));
    if ($rawVersion === '') { logmsg('Could not detect distro version; defaulting to 0'); }

    $version  = (int) filter_var($rawVersion, FILTER_SANITIZE_NUMBER_INT) ?: 0;
    $codename = strtolower((string) (getDistroCodename() ?: trim((string) @shell_exec('lsb_release -cs 2>/dev/null'))));

    if (($mappedVersion = pmssVersionFromCodename($codename)) !== 0) {
        if ($mappedVersion !== $version) { logmsg(sprintf('Distro codename/version mismatch (%s vs %d); trusting codename', $codename, $version)); }
        $version = $mappedVersion;
    }
    return ['name' => $name, 'version' => $version, 'codename' => $codename];
}
function pmssVersionFromCodename(string $codename): int
{
    $major = array_search(strtolower($codename), array_map(static function (array $spec): string { return $spec['repo']; }, pmssDebianReleaseSpecs()), true);
    return $major === false ? 0 : (int) $major;
}

/** Keep phase-2 repository selection and its deferred diagnostic in the distro record. */
function pmssUpdateStep2DistroContextBuild(array $distribution): array
{
    $reported = (int) $distribution['version'];
    $mapped = pmssVersionFromCodename($distribution['codename']);
    $codename = $distribution['codename'] !== '' ? $distribution['codename'] : 'unknown';
    $distribution['repoMessage'] = '';
    if ($mapped === 0 && $reported >= 0) {
        $distribution['repoMessage'] = $reported > 0
            ? sprintf('Repository codename %s unresolved; falling back to VERSION_ID %d', $codename, $reported)
            : sprintf('Repository detection failed for distro=%s codename=%s; skipping repository updates', $distribution['name'], $codename);
    } elseif ($mapped !== 0 && $reported !== 0 && $mapped !== $reported) {
        $distribution['repoMessage'] = sprintf('Repository version mapped via codename %s -> %d (reported=%s)', $codename, $mapped, $distribution['version']);
    }
    $distribution['repoVersion'] = $mapped > 0 ? $mapped : $reported;
    $distribution['baselineVersion'] = $distribution['repoVersion'] > 0 ? $distribution['repoVersion'] : null;
    return $distribution;
}
