#!/usr/bin/env php
<?php
/**
 * Nginx server config
 *
 * Creates nginx config for reverse proxying per-user lighttpd instances.
 *
 * Suspension handling:
 * - The canonical suspended marker is `/home/<user>/www-disabled`. When present,
 *   nginx uses the suspended template instead of proxying.
 *
 * This script intentionally stays focused on nginx config generation. It will
 * only invoke per-user lighttpd config regeneration when required to discover
 * a missing lighttpd port assignment on legacy hosts.
 *
 * @author Aleksi Ursin
 * @copyright NuCode 2015-2023 - All Rights reserved.
 * @since 31/03/2015
 * @version 1.1
 *
 * @license GPL-3.0-only
 **/

require_once __DIR__.'/../lib/homeMount.php';

// Guard: PMSS requires /home to be a separately mounted filesystem. When /home
// is unavailable (array failure, mount timeout), this script would wipe existing
// nginx user configs then produce zero replacements (because home dirs appear
// missing), causing downtime until manual intervention. Abort early to preserve
// working configs. Credit to Chris M. (Canada) for reporting this failure mode.
pmssRequireHomeMounted('createNginxConfig.php');
if (is_file($pmssUserLogPath = __DIR__.'/../lib/user/log.php')) {
    require_once $pmssUserLogPath;
}

require_once __DIR__.'/../lib/nginxConfig/main.php';

exit(pmssCreateNginxConfigMain($argv));
