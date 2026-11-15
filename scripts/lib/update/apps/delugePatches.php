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

require_once __DIR__.'/delugeWebBasePatch.php';

/** Patch Deluge cache hit ratio handling for libtorrent 2.0+ stats removal. */
function pmssPatchDelugeCacheHitRatio(string $path, bool $dryRun, callable $log): bool
{
    return pmssDelugePatchFile($path, $dryRun, $log, ['core.py', 'cache hit ratio', 'cache ratio'], static function (array &$lines) use ($path, $log): bool {
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

        return true;
    });
}

/** Patch Deluge's custom logger override for Python 3.11+ compatibility. */
function pmssPatchDelugeFindCallerSignature(string $path, bool $dryRun, callable $log): bool
{
    return pmssDelugePatchFile($path, $dryRun, $log, ['log.py', 'findCaller signature', 'findCaller'], static function (array &$lines): bool {
        $searchStart = 0;
        while (($i = pmssDelugeLineSearch($lines, 'def findCaller(', $searchStart)) !== null) {
            if (strpos($lines[$i], 'stacklevel=') !== false) return true;
            if (!preg_match('/^(\s*def\s+findCaller\(\s*self\s*,\s*stack_info\s*=\s*False)\)(.*)$/', $lines[$i], $matches)) {
                $searchStart = $i + 1;
                continue;
            }
            $lines[$i] = $matches[1].', stacklevel=1)'.$matches[2];
            return true;
        }
        return false;
    });
}

/** Patch removed gettext.bind_textdomain_codeset() calls for Python 3.10+. */
function pmssPatchDelugeBindTextdomainCodeset(string $path, bool $dryRun, callable $log): bool
{
    return pmssDelugePatchFile($path, $dryRun, $log, ['i18n util.py', 'gettext codeset guard', 'gettext codeset'], static function (array &$lines) use ($path, $log): bool {
        $line = pmssDelugeLineSearch($lines, 'gettext.bind_textdomain_codeset(');
        if ($line === null) return false;

        $previous = $line > 0 ? trim($lines[$line - 1]) : '';
        if (strpos($previous, "hasattr(gettext, 'bind_textdomain_codeset')") !== false
            || strpos($previous, 'hasattr(gettext, "bind_textdomain_codeset")') !== false
        ) {
            return true;
        }

        if (preg_match('/^(\s*)gettext\.bind_textdomain_codeset\((.*)$/', $lines[$line], $matches) !== 1) {
            $log('[WARN] Unable to locate Deluge bind_textdomain_codeset call in '.$path);
            return false;
        }

        $indent = $matches[1];
        array_splice($lines, $line, 1, [
            $indent."if hasattr(gettext, 'bind_textdomain_codeset'):",
            $indent.'    '.trim($lines[$line]),
        ]);

        return true;
    });
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

/** Run every known Deluge compatibility patch and emit legacy status lines. */
function pmssDelugePatchAll(bool $dryRun, callable $log): void
{
    $storeRoot = getenv('PMSS_TORRENT_CLIENTS_STORE_ROOT') ?: '/home/.pmss/clients';
    // Paths share one ordered system-root list; only WebUI repairs include store venvs.
    $specs = [
        ['core/core.py', 'pmssPatchDelugeCacheHitRatio', "\t*** Deluge cache ratio guard ensured\n", []],
        ['log.py', 'pmssPatchDelugeFindCallerSignature', "\t*** Deluge Python 3.11 findCaller compatibility ensured\n", []],
        ['i18n/util.py', 'pmssPatchDelugeBindTextdomainCodeset', "\t*** Deluge gettext Python 3.11 compatibility ensured\n", []],
        ['ui/web/server.py', 'pmssPatchDelugeWebBasePathBytes', "\t*** Deluge WebUI base path bytes compatibility ensured\n", [$storeRoot.'/deluge/*/lib/python3*/site-packages/deluge/ui/web/server.py']],
    ];
    foreach ($specs as [$file, $patch, $message, $extra]) {
        $patterns = [];
        foreach (['/usr/lib/python3', '/usr/lib/python3*', '/usr/local/lib/python3*'] as $root) $patterns[] = $root.'/dist-packages/deluge/'.$file;
        if (pmssDelugePatchEnsure(array_merge($patterns, $extra), $patch, $dryRun, $log)) echo $message;
    }
}
