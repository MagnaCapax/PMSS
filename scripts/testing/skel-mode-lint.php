#!/usr/bin/env php
<?php
/**
 * skel-mode-lint — content-aware, shebang/ELF-detecting committed-mode lint for the
 * provisioning tree (etc/skel + etc/seedbox/skel).
 *
 * Rule: a file's committed git exec bit must be set IFF the file is genuinely
 * executable — its first bytes are a shebang (`#!`) OR it is an ELF binary. Git can
 * store only 100644 (data) or 100755 (executable), so this is the complete committed-
 * mode policy. Everything finer (0600 secrets, 0750 homes) is deploy-time and owned by
 * setupPermissions.php — out of scope here.
 *
 * Catches BOTH directions in one pass:
 *   - excess exec (GH #781): a non-executable data file committed 100755  -> should be 644
 *   - missing exec (GH #779): a shebang/ELF launcher committed 100644     -> should be 755
 *
 * Supersedes exec-bit-lint.sh, which inspected only first-party PHP and therefore
 * missed both classes. Root cause of the excess-exec class: commit 73593f01
 * ("File permission updates", 2023-05-11) bulk-flipped 96% of the repo 644->755.
 * See docs/adr/0043-provisioning-tree-file-permissions-committed-modes-and-deploy-time-policy.md
 *
 * Usage:
 *   php scripts/testing/skel-mode-lint.php [--report] [--fix] [--root PATH ...]
 *     --report  (default) list violations, exit 1 if any, make no changes
 *     --fix     chmod worktree files to policy (0644/0755) and `git add` them
 *     --root    limit to one or more roots (default: etc/skel etc/seedbox/skel)
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

$apply = in_array('--fix', $argv, true);
$roots = [];
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--root' && isset($argv[$i + 1])) { $roots[] = rtrim($argv[++$i], '/'); }
}
if (!$roots) { $roots = ['etc/skel', 'etc/seedbox/skel']; }

$repoRoot = realpath(__DIR__ . '/../..');
if ($repoRoot === false) { fwrite(STDERR, "cannot resolve repo root\n"); exit(2); }
chdir($repoRoot);

/** True if the blob's leading bytes mark a genuine executable (shebang or ELF). */
function isExecutableContent(string $blob): bool {
    return strncmp($blob, "#!", 2) === 0 || strncmp($blob, "\x7fELF", 4) === 0;
}

/** Enumerate tracked files under a root as [mode, sha, path], NUL-safe for non-ASCII. */
function trackedEntries(string $root): array {
    // core.quotePath=false + NUL termination => literal UTF-8 paths, never octal-escaped.
    $raw = shell_exec('git -c core.quotePath=false ls-files -s -z -- ' . escapeshellarg($root));
    $entries = [];
    if ($raw === null || $raw === '') { return $entries; }
    foreach (explode("\0", $raw) as $rec) {
        if ($rec === '') { continue; }
        // "<mode> <sha> <stage>\t<path>"
        if (!preg_match('/^(\d{6}) ([0-9a-f]+) \d+\t(.*)$/s', $rec, $m)) { continue; }
        $entries[] = [$m[1], $m[2], $m[3]];
    }
    return $entries;
}

$excess = []; $missing = [];
foreach ($roots as $root) {
    if (!is_dir($root)) { continue; }
    foreach (trackedEntries($root) as [$mode, $sha, $path]) {
        if ($mode === '120000') { continue; } // symlink
        $blob = shell_exec('git cat-file blob ' . escapeshellarg($sha) . ' 2>/dev/null | head -c 4');
        $shouldExec = isExecutableContent($blob === null ? '' : $blob);
        if ($mode === '100755' && !$shouldExec) { $excess[] = $path; }
        elseif ($mode === '100644' && $shouldExec) { $missing[] = $path; }
    }
}

printf("skel-mode-lint: roots=%s\n", implode(' ', $roots));
printf("  excess-exec (100755 data, should be 644, #781): %d\n", count($excess));
printf("  missing-exec (100644 launcher, should be 755, #779): %d\n", count($missing));

if (!$apply) {
    foreach (['excess (->644)' => $excess, 'missing (->755)' => $missing] as $label => $list) {
        $n = 0;
        foreach ($list as $p) { echo "    [$label] $p\n"; if (++$n >= 40) { echo "    ... (" . (count($list) - 40) . " more)\n"; break; } }
    }
    $total = count($excess) + count($missing);
    if ($total > 0) { fwrite(STDERR, "FAIL: $total mode violations (run with --fix to normalize)\n"); exit(1); }
    echo "PASS: all committed modes match content.\n";
    exit(0);
}

// --fix: chmod the real worktree file, then stage. PHP chmod() takes a literal path,
// so non-ASCII filenames need no shell quoting.
$stripped = 0; $added = 0; $failed = 0;
foreach ($excess as $p)  { if (@chmod($repoRoot . '/' . $p, 0644)) { $stripped++; } else { $failed++; fwrite(STDERR, "chmod 644 failed: $p\n"); } }
foreach ($missing as $p) { if (@chmod($repoRoot . '/' . $p, 0755)) { $added++;   } else { $failed++; fwrite(STDERR, "chmod 755 failed: $p\n"); } }
// Stage the mode changes (git add reads the worktree exec bit); NUL-safe add.
foreach ($roots as $root) {
    if (is_dir($root)) { exec('git add -- ' . escapeshellarg($root)); }
}
printf("FIXED: %d data files -> 644, %d launchers -> 755, %d failed. Staged. Re-run --report to confirm PASS.\n", $stripped, $added, $failed);
exit($failed > 0 ? 1 : 0);
