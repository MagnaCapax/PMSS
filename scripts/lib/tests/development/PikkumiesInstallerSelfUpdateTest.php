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
        $text = (string) file_get_contents($path);

        $this->assertTrue(($lines[0] ?? '') === '#!/usr/bin/env bash', 'Line 1 must stay the bash shebang the self-update checks for');
        $this->assertTrue(($lines[1] ?? '') === '# PMSS: Pikkumies Installer', 'Line 2 must stay the marker the self-update checks for');
        $this->assertTrue(strpos($text, '--no-self-update') !== false, 'The opt-out flag must be handled');
        $this->assertTrue(strpos($text, 'exec bash "$tmp" --skip-update') !== false, 'The fetched copy must run with --skip-update so it never fetches again');
        // /tmp is mounted noexec on PMSS servers: executing the fetched copy directly fails with exit 126, so bash reads it.
        $this->assertTrue(strpos($text, 'exec "$tmp"') === false, 'The fetched copy must be run by bash, not executed from /tmp');
        $this->assertTrue(strpos($text, 'etc/skel/bin/install-pikkumies.sh') !== false, 'The self-update URL must point at this file');

        exec('bash -n '.escapeshellarg($path).' 2>&1', $out, $code);
        $this->assertTrue($code === 0, 'bash -n failed: '.implode(' ', $out));
    }
}
