<?php
/**
 * Account-owned, inode-pinned recovery destination for user transfers (ADR 0092).
 *
 * @license GPL-3.0-only
 */

/** Reject recovery names before callers perform any I/O. */
function pmssUserTransferValidateInto($value): string
{
    if (!is_string($value) || !preg_match('/\A[A-Za-z0-9._-]+\z/', $value)
        || $value === '.' || $value === '..') {
        throw new RuntimeException('Invalid --into DIR; expected one component [A-Za-z0-9._-]+, excluding . and ..', 1);
    }
    return $value;
}

/** Pin the directory as the account before executing rsync in either receiver mapping. */
function pmssUserTransferBuildIntoReceiver(array $cfg, string $rsync): string
{
    $into = pmssUserTransferValidateInto($cfg['into']);
    // Perl's directory handles provide O_NOFOLLOW and fchdir on the PHP 7.3 baseline.
    // The account namespace maps the account owner to UID 0; no host-root work runs here.
    $script = <<<'PERL'
use strict;
use warnings;
my ($home, $dir) = splice @ARGV, 0, 2;
sub refuse { die "Unsafe --into destination: expected a real directory owned by the account\n"; }
sysopen(my $home_fd, $home, O_RDONLY | O_DIRECTORY | O_NOFOLLOW) or refuse();
chdir($home_fd) or refuse();
my @entry = lstat($dir);
if (!@entry) {
    mkdir($dir, 0700) or refuse();
}
sysopen(my $dir_fd, $dir, O_RDONLY | O_DIRECTORY | O_NOFOLLOW) or refuse();
my @opened = stat($dir_fd);
@entry = lstat($dir);
@opened && @entry && S_ISDIR($entry[2]) && $opened[4] == $<
    && $opened[0] == $entry[0] && $opened[1] == $entry[1] or refuse();
chdir($dir_fd) or refuse();
# Use the pinned working directory even if the account replaces its original name.
$ARGV[-1] = '.';
exec @ARGV or die "Cannot execute recovery receiver: $!\n";
PERL;
    return 'perl -MFcntl=O_RDONLY,O_DIRECTORY,O_NOFOLLOW,S_ISDIR -e '.escapeshellarg($script)
        .' -- '.escapeshellarg($cfg['receiver']['home']).' '.escapeshellarg($into).' '.$rsync;
}

/** A migration hands services over (Eternals holds, Docker stop/start); a recovery into a live account never does. */
function pmssUserTransferRunsHandOver(array $cfg): bool
{
    return !isset($cfg['into']);
}
