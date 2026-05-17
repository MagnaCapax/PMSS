#!/usr/bin/env php
<?php
/**
 * CLI entrypoint for the customer panel render harness.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/lib/testing/customerPanelRenderHarness.php';

exit(pmssCustomerPanelRenderMain());
