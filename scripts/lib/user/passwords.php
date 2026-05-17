<?php
/**
 * Legacy password-helper compatibility bridge.
 *
 * Deluge service password helpers now live in delugePasswords.php.
 * Keep this file lightweight so user-facing and operator-side concerns stay
 * separated by module rather than accumulating in one mixed helper file.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/delugePasswords.php';
