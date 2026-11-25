<?php
/** Verify fresh SABnzbd defaults and preservation in etc/skel/install-media-stack.sh. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MediaStackSabnzbdDefaultsTest extends TestCase
{
    public function testFreshConfigCreatesDownloadFoldersAndLeavesServerAndCategoriesToUpstream(): void
    {
        [$home, $config, $output] = $this->configure();
        foreach (['complete', 'incomplete'] as $folder) {
            $this->assertTrue(is_dir($home.'/data/usenet/'.$folder));
            $key = $folder === 'complete' ? 'complete_dir' : 'download_dir';
            $this->assertStringContainsString($key.' = "'.$home.'/data/usenet/'.$folder.'"', $config);
        }
        $this->assertStringContainsAllStrings(['language = en', 'host = 127.0.0.1', 'port = 23000',
            'url_base = /public-fixtur01/sabnzbd', 'username = fixtur01', 'password = fixture-secret',
            'inet_exposure = 4'], $config);
        $this->assertStringContainsString('add your own Usenet news server', $output);
        $this->assertStringContainsAndOmitsStrings([], ['[servers]', '[categories]'], $config);
    }

    public function testRerunPreservesCustomBlankAndAbsentDefaultsAndCategoryServerSections(): void
    {
        // ConfigObj indents nested values; include independent service credentials.
        $sections = "[categories]\n[[movies]]\n    dir = /fixture/movies\n[[tv]]\n    dir = series\n"
            ."[servers]\n[[fixture.invalid]]\n    host = fixture.invalid\n"
            ."    username = fixture-provider\n    password = fixture-provider-secret\n";
        foreach (["complete_dir = /fixture/library\ndownload_dir = /fixture/staging\nlanguage = fi\n",
            "complete_dir = \ndownload_dir = \nlanguage = \n", ''] as $defaults) {
            $before = "[misc]\n".$defaults.$sections;
            [$home, $config, $output] = $this->configure($before);
            $this->assertStringContainsString($defaults.$sections, $config);
            $this->assertFalse(is_dir($home.'/data/usenet'));
            $this->assertStringNotContainsString('data/usenet', $config);
            $this->assertStringNotContainsString('SABnzbd first run:', $output);
        }
    }

    public function testFreshDefaultsAreStableOnASecondConfiguration(): void
    {
        [$home, $config] = $this->configure(null, 'rerun');
        $this->assertSame($config, file_get_contents($home.'/first.ini'));
    }

    public function testDryRunWritesNeitherConfigNorDownloadFolders(): void
    {
        foreach ([null, "[misc]\nlanguage = fi\n"] as $before) {
            [$home, $config, $output] = $this->configure($before, 'dry-run');
            $this->assertSame($before ?? '', $config);
            $this->assertSame($before !== null, file_exists($home.'/.config/sabnzbd/sabnzbd.ini'));
            $this->assertFalse(is_dir($home.'/data'));
            $this->assertStringContainsString('[dry-run] would configure SABNZBD', $output);
        }
    }

    public function testDirectoryFailureStopsBeforePublishingConfig(): void
    {
        [$home, $config, $output] = $this->configure(null, 'mkdir-failure');
        $this->assertFalse(file_exists($home.'/.config/sabnzbd/sabnzbd.ini'));
        $this->assertSame('', $config);
        $this->assertStringContainsString('rc:1', $output);
    }

    public function testAuthVerificationStillRejectsFailedOwnedSettings(): void
    {
        [, , $output] = $this->configure(null, 'auth-failure');
        $this->assertStringContainsAllStrings(['SABnzbd auth configuration verification failed', 'rc:1'], $output);
    }

    /** Run only the production config block, inside private fixture directories on the testing VM. */
    private function configure(?string $before = null, string $mode = ''): array
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $this->assertSame(1, preg_match('~^echo "Configuring \$\{app\^\^\}"\n(.*?)^echo "\$\{app\^\^\} configured"~ms', $source, $matches));
        $home = $this->pmssMakeTempDir('pmss-sabnzbd-defaults-').'/fixture home';
        mkdir($home.'/.config/sabnzbd', 0700, true);
        $path = $home.'/.config/sabnzbd/sabnzbd.ini';
        if ($before !== null) $this->pmssWriteFile($path, $before);
        $functions = $this->pmssExtractShellFunctions($source, [
            'sed_replacement_escape', 'sabnzbd_misc_value_set', 'existing_value_from_ini',
        ]);
        $shell = "#!/usr/bin/env bash\n(\nset -euo pipefail\nHOME=".escapeshellarg($home)."\n"
            .'DRY_RUN='.($mode === 'dry-run' ? '1' : '0')."\n"
            ."app=sabnzbd\ndatadir=\"\$HOME/.config/sabnzbd\"\nUSERNAME=fixtur01\nHOSTNAME=fixture.invalid\n"
            ."SABNZBD_PORT=23000\nSABNZBD_AUTH_USERNAME=fixtur01\nSABNZBD_PASSWORD=fixture-secret\n"
            ."log_info() { echo \"\$*\"; }\nlog_err() { echo \"\$*\" >&2; }\n".$functions."\n";
        if ($mode === 'mkdir-failure') $shell .= "mkdir() { return 1; }\n";
        if ($mode === 'auth-failure') $shell .= "sabnzbd_misc_value_set() { :; }\n";
        $shell .= $matches[1];
        if ($mode === 'rerun') {
            $shell .= 'cp "$datadir/sabnzbd.ini" "$HOME/first.ini"'."\n".$matches[1];
        }
        $output = $this->pmssRunShellHarness($shell."\n)\nprintf 'rc:%s\\n' \"\$?\"\n");
        if (!in_array($mode, ['mkdir-failure', 'auth-failure'], true)) {
            $this->assertStringContainsString('rc:0', $output);
        }
        return [$home, file_exists($path) ? file_get_contents($path) : '', $output];
    }
}
