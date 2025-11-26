#!/usr/bin/php
<?php
/**
 * Legacy shim: delegates to userConfigLighttpd.php.
 */

require_once __DIR__.'/userConfigLighttpd.php';

exit(pmssUserConfigLighttpdMain($argv));
