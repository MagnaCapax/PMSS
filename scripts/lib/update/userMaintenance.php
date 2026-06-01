<?php
/**
 * User maintenance helpers for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/systemPrep.php';
require_once __DIR__.'/users.php';
require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../users.php';
require_once __DIR__.'/../userLifecycle.php';
require_once __DIR__.'/../user/directories.php';
require_once __DIR__.'/users/docker.php';

    /**
     * Signature a user is "refreshed against". Keyed on the installed PMSS
     * version (advances every update → forces full refresh on a real upgrade)
     * plus the ruTorrent skel SHA (catches skel-only template bumps). Within one
     * update a same-version re-run reuses the signature so completed users skip.
     */
    function pmssUserRefreshSignature(string $rutorrentIndexSha): string
    {
        $version = trim((string) @file_get_contents('/etc/seedbox/config/version'));
        return sha1($version.'|'.$rutorrentIndexSha);
    }

    /** Marker path for a username when the boundary inputs are safe. */
    function pmssUserRefreshMarkerPath(string $user): string
    {
        $dir = pmssResolvePathFromEnv('PMSS_USER_REFRESH_STATE_DIR', '/var/lib/pmss/user-refresh');
        if (!pmssValidateUsername($user) || !pmssPathTargetIsSafe($dir, true, false, false)) {
            return '';
        }

        return $dir.'/'.$user;
    }

    /** True when the user was already fully refreshed against this signature. */
    function pmssUserRefreshAlreadyDone(string $user, string $signature): bool
    {
        $path = pmssUserRefreshMarkerPath($user);
        return $path !== '' && is_file($path) && trim((string) @file_get_contents($path)) === $signature;
    }

    /** Record that the user is fully refreshed against this signature. */
    function pmssUserRefreshMarkDone(string $user, string $signature): void
    {
        $path = pmssUserRefreshMarkerPath($user);
        if ($path === '') {
            $safeUser = (string) preg_replace('/[\r\n\0]+/', '?', $user);
            logMessage('[WARN] Refusing to write unsafe user refresh marker for '.($safeUser === '' ? '(empty)' : $safeUser));
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            logMessage('[WARN] Unable to create user refresh state directory: '.$dir);
            return;
        }
        if (@file_put_contents($path, $signature."\n") === false) {
            logMessage('[WARN] Unable to write user refresh marker: '.$path);
        }
    }

    /**
     * Refresh ruTorrent and skeleton data for every provisioned user.
     *
     * Keep this a simple foreach(users) orchestrator; avoid accumulating
     * extra cross-cutting concerns beyond logging and the legacy CPUQuota
     * fix block.
     */
    function pmssUpdateAllUsers(string $rutorrentIndexSha): array
    {
        $users = users::listHomeUsers();
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        $totalUsers = count($users);
        $processedUsers = 0;
        $skippedUsers = 0;
        // Resume capability (GH#302 point 5): on I/O-saturated hosts a single
        // run can time out mid-queue. We mark each user fully-refreshed against
        // the CURRENT PMSS version; a same-version re-run then skips already-done
        // users and converges on the timed-out tail instead of re-walking every
        // home from scratch. A version change invalidates all markers → full
        // refresh (new skel/permission logic must reach every user). Keyed on
        // the version string + the ruTorrent skel SHA for skel-only bumps.
        $refreshSignature = pmssUserRefreshSignature($rutorrentIndexSha);
        // Capture per-user skip reasons so a partial-completion failure can name
        // WHY users were skipped (e.g. userPermissions timeout) instead of only
        // reporting an opaque N_of_M count. Operators otherwise chase correlated
        // but unrelated host state (see GH#591). Compact list only; detailed
        // traces already go to per-user logs.
        $skipReasons = [];
        logMessage(sprintf('Per-user maintenance: %d user(s) to process', $totalUsers));
        $isTty = pmssStreamIsTty(STDOUT);
        $phases = ['Environment (HTTP/ruTorrent/permissions + linger/systemd/rootless Docker)'];
        $postChecks = [];
        if (is_file('/scripts/util/checkUserHtpasswd.php')) {
            $phases[] = 'Legacy htpasswd sync';
            $postChecks['Synchronizing per-user htpasswd'] = '/scripts/util/checkUserHtpasswd.php';
        }
        if (is_file('/scripts/cron/checkLighttpdInstances.php')) {
            $phases[] = 'Lighttpd instance check';
            $postChecks['Checking lighttpd instance'] = '/scripts/cron/checkLighttpdInstances.php';
        }
        $recordUserProfile = static function (string $user, string $status, int $rc, float $duration, string $stderrExcerpt = ''): void {
            pmssRecordProfile([
                'description'    => 'updateUser '.$user,
                'command'        => '',
                'status'         => $status,
                'rc'             => $rc,
                'duration'       => round($duration, 4),
                'dry_run'        => false,
                'stdout_excerpt' => '',
                'stderr_excerpt' => $stderrExcerpt,
            ]);
        };

        foreach ($users as $user) {
            if (($userTrim = trim($user)) === '') {
                $skippedUsers++;
                $skipReasons[] = '(empty): empty username entry';
                logMessage("[WARN] Account '(empty)' skipped during environment refresh: empty username entry");
                continue;
            }
            if (!pmssValidateUsername($userTrim)) {
                $skippedUsers++;
                $skipReasons[] = $userTrim.': invalid username';
                logMessage(sprintf("[WARN] Account '%s' skipped during environment refresh: invalid username", $userTrim));
                continue;
            }

            // Resume: this user was already fully refreshed against the current
            // PMSS version (e.g. a prior run that timed out further down the
            // queue). Count as processed and skip the expensive home traversal.
            if (pmssUserRefreshAlreadyDone($userTrim, $refreshSignature)) {
                $processedUsers++;
                logMessage(sprintf('User %s already refreshed this version; skipping (resume)', $userTrim));
                continue;
            }

            if ($isTty) {
                echo PHP_EOL."\033[35mUpdating user {$userTrim}\033[0m".PHP_EOL;
                foreach ($phases as $phase) {
                    echo "  \033[33m* {$phase}\033[0m".PHP_EOL;
                }
            } else {
                logMessage(sprintf('Updating user %s phases: %s', $userTrim, implode(', ', $phases)));
            }

            $userStart = microtime(true);

            try {
                // #TODO Remove this fix block by end of 2027.
                // Legacy fix: Detect "CPUQuota=85%" overrides on per-user slices and
                // bump them to a host-based quota derived from total CPU threads so
                // users are no longer capped to 85% of a single core.
                $uinfo = pmssUserAccountLookup($user);
                if ($uinfo !== null) {
                    $uid = (int)$uinfo['uid'];
                    $sliceDir = '/etc/systemd/system/user-'.$uid.'.slice.d';
                    $needsFix = false;
                    if (is_dir($sliceDir)) {
                        $files = glob($sliceDir.'/*.conf') ?: [];
                        foreach ($files as $f) {
                            $content = @file_get_contents($f);
                            if ($content && preg_match('/^CPUQuota\s*=\s*85%$/m', $content)) {
                                $needsFix = true;
                                break;
                            }
                        }
                    }
                    if ($needsFix) {
                        $threads = pmssTotalCpuThreads();
                        $newQuota = ($threads > 0) ? ($threads * 85) : 600;
                        $slice = 'user-'.$uid.'.slice';
                        runStep(
                            "Fixing legacy 85% CPUQuota for {$user}",
                            'systemctl set-property '.escapeshellarg($slice).' CPUQuota='.$newQuota.'%'
                        );
                    }
                }

                pmssUpdateUserEnvironment($userTrim, $rutorrentIndexSha);
                pmssEnsureLingerAndDocker($userTrim);

                foreach ($postChecks as $label => $helperPath) {
                    $rc = runUserStep($userTrim, $label, pmssBuildCommand($helperPath, [$userTrim]));
                    if ($rc !== 0) {
                        pmssUserLog($userTrim, sprintf('[WARN] %s failed (rc=%d)', $label, $rc));
                    }
                }

                $userDuration = microtime(true) - $userStart;
                pmssUserLog($userTrim, sprintf('update-step2: user maintenance finished (%.2fs)', $userDuration));
                $recordUserProfile($userTrim, 'OK', 0, $userDuration);
                // Mark fully-refreshed against this version so a same-version
                // re-run (after a timeout further down the queue) skips this user.
                pmssUserRefreshMarkDone($userTrim, $refreshSignature);
                $processedUsers++;
            } catch (\Throwable $throwable) {
                $skippedUsers++;
                $userDuration = microtime(true) - $userStart;
                $reason = get_class($throwable).($throwable->getMessage() === '' ? '' : ': '.$throwable->getMessage());
                $skipReasons[] = $userTrim.': '.$reason;

                logMessage(sprintf("[WARN] Account '%s' skipped during environment refresh: %s", $userTrim, $reason));
                pmssUserLog($userTrim, '[WARN] update-step2 user maintenance aborted: '.$reason);

                $recordUserProfile($userTrim, 'ERR', 1, $userDuration, substr(preg_replace('/\s+/', ' ', $reason), 0, 300));
            }
        }

        $summaryStatus = $processedUsers < $totalUsers ? 'warn' : 'ok';
        logMessage(sprintf('%sProcessed %d of %d users', $summaryStatus === 'warn' ? '[WARN] ' : '', $processedUsers, $totalUsers));
        pmssLogJson([
            'event'     => 'user_maintenance_summary',
            'status'    => $summaryStatus,
            'total'     => $totalUsers,
            'processed' => $processedUsers,
            'skipped'   => $skippedUsers,
            'skip_reasons' => $skipReasons,
        ]);

        return [
            'total' => $totalUsers,
            'processed' => $processedUsers,
            'skipped' => $skippedUsers,
            'skip_reasons' => $skipReasons,
        ];
    }
