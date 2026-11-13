<?php
/**
 * Shared additional-disk display parsing and publication for account CLIs.
 *
 * Keep the existing helper names available through the addUser includes.
 * This figure is already included in the enforced quota; it is display only.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/identity.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';

/** Parse the optional additional disk quota without accepting coercible values. */
function pmssAddUserBonusQuotaGiBParse($raw): ?int
{
    if ($raw === null) {
        return null;
    }
    if (!is_string($raw) || preg_match('/^(0|[1-9][0-9]*)$/D', $raw) !== 1) {
        throw new InvalidArgumentException('Invalid --bonus-quota-gib value');
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($value === false) {
        throw new InvalidArgumentException('Invalid --bonus-quota-gib value');
    }
    return (int) $value;
}

/** Publish positive additional quota; omission and zero preserve existing display state. */
function pmssAddUserBonusQuotaPersist(array $user, string $homePath): bool
{
    if (!array_key_exists('bonusQuotaGiB', $user)) {
        return true;
    }
    if (!is_int($user['bonusQuotaGiB']) || $user['bonusQuotaGiB'] < 0) {
        return false;
    }
    if ($user['bonusQuotaGiB'] === 0) {
        return true;
    }
    if (!isset($user['name']) || !is_string($user['name']) || !pmssValidateUsername($user['name'])) {
        return false;
    }

    return pmssReplaceAccountFileWithLegacyFallback(
        $user['name'],
        rtrim($homePath, '/'),
        rtrim($homePath, '/').'/.bonusQuota',
        (string) $user['bonusQuotaGiB'],
        0640
    );
}
