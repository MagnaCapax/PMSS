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
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/skel/bin/install-pikkumies.sh', [
            '--no-self-update' => 'The opt-out flag must be handled',
            'exec bash "$tmp" --skip-update' => 'The fetched copy must run with --skip-update so it never fetches again',
            'etc/skel/bin/install-pikkumies.sh' => 'The self-update URL must point at this file',
        ], ['exec "$tmp"' => 'The fetched copy must be run by bash, not executed from /tmp']);

        $result = $this->pmssExecArgvCommand(['bash', '-n', $path]);
        $this->assertTrue($result['rc'] === 0, 'bash -n failed: '.implode(' ', $result['lines']));
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
            $this->pmssEnsureDir($home, 0700);
            // Stand-in git: "clone ... DIR" fails like git on a non-empty DIR, else creates a checkout with a launcher.
            $this->pmssWriteExecutableFile($bin.'/git', "#!/bin/sh\nfor d; do :; done\n"
                ."if [ -e \"\$d\" ] && [ -n \"\$(ls -A \"\$d\")\" ]; then echo 'fatal: destination path exists and is not an empty directory' >&2; exit 128; fi\n"
                ."mkdir -p \"\$d/.git\" \"\$d/pikkumies/bin\"\n"
                ."printf '#!/bin/sh\\necho \"\$*\" > \"\$HOME/ran\"\\n' > \"\$d/pikkumies/bin/pikkumies\"\n"
                ."chmod 755 \"\$d/pikkumies/bin/pikkumies\"\n", 0700);
            if ($leftover) {
                $this->pmssWriteFile($home.'/.mcxPikkumies/keep.txt', 'customer file', 0700);
            }
            $result = $this->pmssExecArgvCommand(['env', '-i', 'HOME='.$home, 'PATH='.$bin.':/usr/bin:/bin',
                'bash', $script, '--no-self-update']);
            $kept = glob($home.'/.mcxPikkumies.kept-*') ?: [];
            $this->assertTrue($result['rc'] === 0, 'installer exited '.$result['rc'].': '.implode(' ', $result['lines']));
            $this->assertTrue(is_dir($home.'/.mcxPikkumies/.git'), 'the fresh checkout must exist');
            $this->assertTrue(trim((string) @file_get_contents($home.'/ran')) === 'install', 'the installer must run pikkumies install');
            if ($leftover) {
                $this->assertTrue(count($kept) === 1 && file_get_contents($kept[0].'/keep.txt') === 'customer file',
                    'the leftover folder must be kept with its files');
            } else {
                $this->assertTrue($kept === [], 'nothing is kept when there was no leftover folder');
            }
            $this->pmssExecArgvCommand(['rm', '-rf', '--', $root], [], '');
        }
    }

    /**
     * On an existing checkout the installer runs `pikkumies update` then `pikkumies install`. A person runs it, so the
     * update step carries PIKKUMIES_ATTENDED=1 and Pikkumies builds its runtime as `install` would, instead of refusing
     * with the daily cron's reserve and then reporting "building" (Pikkumies ADR-0013 addendum 2026-11-14).
     */
    public function testUpdateStepRunsAttended(): void
    {
        $root = $this->pmssMakeTempDir('pikkumies-installer-', 0700);
        $home = $root.'/home';
        $launcher = $home.'/.mcxPikkumies/pikkumies/bin/pikkumies';
        $this->pmssEnsureDir($home.'/.mcxPikkumies/.git', 0700);
        $this->pmssEnsureDir(dirname($launcher), 0700);
        $this->pmssWriteExecutableFile($launcher, "#!/bin/sh\necho \"\$1 \${PIKKUMIES_ATTENDED:-unset}\" >> \"\$HOME/ran\"\n", 0700);
        $result = $this->pmssExecArgvCommand(['env', '-i', 'HOME='.$home, 'PATH=/usr/bin:/bin',
            'bash', $this->pmssRepoPath('etc/skel/bin/install-pikkumies.sh'), '--no-self-update']);
        $ran = trim((string) @file_get_contents($home.'/ran'));
        $this->pmssExecArgvCommand(['rm', '-rf', '--', $root], [], '');
        $this->assertTrue($result['rc'] === 0, 'installer exited '.$result['rc'].': '.implode(' ', $result['lines']));
        $this->assertTrue($ran === "update 1\ninstall unset", 'the update step must run attended, then install: '.json_encode($ran));
    }
}
