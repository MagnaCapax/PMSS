<?php
/** Verify failed media-stack installs reconcile existing proxy ports from saved configs. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MediaStackProxyRecoveryTest extends TestCase
{
    public function testExplicitFailureCommandFailureAndPromptEofPreserveStatus(): void
    {
        foreach (['exit 17' => 17, 'false' => 1, 'read -r answer </dev/null' => 1] as $failure => $status) {
            [$home, $before, $output] = $this->recover(['sabnzbd' => '23000'], $failure);
            $this->assertSame(str_replace('=> 18000', '=> 23000', $before), $this->fragment($home));
            $this->assertStringContainsString('status:'.$status, $output);
            $this->assertSame(1, substr_count($output, 'Downloads without checksum verification (1)'));
            $this->assertSame(0640, fileperms($home.'/.lighttpd/custom.d/media-stack.conf') & 0777);
            $this->assertSame([], glob($home.'/.lighttpd/.media-stack-ports.*'));
        }
    }

    public function testAllAppFormatsRecoverAndAbsentRoutesStayAbsent(): void
    {
        $ports = array_fill_keys(['sabnzbd', 'radarr', 'prowlarr', 'sonarr', 'autobrr', 'jellyfin'], '24000');
        [$home, $before] = $this->recover($ports, 'exit 1');
        $this->assertSame(preg_replace('/=> 1800[0-5]/', '=> 24000', $before), $this->fragment($home));
        [$home, $before] = $this->recover(['jellyfin' => '24000'], 'exit 1', 'absent-route');
        $this->assertSame($before, $this->fragment($home));
        [$home, $before] = $this->recover(['jellyfin' => '24000'], 'exit 1', 'internal-port');
        $this->assertSame(str_replace('=> 18005', '=> 24000', $before), $this->fragment($home));
    }

    public function testInvalidMissingAndUnchangedPortsLeaveFragmentInodeIntact(): void
    {
        foreach ([null, '', '0', '65536', 'abc', '23;bad', '18000'] as $port) {
            [$home, $before] = $this->recover(['sabnzbd' => $port], 'exit 1');
            $this->assertSame($before, $this->fragment($home));
            $this->assertSame(trim(file_get_contents($home.'/inode')), (string) fileinode($home.'/.lighttpd/custom.d/media-stack.conf'));
        }
    }

    public function testDryRunSuccessMissingAndSymlinkFragmentsAreUntouched(): void
    {
        foreach (['dry-run', 'success', 'missing', 'symlink'] as $mode) {
            [$home, $before] = $this->recover(['sabnzbd' => '23000'], $mode === 'success' ? 'exit 0' : 'exit 1', $mode);
            if ($mode === 'missing') {
                $this->assertFalse(file_exists($home.'/.lighttpd/custom.d/media-stack.conf'));
            } else {
                $this->assertSame($before, $this->fragment($home));
            }
            if ($mode === 'symlink') $this->assertTrue(is_link($home.'/.lighttpd/custom.d/media-stack.conf'));
        }
    }

    public function testPublicationFailureWarnsAndPreservesFailureAndOriginalFragment(): void
    {
        [$home, $before, $output] = $this->recover(['sabnzbd' => '23000'], 'exit 17', 'write-failure');
        $this->assertSame($before, $this->fragment($home));
        $this->assertStringContainsString('Failed to reconcile media-stack proxy ports', $output);
        $this->assertStringContainsString('status:17', $output);
        $this->assertSame([], glob($home.'/.lighttpd/.media-stack-ports.*'));
    }

    public function testExitHookIsArmedBeforeAnyFullInstallConfiguration(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $this->assertOrderedStrings([
            'if [[ -n "$SECURE_APP" ]]', 'SABNZBD_PORT=$(pick_existing_or_reserved_port',
            'trap \'media_stack_install_exit "$?"\' EXIT', '# Kill existing tmux sessions per app first',
            'sabnzbd_misc_value_set "$datadir/${app}.ini" port "$SABNZBD_PORT"',
        ], $source);
    }

    private function fragment(string $home): string
    {
        return (string) file_get_contents($home.'/.lighttpd/custom.d/media-stack.conf');
    }

    /** Extract production helpers; run failure scenarios only inside the fixture shell. */
    private function recover(array $changes, string $failure, string $mode = ''): array
    {
        $home = $this->pmssMakeTempDir('pmss-media-proxy-recovery-');
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $fragment = "# Preserve custom comments and route maps.\n";
        foreach (['sabnzbd', 'radarr', 'prowlarr', 'sonarr', 'autobrr', 'jellyfin'] as $index => $app) {
            $old = (string) (18000 + $index);
            $port = array_key_exists($app, $changes) ? $changes[$app] : $old;
            if ($mode !== 'absent-route' || $app !== 'jellyfin') {
                $fragment .= '$HTTP["url"] =~ "^/'.$app.'(\$|/)" {' . "\n"
                    .'  proxy.server = ( "" => (( "host" => "127.0.0.1", "port" => '.$old." )) )\n}\n";
            }
            $this->pmssWriteRelativeFile($home, '.media-stack-port-'.$app, (23000 + $index)."\n");
            if ($port === null) continue;
            if ($app === 'sabnzbd' || $app === 'autobrr') {
                $path = $app === 'sabnzbd' ? 'sabnzbd.ini' : 'config.toml';
                $this->pmssWriteRelativeFile($home, '.config/'.$app.'/'.$path, 'port = '.$port."\n");
            } else {
                $tag = $app === 'jellyfin' ? 'PublicHttpPort' : 'Port';
                if ($app === 'jellyfin' && $mode === 'internal-port') $tag = 'InternalHttpPort';
                $path = $app === 'jellyfin' ? 'network.xml' : 'config.xml';
                $xml = '<Config><'.$tag.'>'.$port.'</'.$tag.'>';
                if ($tag === 'InternalHttpPort') $xml .= '<PublicHttpPort>18005</PublicHttpPort>';
                $this->pmssWriteRelativeFile($home, '.config/'.$app.'/'.$path, $xml.'</Config>');
            }
        }
        $target = $home.'/.lighttpd/custom.d/media-stack.conf';
        $this->pmssWriteFile($target, $fragment);
        chmod($target, 0640);
        file_put_contents($home.'/inode', (string) fileinode($target));
        if ($mode === 'missing') unlink($target);
        if ($mode === 'symlink') {
            rename($target, $home.'/original');
            symlink($home.'/original', $target);
        }
        $functions = $this->pmssExtractShellFunctions($source, [
            'existing_port_from_ini', 'existing_port_from_xml_tag', 'media_stack_port_is_valid',
            'lighttpd_media_stack_ports_reconcile', 'media_stack_install_exit', 'media_stack_install_error', 'checksum_summary',
        ]);
        $shell = "#!/bin/bash\n(\nset -Eeuo pipefail\nHOME=".escapeshellarg($home)."\n"
            .'DRY_RUN='.($mode === 'dry-run' ? '1' : '0')."\nJELLYFIN_CONFIG_DIR=\"\$HOME/.config/jellyfin\"\n"
            ."CHECKSUMS_SKIPPED=(fixture)\nlog_warn() { echo \"\$*\" >&2; }\nlog_err() { echo \"\$*\" >&2; }\n"
            ."media_stack_quota_files() { :; }\n".$functions."\n"
            .($mode === 'write-failure' ? "mv() { return 1; }\n" : '')
            .'trap \'media_stack_install_error "$?"\' ERR'."\n"
            .'trap \'media_stack_install_exit "$?"\' EXIT'."\n".$failure."\n)\necho status:\$?\n";
        $output = $this->pmssRunShellHarness($shell);
        clearstatcache();
        return [$home, $fragment, $output];
    }
}
