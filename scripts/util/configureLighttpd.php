#!/usr/bin/php
<?php
/**
 * Legacy shim: delegates to userConfigLighttpd.php.
 *
 * #TODO Remove this deprecated script by end of 2027. All internal calls
 *       should now point directly to userConfigLighttpd.php.
 */

require_once __DIR__.'/userConfigLighttpd.php';

exit(pmssUserConfigLighttpdMain($argv));
