<?php
/**
 * Base system preparation helpers executed during update-step2.
 *
 * This remains the historical include path for system preparation and loads
 * focused baseline modules. Including this facade never applies host changes.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/../runtime.php';
pmssRequireRelativeFiles(__DIR__, [
    'fstab.php', 'managedPath.php', 'runtime/commands.php', 'runtime/processes.php',
    'systemPrep/hostEnvironment.php', 'systemPrep/bootDefaults.php', 'systemPrep/sysctlTuning.php', 'systemPrep/systemdSlicesEnsure.php', 'systemPrep/dbusPolicyHardening.php', 'systemPrep/renderNodeAccess.php', 'systemPrep/realtekNicAspm.php', 'systemPrep/socketTablePrivacy.php',
    'systemPrep/tempMount.php', 'systemPrep/bootTuning.php', 'systemPrep/locale.php', 'systemPrep/shellDefaults.php',
]);
