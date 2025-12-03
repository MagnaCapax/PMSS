<?php
/**
 * Shared helpers for user configuration routines.
 */

require_once __DIR__.'/../update/runtime/commands.php';

/**
 * Run a shell command with optional logging while keeping failures non-fatal.
 */
function userRunCommand(string $description, string $command): int
{
    return runStep($description, $command);
}
