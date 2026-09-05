<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
// Loading the tool only defines its functions — pmssRunCliEntrypointWithArgv's
// realpath(SCRIPT_FILENAME)===scriptPath guard means main() does NOT run on require.
require_once __DIR__.'/../../../util/safeRootCleanup.php';

/**
 * Proves the safety boundary of scripts/util/safeRootCleanup.php: the whitelist gate
 * REFUSES every customer/system/live path and ACCEPTS only regenerable cruft. This
 * tool deletes on production root filesystems, so the boundary is the load-bearing
 * safety property and must be verified before it ships.
 */
class PmssSafeRootCleanupTest extends TestCase
{
    /** The gate must REFUSE customer data, system config, and other never-touch trees. */
    public function testWhitelistRefusesCustomerAndSystemPaths(): void
    {
        $refused = [
            '/home/alice/data/movie.mkv',
            '/home/bob/.rtorrent.rc',
            '/var/www/site/index.php',
            '/var/www/html/10GiB.dat',       // a large SERVED file — needs human judgment, never auto-rm
            '/etc/passwd',
            '/etc/seedbox/config/version',
            '/root/.ssh/id_rsa',
            '/root/sysadmin.agentic.log',
            '/boot/vmlinuz-6.1.0',
            '/var/lib/mysql/ibdata1',
            '/var/spool/cron/crontabs/root',
            '/tmp/whatever',                 // not under any allow prefix
            '/var/cache/other/thing',        // /var/cache but not apt/archives
            '/srv/data',
            'relative/path',                 // not absolute
            '',
        ];
        foreach ($refused as $path) {
            $this->assertFalse(
                pmssSafeRootCleanupPathIsWhitelisted($path),
                "MUST refuse to delete: {$path}"
            );
        }
    }

    /** The gate must REFUSE LIVE (non-rotated) logs — only their rotated siblings go. */
    public function testWhitelistRefusesLiveLogs(): void
    {
        $liveLogs = [
            '/var/log/syslog',
            '/var/log/kern.log',
            '/var/log/messages',
            '/var/log/auth.log',
            '/var/log/daemon.log',
            '/var/log/mail.log',
            '/var/log/nginx/access.log',     // live app log (non-rotated)
            '/var/log/nginx/error.log',
            '/var/log/some-unrecognized-file',
            '/var/log/pmss/system-stats.log', // live observability log, never cruft
        ];
        foreach ($liveLogs as $path) {
            $this->assertFalse(
                pmssSafeRootCleanupPathIsWhitelisted($path),
                "MUST refuse to delete live/non-rotated log: {$path}"
            );
        }
    }

    /** The gate must ACCEPT only genuinely regenerable, rotated/cruft classes. */
    public function testWhitelistAcceptsRegenerableCruft(): void
    {
        $accepted = [
            '/var/log/syslog.1',
            '/var/log/syslog.2.gz',
            '/var/log/kern.log.1',
            '/var/log/kern.log.4.gz',
            '/var/log/nginx/access.log.3.gz',
            '/var/log/apt/history.log.1.gz',
            '/var/log/mail.log.2.gz',
            '/var/log/messages.old',
            '/var/cache/apt/archives/nginx_1.24_amd64.deb',
            '/var/lib/apt/lists/partial/deb.debian.org_dists',
            '/var/log/atop/atop_20260101',
        ];
        foreach ($accepted as $path) {
            $this->assertTrue(
                pmssSafeRootCleanupPathIsWhitelisted($path),
                "should accept regenerable cruft: {$path}"
            );
        }
    }

    /** The rotated-log predicate distinguishes rotations from live logs by name. */
    public function testRotatedLogPredicate(): void
    {
        foreach (['syslog.1', 'syslog.2.gz', 'foo.gz', 'bar.old', 'kern.log.10.gz'] as $name) {
            $this->assertTrue(
                pmssSafeRootCleanupIsRotatedLog('/var/log/'.$name),
                "should be rotated: {$name}"
            );
        }
        foreach (['syslog', 'kern.log', 'messages', 'access.log', 'auth.log'] as $name) {
            $this->assertFalse(
                pmssSafeRootCleanupIsRotatedLog('/var/log/'.$name),
                "must NOT be treated as rotated (live log): {$name}"
            );
        }
    }

    /** deny-prefix wins over the rotated-log accept: a rotated NAME under a denied tree is refused. */
    public function testDenyPrefixBeatsRotatedAccept(): void
    {
        $this->assertFalse(
            pmssSafeRootCleanupPathIsWhitelisted('/var/www/logs/access.log.1.gz'),
            'a rotated-log-looking name under a denied tree (/var/www) must still be refused'
        );
        $this->assertFalse(
            pmssSafeRootCleanupPathIsWhitelisted('/home/user/backup.tar.1.gz'),
            'a rotated-looking name under /home must be refused'
        );
    }
}
