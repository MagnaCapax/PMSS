<?php
/**
 * Hardened root-side Servarr config defaults.
 *
 * PMSS installs the ARR applications but never runs them: they are per-account
 * services that must execute as the customer's own uid. Root has no legitimate ARR
 * instance, so /root/.config/<App>/ only ever appears because something started the
 * app as root by accident. When that happens the app writes its OWN first-run
 * defaults -- AuthenticationMethod=None and BindAddress=* -- which is an
 * unauthenticated root-privileged HTTP API on a public port (the 2026-07-03 fleet
 * root compromise).
 *
 * This module removes the reward for that accident. It converges the root-side
 * config to authenticated, loopback-bound defaults with a random API credential,
 * both by pre-seeding hosts where the accident is possible and by repairing configs
 * a past accident already left behind.
 *
 * SCOPE HONESTY: loopback binding removes the REMOTE attack surface. On a
 * multi-tenant seedbox every customer can reach 127.0.0.1, so this is blast-radius
 * reduction, not a security boundary. The boundary is that PMSS never executes the
 * binary -- see scripts/lib/update/apps/arr.php.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/managedPath.php';
require_once __DIR__.'/apps/arr.php';

// Security elements forced on every root-side ARR config, seeded or pre-existing.
const PMSS_ARR_ROOT_CONFIG_FORCED = [
    'BindAddress'            => '127.0.0.1',
    'AuthenticationMethod'   => 'Forms',
    'AuthenticationRequired' => 'Enabled',
];

// Provenance marker: keeps "PMSS seeded this" distinguishable from "root ran the app",
// so the forensic signal used to measure root-run hosts survives this hardening.
const PMSS_ARR_ROOT_CONFIG_MARKER = 'PMSS hardened default - root must never run this application';

/**
 * Generate a random API credential, or null when no CSPRNG is available.
 *
 * Fails closed on purpose: a predictable credential is worse than no file at all.
 */
function pmssArrRootConfigRandomCredential(): ?string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (\Throwable $randomFailure) {
        return null;
    }
}

/** Replace an XML element's value, appending the element when it is absent. */
function pmssArrRootConfigElementSet(string $xml, string $name, string $value): string
{
    $quoted = preg_quote($name, '#');
    $element = '<'.$name.'>'.$value.'</'.$name.'>';
    // preg_replace_callback keeps $ and \ in the replacement literal.
    $literal = static function () use ($element): string { return $element; };

    if (preg_match('#<'.$quoted.'>.*?</'.$quoted.'>#is', $xml) === 1) {
        return (string) preg_replace_callback('#<'.$quoted.'>.*?</'.$quoted.'>#is', $literal, $xml, 1);
    }

    $appended = static function () use ($element): string { return '  '.$element."\n</Config>"; };
    return (string) preg_replace_callback('#</Config>#i', $appended, $xml, 1);
}

/** Normalize a self-closing <Config /> so elements can be appended; null when unrecognised. */
function pmssArrRootConfigNormalize(string $xml): ?string
{
    if (preg_match('#<Config\s*/>#i', $xml) === 1) {
        return (string) preg_replace('#<Config\s*/>#i', "<Config>\n</Config>", $xml, 1);
    }

    return (stripos($xml, '<Config') !== false && stripos($xml, '</Config>') !== false) ? $xml : null;
}

/** Build a fresh hardened config; null when no CSPRNG is available. */
function pmssArrRootConfigTemplate(): ?string
{
    $apiKey = pmssArrRootConfigRandomCredential();
    if ($apiKey === null) {
        return null;
    }

    $xml = "<Config>\n  <!-- ".PMSS_ARR_ROOT_CONFIG_MARKER." -->\n</Config>\n";
    foreach (PMSS_ARR_ROOT_CONFIG_FORCED as $name => $value) {
        $xml = pmssArrRootConfigElementSet($xml, $name, $value);
    }

    return pmssArrRootConfigElementSet($xml, 'ApiKey', $apiKey);
}

/**
 * Apply hardened defaults to an existing config.
 *
 * Returns null when the payload is not a recognisable ARR config (left untouched
 * rather than destroyed) or when a missing API key cannot be generated safely.
 */
function pmssArrRootConfigHarden(string $xml): ?string
{
    $xml = pmssArrRootConfigNormalize($xml);
    if ($xml === null) {
        return null;
    }

    foreach (PMSS_ARR_ROOT_CONFIG_FORCED as $name => $value) {
        $xml = pmssArrRootConfigElementSet($xml, $name, $value);
    }

    // A short or absent key is replaced; an existing strong key is left alone so
    // repeated updates stay idempotent.
    if (preg_match('#<ApiKey>[a-f0-9]{32,}</ApiKey>#i', $xml) !== 1) {
        $apiKey = pmssArrRootConfigRandomCredential();
        if ($apiKey === null) {
            return null;
        }
        $xml = pmssArrRootConfigElementSet($xml, 'ApiKey', $apiKey);
    }

    if (strpos($xml, PMSS_ARR_ROOT_CONFIG_MARKER) === false) {
        $marker = static function (): string { return "<Config>\n  <!-- ".PMSS_ARR_ROOT_CONFIG_MARKER.' -->'; };
        $xml = (string) preg_replace_callback('#<Config>#i', $marker, $xml, 1);
    }

    return $xml;
}

/**
 * Converge every root-side ARR config to hardened defaults.
 *
 * Seeds a config where an accidental root launch is possible (the app is installed)
 * and repairs one wherever a past accidental launch already left one behind.
 */
function pmssEnsureArrRootConfigHardening(callable $log, string $configRoot = '/root/.config', string $installRoot = '/opt'): void
{
    foreach (array_keys(PMSS_ARR_APP_BRANCHES) as $app) {
        $directory = $configRoot.'/'.$app;
        $path = $directory.'/config.xml';
        $configDirectoryExists = is_dir($directory);

        if (!$configDirectoryExists && !is_dir($installRoot.'/'.$app)) {
            continue; // Not installed and never root-run: nothing to defend.
        }
        if (is_link($directory) || is_link($path)) {
            $log('[WARN] '.$app.': symlinked root config path, refusing to write '.$path);
            continue;
        }

        $current = ($configDirectoryExists && is_file($path)) ? @file_get_contents($path) : false;
        $hardened = (is_string($current) && trim($current) !== '')
            ? pmssArrRootConfigHarden($current)
            : pmssArrRootConfigTemplate();
        if ($hardened === null) {
            $log('[WARN] '.$app.': unrecognised or unhardenable root config, leaving '.$path.' untouched');
            continue;
        }

        if (pmssRefreshManagedPathFile($path, $hardened, $app.' root config', $log, [
            'mode' => 0600,
            'directoryMode' => 0700,
        ])) {
            $log($app.': applied hardened root config defaults at '.$path);
        }
    }
}
