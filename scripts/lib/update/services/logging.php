<?php
/**
 * Best-effort journald and remote logging configuration facade.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../../runtime.php';
pmssRequireRelativeFiles(__DIR__, ['../managedPath.php', '../runtime/commands.php', '../runtime/processes.php']);

/** Read a logging template and replace placeholders in one pass. */
function pmssRenderLoggingTemplate(
    string $template,
    array $replacements,
    string $missingMessage,
    string $readErrorMessage,
    callable $logger
): ?string {
    if (!is_file($template)) {
        $logger($missingMessage.$template);
        return null;
    }
    if (($raw = pmssReadRegularFileContents($template)) === null) {
        $logger($readErrorMessage.$template);
        return null;
    }
    return strtr($raw, $replacements);
}
pmssRequireRelativeFiles(__DIR__, ['logging/journald.php', 'logging/remote.php']);
