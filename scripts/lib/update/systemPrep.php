<?php
/**
 * Base system preparation helpers executed during update-step2.
 *
 * This file stays intentionally small: it wires together the cohesive helpers
 * under update/systemPrep/ while preserving the historical include path.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/../runtime.php';

require_once __DIR__.'/systemPrep/cgroupModeDetect.php';
require_once __DIR__.'/systemPrep/hostResourcesDetect.php';
require_once __DIR__.'/systemPrep/cgroupsEnsureConfigured.php';
require_once __DIR__.'/systemPrep/systemdSlicesDropinInstall.php';
require_once __DIR__.'/systemPrep/systemdSlicesRuntimeApply.php';
require_once __DIR__.'/systemPrep/systemdSlicesEnsure.php';
require_once __DIR__.'/systemPrep/localeBaselineEnsure.php';
require_once __DIR__.'/systemPrep/sysctlBaselineEnsure.php';
require_once __DIR__.'/systemPrep/rootShellDefaultsConfigure.php';

