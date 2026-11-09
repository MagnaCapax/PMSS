#!/usr/bin/env php
<?php
/** Release ext4 journal holders' read caps on affected kernels. @license GPL-3.0-only */
declare(strict_types=1);

require_once __DIR__.'/../lib/cgroup/journalHolderRelease.php';

pmssCgroupDirectRequireRuntime('INFO: cgroup-v1 blkio absent; journal release not applicable');
// Canonical flock -n equivalent protects manual invocations too (ADR 0049).
$journalHolderLock = pmssCronLockAcquire('cgroupJournalHolderRelease', static function (): int { return 0; });
pmssJournalHolderReleaseCycle(pmssCgroupPolicyLoad(), php_uname('r'));
