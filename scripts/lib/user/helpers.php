<?php
/**
 * Runtime bootstrap for user configuration helpers.
 *
 * Historically this module exposed a `userRunCommand()` wrapper around the
 * shared `runStep()` runner. That alias has been removed so user provisioning
 * helpers share the same command runner name as the updater.
 */

require_once __DIR__.'/../update/runtime/commands.php';
