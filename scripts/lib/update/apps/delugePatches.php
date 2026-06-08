<?php
/**
 * Deluge upstream compatibility patches.
 *
 * These helpers patch installed Deluge Python files in place while preserving
 * the installer entrypoint's dry-run and fail-soft contracts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Read a candidate patch target while refusing symlinked or unreadable paths. */
function pmssDelugeReadPatchLines(string $path, callable $log, string $readWarning): ?array
{
    if (!is_file($path) || is_link($path) || !is_readable($path)) return null;
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) { $log($readWarning.$path); return null; }
    return $lines;
}

/** Search a bounded line window without open-coding patch-specific loops. */
function pmssDelugeLineSearch(array $lines, string $needle, int $start = 0, ?int $end = null, int $step = 1, bool $regex = false): ?int
{
    if ($lines === [] || $step === 0) return null;
    $lastIndex = count($lines) - 1;
    $start = max(0, min($start, $lastIndex));
    $end = $end === null ? ($step > 0 ? $lastIndex : 0) : max(0, min($end, $lastIndex));

    for ($i = $start; $step > 0 ? $i <= $end : $i >= $end; $i += $step) {
        $matched = $regex ? preg_match($needle, $lines[$i]) === 1 : strpos($lines[$i], $needle) !== false;
        if ($matched) return $i;
    }
    return null;
}

/** Persist patched lines with the same newline and dry-run contract. */
function pmssDelugeWritePatchedLines(string $path, array $lines, bool $dryRun, callable $log, string $dryRunMessage, string $writeWarning): bool
{
    $newContent = implode("\n", $lines);
    if ($newContent !== '' && substr($newContent, -1) !== "\n") $newContent .= "\n";
    if ($dryRun) { $log($dryRunMessage.$path); return true; }
    if (@file_put_contents($path, $newContent) === false) { $log($writeWarning.$path); return false; }
    return true;
}

/** Patch Deluge cache hit ratio handling for libtorrent 2.0+ stats removal. */
function pmssPatchDelugeCacheHitRatio(string $path, bool $dryRun, callable $log): bool
{
    $lines = pmssDelugeReadPatchLines($path, $log, '[WARN] Unable to read Deluge core.py for patching: ');
    if ($lines === null) return false;

    $lineCount = count($lines);
    $hitLine = pmssDelugeLineSearch($lines, 'disk.num_blocks_cache_hits');
    if ($hitLine === null) return false;
    if (pmssDelugeLineSearch($lines, 'except KeyError', max(0, $hitLine - 6), min($lineCount - 1, $hitLine + 6)) !== null) return true;

    $ifLine = pmssDelugeLineSearch($lines, '/^\\s*if blocks_read:\\s*$/', $hitLine, 0, -1, true);
    if ($ifLine === null) { $log('[WARN] Unable to locate Deluge cache ratio block in '.$path); return false; }
    $elseLine = pmssDelugeLineSearch($lines, '/^\\s*else:\\s*$/', $hitLine, min($lineCount - 1, $ifLine + 19), 1, true);
    if ($elseLine === null) { $log('[WARN] Unable to locate Deluge cache ratio else block in '.$path); return false; }

    $assignLine = pmssDelugeLineSearch($lines, "self.session_status['read_hit_ratio']", $ifLine + 1, $elseLine - 1);
    $calcLine = pmssDelugeLineSearch($lines, 'disk.num_blocks_cache_hits', $ifLine + 1, $elseLine - 1);
    if ($assignLine === null || $calcLine === null) { $log('[WARN] Unable to locate Deluge cache ratio assignment in '.$path); return false; }

    $elseAssignLine = pmssDelugeLineSearch($lines, "self.session_status['read_hit_ratio']", $elseLine + 1, min($lineCount - 1, $elseLine + 5));
    if ($elseAssignLine === null) { $log('[WARN] Unable to locate Deluge cache ratio else assignment in '.$path); return false; }

    $ifIndent = preg_replace('/^(\\s*).*$/', '$1', $lines[$ifLine]);
    $assignIndent = preg_replace('/^(\\s*).*$/', '$1', $lines[$assignLine]);
    $calcIndent = preg_replace('/^(\\s*).*$/', '$1', $lines[$calcLine]);
    $indentUnit = (strpos($calcIndent, $assignIndent) === 0) ? substr($calcIndent, strlen($assignIndent)) : '';
    if ($indentUnit === '' && strpos($assignIndent, $ifIndent) === 0) $indentUnit = substr($assignIndent, strlen($ifIndent));
    if ($indentUnit === '') $indentUnit = '    ';

    array_splice($lines, $ifLine, $elseAssignLine - $ifLine + 1, [
        $ifIndent.'if blocks_read:',
        $assignIndent.'try:',
        $assignIndent.$indentUnit."self.session_status['read_hit_ratio'] = (",
        $assignIndent.$indentUnit.$indentUnit."self.session_status['disk.num_blocks_cache_hits'] / blocks_read",
        $assignIndent.$indentUnit.')',
        $assignIndent.'except KeyError:',
        $assignIndent.$indentUnit."self.session_status['read_hit_ratio'] = 0.0",
        $ifIndent.'else:',
        $assignIndent."self.session_status['read_hit_ratio'] = 0.0",
    ]);

    return pmssDelugeWritePatchedLines($path, $lines, $dryRun, $log, '[DRYRUN] Would patch Deluge cache hit ratio in ', '[WARN] Failed to write Deluge cache ratio patch to ');
}

/** Patch Deluge's custom logger override for Python 3.11+ compatibility. */
function pmssPatchDelugeFindCallerSignature(string $path, bool $dryRun, callable $log): bool
{
    $lines = pmssDelugeReadPatchLines($path, $log, '[WARN] Unable to read Deluge log.py for patching: ');
    if ($lines === null) return false;

    $searchStart = 0;
    while (($i = pmssDelugeLineSearch($lines, 'def findCaller(', $searchStart)) !== null) {
        if (strpos($lines[$i], 'stacklevel=') !== false) return true;
        if (!preg_match('/^(\s*def\s+findCaller\(\s*self\s*,\s*stack_info\s*=\s*False)\)(.*)$/', $lines[$i], $matches)) {
            $searchStart = $i + 1;
            continue;
        }
        $lines[$i] = $matches[1].', stacklevel=1)'.$matches[2];
        return pmssDelugeWritePatchedLines($path, $lines, $dryRun, $log, '[DRYRUN] Would patch Deluge findCaller signature in ', '[WARN] Failed to write Deluge findCaller patch to ');
    }
    return false;
}

/** Apply one Deluge patch callback across unique filesystem candidates. */
function pmssDelugePatchEnsure(array $patterns, callable $patch, bool $dryRun, callable $log): bool
{
    $patched = false;
    $seen = [];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $path) {
            if ($path === '' || isset($seen[$path])) continue;
            $seen[$path] = true;
            if ($patch($path, $dryRun, $log)) $patched = true;
        }
    }
    return $patched;
}

/** @return array<int,array{patterns:array<int,string>,patch:callable,message:string}> */
function pmssDelugePatchSpecs(): array
{
    return [
        [
            'patterns' => ['/usr/lib/python3/dist-packages/deluge/core/core.py', '/usr/lib/python3*/dist-packages/deluge/core/core.py', '/usr/local/lib/python3*/dist-packages/deluge/core/core.py'],
            'patch' => 'pmssPatchDelugeCacheHitRatio',
            'message' => "\t*** Deluge cache ratio guard ensured\n",
        ],
        [
            'patterns' => ['/usr/lib/python3/dist-packages/deluge/log.py', '/usr/lib/python3*/dist-packages/deluge/log.py', '/usr/local/lib/python3*/dist-packages/deluge/log.py'],
            'patch' => 'pmssPatchDelugeFindCallerSignature',
            'message' => "\t*** Deluge Python 3.11 findCaller compatibility ensured\n",
        ],
    ];
}

/** Run every known Deluge compatibility patch and emit legacy status lines. */
function pmssDelugePatchAll(bool $dryRun, callable $log): void
{
    foreach (pmssDelugePatchSpecs() as $spec) {
        if (pmssDelugePatchEnsure($spec['patterns'], $spec['patch'], $dryRun, $log)) echo $spec['message'];
    }
}
