#!/usr/bin/php
<?php
/**
 * PMSS Static Updater  –  safe copy‑based edition (Debian‑live friendly)
 *
 *   /scripts/update.php [<source-spec>] [--scriptonly] [--verbose] [--help]
 *
 *   source-spec (defaults to /etc/seedbox/config/version):
 *     release                  – latest GitHub release
 *     release:2025-07-12       – explicit tag
 *     git/main                 – branch “main” from default repo
 *     git/dev:2024-12-05       – branch “dev” pinned to past date
 *     git/https://url/repo.git:beta[:2025-01-01] – custom repo
 *
 * Exit codes: 0 OK · 11 parse · 12 download · 13 verify · 14 deploy
 */
declare(strict_types=1);

/* ───── PHP 7 polyfills ───── */
if (!function_exists('str_contains')) {
    function str_contains(string $hay, string $nee): bool {
        return $nee === '' || strpos($hay, $nee) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $hay, string $nee): bool {
        return $nee !== '' && strpos($hay, $nee) === 0;
    }
}

/* ───── quick help ───── */
if (in_array('--help', $argv, true)) {
    echo "Usage: {$argv[0]} [<source-spec>] [--scriptonly] [--verbose]\n";
    echo "Run with --verbose for debug output. See script header for examples.\n";
    exit(0);
}

/* ───── constants ───── */
const VERSION_FILE = '/etc/seedbox/config/version';
const LOG_FILE     = '/var/log/pmss-update.log';
const FALLBACK_LOG = '/tmp/pmss-update.log';

require_once __DIR__.'/lib/logger.php';
$logger = new Logger(__FILE__);
$GLOBALS['logger'] = $logger;

const DEFAULT_REPO = 'https://github.com/MagnaCapax/PMSS';
const CURL_UA      = 'PMSS-Updater (+https://pulsedmedia.com)';
const RETRIES      = 3;

const EXIT_PARSE  = 11;
const EXIT_DL     = 12;
const EXIT_VERIFY = 13;
const EXIT_DEPLOY = 14;

/* ───── runtime flags ───── */
$verbose    = in_array('--verbose',   $argv, true);
$scriptonly = in_array('--scriptonly', $argv, true);

/* ───── logging helpers ───── */
function fatal(string $m, int $c): never { $GLOBALS['logger']->msg("[FATAL] $m"); exit($c); }

/* ───── shell helpers ───── */
function sh(string $cmd, bool $v=false): void {
    if ($v) fwrite(STDERR, "[CMD] $cmd\n");
    passthru($cmd, $rc);
    if ($rc !== 0) fatal("cmd failed: $cmd", EXIT_DL);
}
function sh_retry(string $cmd, bool $v=false, int $max=RETRIES): void {
    for ($a=1;$a<=$max;$a++) {
        if ($v) fwrite(STDERR,"[CMD] $cmd (try $a)\n");
        passthru($cmd, $rc);
        if ($rc === 0) return;
        if ($a === $max) fatal("retry fail: $cmd", EXIT_DL);
        sleep($a);
    }
}

/* ───── misc helpers ───── */
function tmpd(): string {
    $d = sys_get_temp_dir().'/PMSS_'.bin2hex(random_bytes(6));
    if (!mkdir($d,0700)) fatal("mkdir $d", EXIT_DL);
    return $d;
}
function tagExists(string $t): bool {
    $h = @get_headers(
        'https://api.github.com/repos/MagnaCapax/PMSS/releases/tags/'.rawurlencode($t),
        false,
        stream_context_create(['http'=>['user_agent'=>CURL_UA]])
    );
    return $h && preg_match('/HTTP\/.* (2|3)\d\d/', $h[0]);
}

/* ───── parse source spec ───── */
$cli = array_values(array_diff($argv,['--verbose','--scriptonly','--help']));
array_shift($cli);
$spec = $cli[0] ?? trim(@file_get_contents(VERSION_FILE) ?: 'release');

if (!preg_match('/^(git|release)([\/:])(.*)$/i', $spec, $m))
    fatal("bad spec '$spec'", EXIT_PARSE);

$type = strtolower($m[1]);
$rest = $m[3];
$repo = DEFAULT_REPO;
$branch = 'main';
$date = '';

if ($type === 'release') {
    $date = ltrim($rest, ':');
    if ($date === '') {
        $json = json_decode(
            file_get_contents(
                'https://api.github.com/repos/MagnaCapax/PMSS/releases/latest',
                false,
                stream_context_create(['http'=>['user_agent'=>CURL_UA]])
            ), true
        );
        $date = $json['tag_name'] ?? fatal('GitHub API tag missing', EXIT_PARSE);
    } elseif (!tagExists($date)) {
        fatal("Release tag '$date' not found", EXIT_PARSE);
    }
} else {                                           /* ── git ── */
    if (preg_match('/:(\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2})?)$/', $rest, $mm)) {
        $date = $mm[1];
        $rest = substr($rest, 0, -strlen($mm[0])); // strip date
    }
    if (preg_match('#^(https?|ssh)://#', $rest)) { // repo URL
        $pos = strrpos($rest, ':');
        if ($pos !== false && $pos > 8) {
            $repo   = substr($rest, 0, $pos);
            $branch = substr($rest, $pos + 1) ?: 'main';
        } else {
            $repo   = $rest;
            $branch = 'main';
        }
    } elseif (str_contains($rest, ':')) {          // repo:branch
        [$repo, $branch] = explode(':', $rest, 2);
        $branch = $branch ?: 'main';
    } else {                                       // branch only
        $branch = $rest;
    }
    if (!preg_match('#://|/#', $repo)) {           // safety net
        $branch = $repo ?: $branch;
        $repo   = DEFAULT_REPO;
    }
}
    $logger->msg("Source → $type repo=$repo branch=$branch date='$date'");

/* ───── download tree ───── */
$tmp = tmpd();
if ($type === 'release') {
    $tar = "$tmp/src.tgz";
    sh_retry("curl -L --fail -A ".escapeshellarg(CURL_UA)." https://api.github.com/repos/MagnaCapax/PMSS/tarball/$date -o ".escapeshellarg($tar), $verbose);
    sh("tar -xzf ".escapeshellarg($tar)." -C ".escapeshellarg($tmp)." --strip-components=1", $verbose);
} else {
    sh_retry("git clone --depth=1 --branch ".escapeshellarg($branch).' '.escapeshellarg($repo).' '.escapeshellarg($tmp), $verbose);
    if ($date !== '') {
        $ref = escapeshellarg("$branch@{{$date}}");
        sh_retry('cd '.escapeshellarg($tmp).' && git fetch --quiet && git checkout '.$ref, $verbose);
    }
}

/* ───── sanity ───── */
foreach (['scripts','etc','var'] as $d)
    if (!is_dir("$tmp/$d")) fatal("missing $d", EXIT_VERIFY);

/* ───── DEPLOY (copy‑only) ───── */
sh("rm -rf /scripts/*", $verbose);                                   // wipe scripts dir only
sh("cp -rp ".escapeshellarg("$tmp/scripts")." /", $verbose);         // fresh scripts
sh("cp -rpu ".escapeshellarg("$tmp/etc")." /", $verbose);            // merge etc (update if newer)
sh("cp -rp ".escapeshellarg("$tmp/var")." /", $verbose);             // copy var
sh("chmod -R o-rwx /scripts /root /etc/skel /etc/seedbox", $verbose);

/* ───── record & clean ───── */
$version = ($type === 'release')
         ? "release:$date"
         : "git:$repo:$branch".($date?":$date":'');
file_put_contents(VERSION_FILE, $version.PHP_EOL);
sh("rm -rf ".escapeshellarg($tmp), $verbose);

/* ───── phase‑2 ───── */
if (!$scriptonly && file_exists('/scripts/util/update-step2.php') && file_exists('/etc/hostname')) {
    require '/scripts/util/update-step2.php';
} else {
    $logger->msg('Skipped update‑step2');
}

$logger->msg("Update OK → $version");
echo "Update OK → $version\n";
