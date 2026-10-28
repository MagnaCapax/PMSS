<?php
/** Deluge provisioning template selection and rendering; loaded through deluge.php.
 * @license GPL-3.0-only
 */

/**
 * Pick the Deluge core template path for the active Debian release.
 *
 * Debian 12+ ships libtorrent 2.0, which removed the legacy cache_*
 * settings. Older releases keep the cache directives enabled.
 *
 * @param array{name?:string,version?:int,codename?:string}|null $distro
 */
function pmssDelugeCoreTemplatePath(?array $distro = null): string
{
    return (int) (($distro['version'] ?? 0)) >= 12
        ? pmssDelugeTemplatePath('template.deluge.core.nocache.conf')
        : pmssDelugeTemplatePath('template.deluge.core.conf');
}

/**
 * Resolve an auxiliary Deluge template under the configured seedbox config dir.
 */
function pmssDelugeTemplatePath(string $templateName): string
{
    return pmssResolvePathFromEnv('PMSS_SEEDBOX_CONFIG_DIR', '/etc/seedbox/config').'/'.$templateName;
}

/**
 * Read a required Deluge template and emit a structured warning on failure.
 */
function pmssDelugeTemplateRead(string $templatePath, string $label): string
{
    $template = @file_get_contents($templatePath);
    if (is_string($template) && $template !== '') {
        return $template;
    }

    pmssLogStatus('WARN', sprintf(
        'Skipping Deluge %s template because it is %s: %s',
        $label,
        file_exists($templatePath) ? 'empty' : 'missing',
        $templatePath
    ), 1);
    return '';
}

/**
 * Render Deluge core.conf from the PMSS template and runtime values.
 *
 * @param array{name:string,memory:int|float} $user
 * @param array{name?:string,version?:int,codename?:string}|null $distro
 */
function pmssDelugeRenderCoreConfig(array $user, int $delugePort, string $uploadThrottle, ?array $distro = null): string
{
    $username = (string) $user['name'];
    $template = pmssDelugeTemplateRead(pmssDelugeCoreTemplatePath($distro ?? pmssDetectDistro()), 'core');
    if ($template === '') {
        return '';
    }

    return str_replace(
        ['##USERNAME##', '##CACHE', '##DAEMONPORT', '##UPLOAD_THROTTLE##'],
        [$username, (int) ($user['memory'] * 1024 / 16), $delugePort, $uploadThrottle],
        $template
    );
}
