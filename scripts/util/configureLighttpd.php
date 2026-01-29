#!/usr/bin/env php
<?php
/**
 * Legacy shim: delegates to userConfigLighttpd.php.
 *
 * #TODO Remove this deprecated script by H2-2027 (no earlier).
 *       All internal calls should now point directly to userConfigLighttpd.php.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/userConfigLighttpd.php';

exit(pmssUserConfigLighttpdMain($argv));
