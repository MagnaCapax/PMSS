#!/usr/bin/env php
<?php
/**
 * Catch customer PHP calls to functions unavailable inside the customer tree.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/lib/testing/customerContextFatalScan.php';

exit(pmssCustomerContextFatalScanMain($argv));
