<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * A root-run fetch with wget's or curl's defaults can hang the update for most of an hour on a mirror that drops
 * packets; every wget in scripts/lib carries --timeout and --tries, every curl a connect and idle bound.
 */
final class DownloadTimeoutBoundsTest extends TestCase
{
    public function testEveryLibraryFetchCarriesTimeoutBounds(): void
    {
        $unbounded = [];
        $root = $this->pmssRepoPath('scripts/lib');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false) continue;
            foreach (file($path) as $no => $line) {
                $wget = preg_match("/pmssBuildCommand\\('wget'|['\"]wget -/", $line) === 1 && strpos($line, '--version') === false;
                $curl = preg_match("/['\"]curl -/", $line) === 1 && strpos($line, '--version') === false;
                // A total time limit (-m N / --max-time) is a bound too; the diagnostics probes use it.
                $timed = strpos($line, '--max-time') !== false || preg_match('/curl [^\'"]*-m \d/', $line) === 1;
                if ($wget && (strpos($line, '--timeout=') === false || strpos($line, '--tries=') === false)) {
                    $unbounded[] = substr($path, strlen($root) + 1).':'.($no + 1);
                }
                if ($curl && !$timed && (strpos($line, '--connect-timeout') === false || strpos($line, '--speed-time') === false)) {
                    $unbounded[] = substr($path, strlen($root) + 1).':'.($no + 1);
                }
            }
        }
        $this->assertSame([], $unbounded, 'unbounded fetches: '.implode(', ', $unbounded));
    }
}
