<?php
/**
 * Per-user notification email parsing.
 *
 * The billing system provisions the recipient through writeHomeMarker.php .notifyEmail; it is
 * read from the root store (UserConfigStore::readNotificationEmail). ~/.notifyEmail is its
 * display copy.
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
