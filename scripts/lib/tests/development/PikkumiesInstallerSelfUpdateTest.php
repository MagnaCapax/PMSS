<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * etc/skel/bin/install-pikkumies.sh fetches its own newest copy and runs it only when line 1 is the bash
 * shebang and line 2 is the PMSS marker (ADR 0090, 2026-10-17 amendment). Editing either line would make
 * every self-update refuse silently, so both are pinned here, with the opt-out flag, the loop guard and the
 * bash run (/tmp is mounted noexec on PMSS servers).
 */
class PikkumiesInstallerSelfUpdateTest extends TestCase
{
    public function testSelfUpdateMarkersFlagsAndSyntax(): void
    {
        $path = $this->pmssRepoPath('etc/skel/bin/install-pikkumies.sh');
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        $this->assertTrue(($lines[0] ?? '') === '#!/usr/bin/env bash', 'Line 1 must stay the bash shebang the self-update checks for');
        $this->assertTrue(($lines[1] ?? '') === '# PMSS: Pikkumies Installer', 'Line 2 must stay the marker the self-update checks for');
        // /tmp is mounted noexec on PMSS servers: executing the fetched copy directly fails with exit 126, so bash reads it.
        $this->pmssAssertRepoFileContract('etc/skel/bin/install-pikkumies.sh', ['required' => [
            '--no-self-update' => 'The opt-out flag must be handled',
            'exec bash "$tmp" --skip-update' => 'The fetched copy must run with --skip-update so it never fetches again',
            'etc/skel/bin/install-pikkumies.sh' => 'The self-update URL must point at this file',
        ], 'forbidden' => ['exec "$tmp"' => 'The fetched copy must be run by bash, not executed from /tmp']]);

        exec('bash -n '.escapeshellarg($path).' 2>&1', $out, $code);
        $this->assertTrue($code === 0, 'bash -n failed: '.implode(' ', $out));
    }

    /**
     * A ~/.mcxPikkumies folder without its git checkout (files written back after the checkout went away) made the
     * clone fail. The installer keeps that folder under a new name with its files intact and clones fresh; with no
     * such folder it keeps nothing. Runs the real script in a temporary home with a stand-in git.
     */
    public function testLeftoverFolderIsKeptAndCloneProceeds(): void
    {
        $script = $this->pmssRepoPath('etc/skel/bin/install-pikkumies.sh');
        foreach ([true, false] as $leftover) {
            $root = $this->pmssMakeTempDir('pikkumies-installer-', 0700);
            $home = $root.'/home';
            $bin = $root.'/bin';
            mkdir($home, 0700);
            mkdir($bin, 0700);
            // Stand-in git: "clone ... DIR" fails like git on a non-empty DIR, else creates a checkout with a launcher.
            file_put_contents($bin.'/git', "#!/bin/sh\nfor d; do :; done\n"
                ."if [ -e \"\$d\" ] && [ -n \"\$(ls -A \"\$d\")\" ]; then echo 'fatal: destination path exists and is not an empty directory' >&2; exit 128; fi\n"
                ."mkdir -p \"\$d/.git\" \"\$d/pikkumies/bin\"\n"
                ."printf '#!/bin/sh\\necho \"\$*\" > \"\$HOME/ran\"\\n' > \"\$d/pikkumies/bin/pikkumies\"\n"
                ."chmod 755 \"\$d/pikkumies/bin/pikkumies\"\n");
            chmod($bin.'/git', 0755);
            if ($leftover) {
                mkdir($home.'/.mcxPikkumies', 0700);
                file_put_contents($home.'/.mcxPikkumies/keep.txt', 'customer file');
            }
            $cmd = 'env -i HOME='.escapeshellarg($home).' PATH='.escapeshellarg($bin.':/usr/bin:/bin')
                .' bash '.escapeshellarg($script).' --no-self-update 2>&1';
            exec($cmd, $out, $code);
            $kept = glob($home.'/.mcxPikkumies.kept-*') ?: [];
            $this->assertTrue($code === 0, 'installer exited '.$code.': '.implode(' ', $out));
            $this->assertTrue(is_dir($home.'/.mcxPikkumies/.git'), 'the fresh checkout must exist');
            $this->assertTrue(trim((string) @file_get_contents($home.'/ran')) === 'install', 'the installer must run pikkumies install');
            if ($leftover) {
                $this->assertTrue(count($kept) === 1 && file_get_contents($kept[0].'/keep.txt') === 'customer file',
                    'the leftover folder must be kept with its files');
            } else {
                $this->assertTrue($kept === [], 'nothing is kept when there was no leftover folder');
            }
            exec('rm -rf -- '.escapeshellarg($root));
            $out = [];
        }
    }
}
