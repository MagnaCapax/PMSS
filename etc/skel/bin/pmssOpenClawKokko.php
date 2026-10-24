<?php
/** Optional account-local PM skills: verified staging, atomic selection and additive configuration. */
require_once __DIR__.'/pmssOpenClawKokkoArchive.php';

/** Append our skills directory once, or remove only our exact entry on opt-out/uninstall. */
function ocKokkoExtraDirs(array $paths, bool $enable): bool
{
    $config = ocConfigRead($paths);
    $dirs = $config['skills']['load']['extraDirs'] ?? array();
    if (!is_array($dirs) || array_values($dirs) !== $dirs) throw new RuntimeException('Invalid skill directories');
    $ours = $paths['state'].'/pmss-kokko/skills';
    $next = $enable ? $dirs : array_values(array_filter($dirs, static function ($dir) use ($ours): bool { return $dir !== $ours; }));
    if ($enable && !in_array($ours, $dirs, true)) $next[] = $ours;
    if ($dirs === $next) return false;
    list($rc) = ocCli($paths, array('config', 'set', 'skills.load.extraDirs', json_encode($next)), ocGatewayEnv($paths));
    if ($rc !== 0) throw new RuntimeException('Could not configure skill directories');
    return true;
}

/** Refresh main without making availability of an optional download a gateway dependency. */
function ocKokkoApply(array $paths, bool $uninstall = false, ?callable $download = null): void
{
    $work = null;
    $old = umask(0077);
    try {
        if (is_link($paths['state']) || !is_dir($paths['state']) || fileowner($paths['state']) !== posix_geteuid()) {
            throw new RuntimeException('Unsafe skill state directory');
        }
        $live = $paths['state'].'/pmss-kokko';
        $disabled = $paths['state'].'/pmss-kokko-disabled';
        if ($uninstall || file_exists($disabled) || is_link($disabled)) {
            ocKokkoExtraDirs($paths, false);
            if ($uninstall) {
                if (is_link($live) && preg_match('/^pmss-kokko-(?:v[0-9]+\.[0-9]+\.[0-9]+|[a-f0-9]{12})$/D', readlink($live))
                    && ocKokkoManaged(dirname($live).'/'.readlink($live))) unlink($live);
                ocKokkoCleanup($paths, null);
            }
            return;
        }
        // A user-created directory or link is not ours to replace.
        if (file_exists($live) || is_link($live)) {
            $selected = is_link($live) ? readlink($live) : '';
            if (!preg_match('/^pmss-kokko-(?:v[0-9]+\.[0-9]+\.[0-9]+|[a-f0-9]{12})$/D', $selected)
                || !ocKokkoManaged($paths['state'].'/'.$selected)) throw new RuntimeException('Unmanaged skill path');
        }
        // The downloaded bytes identify the version; identical archives reuse the completed tree.
        $candidate = $paths['home'].'/.openclaw-kokko-'.bin2hex(random_bytes(8));
        if (!mkdir($candidate, 0700) || !chmod($candidate, 0700)) throw new RuntimeException('Could not stage skills');
        $work = $candidate;
        $archive = $work.'/kokko.tar.gz';
        $download = $download ?? 'ocKokkoDownload';
        if (!$download('https://github.com/MagnaCapax/mcxKokko/archive/refs/heads/main.tar.gz', $archive)
            || is_link($archive) || !is_file($archive)) throw new RuntimeException('Skill download failed');
        $sha = hash_file('sha256', $archive);
        if ($sha === false) throw new RuntimeException('Could not hash skill archive');
        $target = $live.'-'.substr($sha, 0, 12);
        $marker = "main\n".$sha."\n";
        if (!ocKokkoManaged($target)) {
            if (file_exists($target) || is_link($target)) throw new RuntimeException('Unmanaged skill version');
            ocKokkoArchiveValidate($archive);
            mkdir($work.'/pack', 0700);
            $env = getenv();
            unset($env['TAR_OPTIONS']);
            list($rc) = ocRun(array('tar', '-xzf', $archive, '--strip-components=1', '--no-same-owner',
                '--no-same-permissions', '--delay-directory-restore', '-C', $work.'/pack'), $env);
            if ($rc !== 0 || !is_dir($work.'/pack/skills')) throw new RuntimeException('Skill extraction failed');
            ocPrivateMarkerWrite($work.'/pack/.pmss-kokko', $marker);
            if (!rename($work.'/pack', $target)) throw new RuntimeException('Could not publish skill version');
        }
        if ((string) file_get_contents($target.'/.pmss-kokko') !== $marker
            || !is_dir($target.'/skills')) throw new RuntimeException('Incomplete skill version');
        if (!is_link($live) || readlink($live) !== basename($target)) {
            $next = $paths['state'].'/.pmss-kokko-link-'.bin2hex(random_bytes(8));
            if (!symlink(basename($target), $next)) throw new RuntimeException('Could not select skills');
            if (!rename($next, $live)) {
                unlink($next);
                throw new RuntimeException('Could not select skills');
            }
        }
        ocKokkoExtraDirs($paths, true);
        ocKokkoCleanup($paths, $target);
    } catch (Throwable $error) {
        // Do not print downloader/CLI exceptions: those may include service secrets.
        fwrite(STDERR, "PM skills: optional pack could not be reconciled; continuing.\n");
    } finally {
        if ($work !== null) ocRemoveTree($work);
        umask($old);
    }
}

/** Status is read-only and reports the selected pack, including legacy release selections. */
function ocKokkoStatus(array $paths): string
{
    $disabled = $paths['state'].'/pmss-kokko-disabled';
    if (file_exists($disabled) || is_link($disabled)) return 'disabled';
    $live = $paths['state'].'/pmss-kokko';
    $name = is_link($live) ? readlink($live) : '';
    if (preg_match('/^pmss-kokko-(v[0-9]+\.[0-9]+\.[0-9]+|[a-f0-9]{12})$/D', $name, $match)
        && ocKokkoManaged($paths['state'].'/'.$name)) return $match[1][0] === 'v' ? $match[1] : 'main '.$match[1];
    return 'absent';
}
