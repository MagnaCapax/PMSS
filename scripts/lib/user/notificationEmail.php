<?php
/**
 * Per-user notification email parsing.
 *
 * The recipient is read only from the root store (UserConfigStore::readNotificationEmail,
 * ADR 0213); no file under the home is read. No provisioning CLI records an address on the node.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

/** Normalize one syntactically valid email address from provisioned text. */
function pmssUserNotificationEmailParse(string $raw): ?string
{
    $email = trim($raw, " \t\n\r");
    if ($email === ''
        || strlen($email) > 254
        || preg_match('/[\r\n\0\s]/', $email) === 1
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        return null;
    }

    return $email;
}
