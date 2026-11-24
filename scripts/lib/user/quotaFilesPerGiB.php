<?php
/**
 * Per-host file (inode) quota ratio shared by enforcement and display (ADR 0342).
 *
 * `/etc/seedbox/config/quotaFilesPerGiB` holds one positive integer: the files an account may
 * hold per GiB of disk quota. A missing, empty, malformed, zero, negative or oversize value means
 * the historical 500, so a host without the file behaves exactly as before. The soft limit keeps
 * its 15000 floor and the hard limit stays 1.25 times the soft limit.
 *
 * Both `userApplyDiskQuota()` (enforced limit) and `pmssUserResourcesListQuotaState()` (displayed
 * limit) call `pmssQuotaFilesLimit()`, so the two cannot drift.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';

/** The ratio every host used before the per-host file existed. */
const PMSS_QUOTA_FILES_PER_GIB_DEFAULT = 500;
/** ext4 allows no more than one inode per 1024 bytes, which is 2^20 inodes per GiB. */
const PMSS_QUOTA_FILES_PER_GIB_MAX = 1048576;
/** Smallest soft file limit of any account. */
const PMSS_QUOTA_FILES_FLOOR = 15000;

/** Path of the per-host ratio file; PMSS_CONFIG_DIR is the existing config-directory override. */
function pmssQuotaFilesPerGiBPath(): string
{
    return pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config').'/quotaFilesPerGiB';
}

/** Accept only a plain positive decimal no larger than the filesystem can carry; anything else is null. */
function pmssQuotaFilesPerGiBParse(?string $raw): ?int
{
    $raw = trim((string) $raw);
    // Seven digits cover the maximum; the pattern keeps signs, spaces, zero and suffixes out.
    if (preg_match('/^[1-9][0-9]{0,6}$/D', $raw) !== 1) {
        return null;
    }
    $value = (int) $raw;
    return $value <= PMSS_QUOTA_FILES_PER_GIB_MAX ? $value : null;
}

/** Effective files-per-GiB ratio of this host: the file's value, or 500 when it is unusable. */
function pmssQuotaFilesPerGiB(): int
{
    // Bounded regular-file read: a symlink, directory or oversize file reads as absent.
    $value = pmssQuotaFilesPerGiBParse(pmssReadRegularFileContentsVerified(pmssQuotaFilesPerGiBPath(), null, 64));
    return $value ?? PMSS_QUOTA_FILES_PER_GIB_DEFAULT;
}

/**
 * Soft and hard file limits for an account with a $quotaGiB disk quota.
 *
 * @param int|null $perGiB Ratio override; null reads this host's ratio.
 * @return array{soft:int,hard:int}
 */
function pmssQuotaFilesLimit(int $quotaGiB, ?int $perGiB = null): array
{
    $soft = max($quotaGiB * ($perGiB ?? pmssQuotaFilesPerGiB()), PMSS_QUOTA_FILES_FLOOR);
    return ['soft' => $soft, 'hard' => (int) floor($soft * 1.25)];
}
