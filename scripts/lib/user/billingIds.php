<?php
/**
 * Per-user billing identity readers.
 *
 * Billing service IDs are read from the new self-describing file name first
 * and fall back to the legacy .billingId file during the migration window.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

/** Read a positive digit-only identifier without following symlinks. */
function pmssUserBillingDigitsFileRead(string $path, bool $requireRootOwner = false): ?string
{
    if (!pmssRegularFilePathIsReadable($path)) {
        return null;
    }
    if ($requireRootOwner && @fileowner($path) !== 0) {
        return null;
    }

    $raw = pmssReadRegularFileDigits($path);
    return ($raw !== null && (int) $raw > 0) ? $raw : null;
}

/** Accept only the billing dot-file basenames this helper is meant to probe. */
function pmssUserBillingFileNameIsSafe(string $fileName): bool
{
    return preg_match('/^\.[A-Za-z0-9][A-Za-z0-9._-]*$/D', $fileName) === 1;
}

/** Read the first positive billing identifier from an ordered file list. */
function pmssUserBillingDigitsRead(string $home, array $fileNames, bool $requireRootOwner = false): ?string
{
    $home = rtrim($home, '/');
    if ($home === '') {
        return null;
    }

    foreach ($fileNames as $fileName) {
        $fileName = (string) $fileName;
        if (!pmssUserBillingFileNameIsSafe($fileName)) {
            continue;
        }

        $raw = pmssUserBillingDigitsFileRead($home.'/'.$fileName, $requireRootOwner);
        if ($raw !== null) {
            return $raw;
        }
    }

    return null;
}

/** Read the billing platform service ID, falling back to the legacy file name. */
function pmssUserBillingServiceIdDigitsRead(string $home, bool $requireRootOwner = false): ?string
{
    return pmssUserBillingDigitsRead($home, ['.billingServiceId', '.billingId'], $requireRootOwner);
}

/** Read the billing platform client/account ID when provisioned. */
function pmssUserBillingClientIdDigitsRead(string $home, bool $requireRootOwner = false): ?string
{
    return pmssUserBillingDigitsRead($home, ['.billingClientId'], $requireRootOwner);
}

/** Read the billing platform service ID as an integer. */
function pmssUserBillingServiceIdRead(string $home, bool $requireRootOwner = false): int
{
    $raw = pmssUserBillingServiceIdDigitsRead($home, $requireRootOwner);
    return $raw === null ? 0 : (int) $raw;
}

/** Read the billing platform client/account ID as an integer. */
function pmssUserBillingClientIdRead(string $home, bool $requireRootOwner = false): int
{
    $raw = pmssUserBillingClientIdDigitsRead($home, $requireRootOwner);
    return $raw === null ? 0 : (int) $raw;
}
