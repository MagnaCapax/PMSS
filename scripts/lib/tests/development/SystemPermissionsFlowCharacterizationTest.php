<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/testing/filesystem.php';
require_once dirname(__DIR__, 2).'/update/systemPermissionsTrees.php';
require_once dirname(__DIR__, 2).'/update/systemPermissionsSecrets.php';

/** Lock the complete setupPermissions.php action stream without touching host paths. */
class SystemPermissionsFlowCharacterizationTest extends TestCase
{
    public function testAllPresentTargetsRetainExactCommandsModesAndOrder(): void
    {
        $result = $this->trace([]);
        $this->assertSame(0, $result['rc']);
        $this->assertSame($this->expectedEvents(), $result['events']);
    }

    public function testEveryCommandFailureAccumulatesWithoutStoppingLaterWork(): void
    {
        $events = $this->expectedEvents();
        $commands = array_filter($events, static function ($row): bool { return $row[0] === 'step'; });
        foreach (array_keys(array_values($commands)) as $index) {
            $result = $this->trace(['fail_step' => $index]);
            $this->assertSame(1, $result['rc'], 'Failure at command '.$index);
            $this->assertSame($events, $result['events'], 'Later work after command '.$index);
        }
    }

    public function testBestEffortChmodAndUnlinkFailuresDoNotChangeExitStatus(): void
    {
        $result = $this->trace(['direct_result' => false]);
        $this->assertSame(0, $result['rc']);
        $this->assertSame($this->expectedEvents(), $result['events']);
    }

    public function testMissingTargetsRetainOnlyMissingTreeDiagnostics(): void
    {
        $result = $this->trace(['missing_all' => true]);
        $this->assertSame(0, $result['rc']);
        $this->assertSame([
            ['root'],
            ['log', 'Skipping /etc/skel permission adjustments; directory missing'],
            ['log', 'Skipping /etc/seedbox permission adjustments; directory missing'],
            ['log', 'Skipping ownership enforcement; missing /etc/skel'],
            ['log', 'Skipping ownership enforcement; missing /etc/seedbox'],
        ], $result['events']);
    }

    public function testSymlinkedWireguardParentSuppressesAllChildWork(): void
    {
        $expected = array_values(array_filter($this->expectedEvents(), static function ($row): bool {
            return strpos(implode(' ', $row), '/etc/wireguard') === false && strpos(implode(' ', $row), 'WireGuard') === false;
        }));
        $expected[] = ['log', 'Skipping /etc/wireguard: symlink detected'];
        $result = $this->trace(['links' => ['/etc/wireguard']]);
        $this->assertSame(0, $result['rc']);
        $this->assertSame($expected, $result['events']);
    }

    public function testFileTargetsRejectDirectoriesAndSymlinksBeforeOwnership(): void
    {
        foreach (['links', 'directories'] as $kind) {
            $path = '/etc/openvpn/ta.key';
            $expected = [];
            foreach ($this->expectedEvents() as $row) {
                if ($row[0] === 'step' && $row[2] === "chown root:root '{$path}'") {
                    if ($kind === 'links') $expected[] = ['log', 'Skipping '.$path.': symlink detected'];
                    continue;
                }
                if ($row[0] === 'step' && $row[2] === 'chmod 600 '.$path) continue;
                $expected[] = $row;
            }
            $result = $this->trace([$kind => [$path]]);
            $this->assertSame(0, $result['rc']);
            $this->assertSame($expected, $result['events']);
        }
    }

    public function testWrongTypeTreeStillRetainsItsSeparateOwnershipPass(): void
    {
        $result = $this->trace(['files' => ['/etc/skel']]);
        $this->assertSame(0, $result['rc']);
        $this->assertTrue(in_array(['log', 'Skipping /etc/skel permission adjustments; directory missing'], $result['events'], true));
        $steps = array_column(array_filter($result['events'], static function ($row): bool { return $row[0] === 'step'; }), 2);
        $this->assertTrue(in_array("chown root:root '/etc/skel'", $steps, true));
        $this->assertFalse(in_array('chmod 770 /etc/skel', $steps, true));
    }

    public function testRootRefusalAndThrownCommandStopAtTheirOriginalBoundaries(): void
    {
        $result = $this->trace(['root_fault' => true]);
        $this->assertSame(99, $result['rc']);
        $this->assertSame([['root'], ['throw', 'Error', 'root refused']], $result['events']);
        $result = $this->trace(['throw_step' => 2]);
        $this->assertSame(99, $result['rc']);
        $this->assertSame(array_merge(array_slice($this->expectedEvents(), 0, 4), [['throw', 'Error', 'command fault']]), $result['events']);
    }

    /** Literal original CLI contract, independent of the runtime policy tables. */
    private function expectedEvents(): array
    {
        return [
            ['root'],
            ['step', 'Hardening /etc/skel content permissions', 'find /etc/skel -mindepth 1 -not -type l -perm /002 -exec chmod o-w -- {} +'],
            ['step', 'Restricting /etc/skel directory permissions', 'chmod 770 /etc/skel'],
            ['step', 'Hardening /etc/seedbox content permissions', 'find /etc/seedbox -mindepth 1 -not -type l -perm /002 -exec chmod o-w -- {} +'],
            ['step', 'Ensuring /etc/seedbox is traversable', 'chmod o+x /etc/seedbox'],
            ['step', 'Ensuring /etc/skel ownership is root:root', "chown root:root '/etc/skel'"],
            ['step', 'Ensuring /etc/seedbox ownership is root:root', "chown root:root '/etc/seedbox'"],
            ['step', 'Removing group write from the toggle-app catalog path', 'find /etc/skel/www -maxdepth 1 \\( -path /etc/skel/www -o -name toggleAppCatalog.php \\) -not -type l -perm /022 -exec chmod go-w -- {} +'],
            ['step', 'Removing other permissions from /etc/skel files', 'find /etc/skel -type f -perm /007 -exec chmod o-rwx -- {} +'],
            ['step', 'Removing other permissions from /etc/seedbox files', 'find /etc/seedbox -type f -perm /007 -exec chmod o-rwx -- {} +'],
            ['chmod', '/etc/openvpn', 0771], ['chmod', '/etc/openvpn/openvpn.conf', 0640],
            ['chmod', '/etc/openvpn/easy-rsa', 0771], ['chmod', '/etc/seedbox/config/localnet', 0664],
            ['chmod', '/etc/seedbox/localnet', 0664],
            ['unlink', '/etc/seedbox/config/api.server'], ['unlink', '/etc/seedbox/config/api.debug'],
            ['unlink', '/etc/seedbox/config/api.localKey'], ['unlink', '/etc/seedbox/config/api.remoteKey'],
            ['sweep', '/etc/seedbox/config'],
            ['step', "Hardening TLS private keys (Let's Encrypt)", "find /etc/letsencrypt/live -type f -name 'privkey.pem' -not -perm 0600 -exec chmod 600 {} +"],
            ['step', "Hardening TLS account keys (Let's Encrypt)", "find /etc/letsencrypt/accounts -type f \\( -name 'private_key.json' -o -name 'private_key*.pem' \\) -not -perm 0600 -exec chmod 600 {} +"],
            ['step', "Hardening TLS account metadata (Let's Encrypt)", "find /etc/letsencrypt/accounts -type f \\( -name 'registration.json' -o -name 'meta.json' \\) -not -perm 0600 -exec chmod 600 {} +"],
            ['step', "Hardening TLS archive keys (Let's Encrypt)", "find /etc/letsencrypt/archive -type f -name 'privkey*.pem' -not -perm 0600 -exec chmod 600 {} +"],
            ['step', "Restricting TLS renewal configs (Let's Encrypt)", "find /etc/letsencrypt/renewal -type f -name '*.conf' -not -perm 0600 -exec chmod 600 {} +"],
            ['step', 'Ensuring /var/lib/letsencrypt ownership is root:root', "chown root:root '/var/lib/letsencrypt'"],
            ['step', 'Restricting /var/lib/letsencrypt', 'chmod 700 /var/lib/letsencrypt'],
            ['step', 'Ensuring /var/log/letsencrypt ownership is root:root', "chown root:root '/var/log/letsencrypt'"],
            ['step', 'Restricting /var/log/letsencrypt', 'chmod 700 /var/log/letsencrypt'],
            ['step', 'Hardening seedbox SSL private keys', "find /etc/seedbox/config/ssl -type f -name 'privkey.pem' -not -perm 0600 -exec chmod 600 {} +"],
            ['step', 'Ensuring /etc/openvpn/easy-rsa/pki/private ownership is root:root', "chown root:root '/etc/openvpn/easy-rsa/pki/private'"],
            ['step', 'Restricting OpenVPN private key directory', 'chmod 700 /etc/openvpn/easy-rsa/pki/private'],
            ['step', 'Hardening OpenVPN private keys', "find /etc/openvpn/easy-rsa/pki/private -type f -name '*.key' -not -perm 0600 -exec chmod 600 {} +"],
            ['step', 'Ensuring /etc/openvpn/easy-rsa/pki/issued ownership is root:root', "chown root:root '/etc/openvpn/easy-rsa/pki/issued'"],
            ['step', 'Restricting OpenVPN PKI directory /etc/openvpn/easy-rsa/pki/issued', "chmod 750 '/etc/openvpn/easy-rsa/pki/issued'"],
            ['step', 'Ensuring /etc/openvpn/easy-rsa/pki/reqs ownership is root:root', "chown root:root '/etc/openvpn/easy-rsa/pki/reqs'"],
            ['step', 'Restricting OpenVPN PKI directory /etc/openvpn/easy-rsa/pki/reqs', "chmod 750 '/etc/openvpn/easy-rsa/pki/reqs'"],
            ['step', 'Ensuring /etc/openvpn/easy-rsa/pki/crl ownership is root:root', "chown root:root '/etc/openvpn/easy-rsa/pki/crl'"],
            ['step', 'Restricting OpenVPN PKI directory /etc/openvpn/easy-rsa/pki/crl', "chmod 750 '/etc/openvpn/easy-rsa/pki/crl'"],
            ['step', 'Ensuring /etc/openvpn/easy-rsa/pki/certs_by_serial ownership is root:root', "chown root:root '/etc/openvpn/easy-rsa/pki/certs_by_serial'"],
            ['step', 'Restricting OpenVPN PKI directory /etc/openvpn/easy-rsa/pki/certs_by_serial', "chmod 750 '/etc/openvpn/easy-rsa/pki/certs_by_serial'"],
            ['step', 'Ensuring /etc/openvpn/easy-rsa/pki/crl.pem ownership is root:root', "chown root:root '/etc/openvpn/easy-rsa/pki/crl.pem'"],
            ['step', 'Restricting OpenVPN CRL', 'chmod 600 /etc/openvpn/easy-rsa/pki/crl.pem'],
            ['step', 'Ensuring /etc/openvpn/ta.key ownership is root:root', "chown root:root '/etc/openvpn/ta.key'"],
            ['step', 'Restricting OpenVPN ta.key', 'chmod 600 /etc/openvpn/ta.key'],
            ['step', 'Restricting OpenVPN configs', "find /etc/openvpn -maxdepth 1 -type f -name '*.conf' -not -perm 0640 -exec chmod 640 {} +"],
            ['step', 'Ensuring /etc/openvpn ownership is root:root', "chown root:root '/etc/openvpn'"],
            ['step', 'Ensuring OpenVPN configs owned by root', "find /etc/openvpn -maxdepth 1 -type f -name '*.conf' \\( -not -user root -o -not -group root \\) -exec chown root:root {} +"],
            ['step', 'Ensuring /etc/wireguard ownership is root:root', "chown root:root '/etc/wireguard'"],
            ['step', 'Hardening WireGuard config directory', 'chmod 700 /etc/wireguard'],
            ['step', 'Ensuring /etc/wireguard/wg0.conf ownership is root:root', "chown root:root '/etc/wireguard/wg0.conf'"],
            ['step', 'Restricting WireGuard wg0.conf', 'chmod 600 /etc/wireguard/wg0.conf'],
            ['step', 'Ensuring /etc/wireguard/server_private.key ownership is root:root', "chown root:root '/etc/wireguard/server_private.key'"],
            ['step', 'Restricting WireGuard server_private.key', 'chmod 600 /etc/wireguard/server_private.key'],
            ['step', 'Ensuring /etc/wireguard/server_public.key ownership is root:root', "chown root:root '/etc/wireguard/server_public.key'"],
            ['step', 'Restricting WireGuard key material', "find /etc/wireguard -type f -name '*.key' -not -perm 0600 -exec chmod 600 {} +"],
        ];
    }

    /** Export real helpers into a child namespace whose external actions only record data. */
    private function trace(array $config): array
    {
        $script = <<<'PHP'
namespace SystemPermissionsFixture;
function is_link($path) { return in_array($path, $GLOBALS['config']['links'] ?? [], true); }
function file_exists($path) { return empty($GLOBALS['config']['missing_all']); }
function is_file($path) {
    return file_exists($path) && !in_array($path, $GLOBALS['config']['directories'] ?? [], true)
        && (in_array($path, $GLOBALS['files'], true) || in_array($path, $GLOBALS['config']['files'] ?? [], true));
}
function is_dir($path) { return file_exists($path) && !is_file($path); }
function logmsg($message) { $GLOBALS['events'][] = ['log', $message]; }
function requireRoot() { $GLOBALS['events'][] = ['root']; if (!empty($GLOBALS['config']['root_fault'])) throw new \Error('root refused'); }
function runStep($message, $command) {
    $GLOBALS['events'][] = ['step', $message, $command];
    $index = $GLOBALS['step']++;
    if (($GLOBALS['config']['throw_step'] ?? -1) === $index) throw new \Error('command fault');
    return ($GLOBALS['config']['fail_step'] ?? -1) === $index ? 23 : 0;
}
function chmod($path, $mode) { $GLOBALS['events'][] = ['chmod', $path, $mode]; return $GLOBALS['config']['direct_result'] ?? true; }
function unlink($path) { $GLOBALS['events'][] = ['unlink', $path]; return $GLOBALS['config']['direct_result'] ?? true; }
function pmssConfigPermissionsSweep($path) { $GLOBALS['events'][] = ['sweep', $path]; }
PHP;
        $files = ['/etc/openvpn/openvpn.conf', '/etc/seedbox/config/localnet', '/etc/seedbox/localnet',
            '/etc/seedbox/config/api.server', '/etc/seedbox/config/api.debug', '/etc/seedbox/config/api.localKey',
            '/etc/seedbox/config/api.remoteKey', '/etc/openvpn/easy-rsa/pki/crl.pem', '/etc/openvpn/ta.key',
            '/etc/wireguard/wg0.conf', '/etc/wireguard/server_private.key', '/etc/wireguard/server_public.key'];
        $script .= '$GLOBALS["config"] = '.var_export($config, true).'; $GLOBALS["files"] = '.var_export($files, true).';'
            .'$GLOBALS["events"] = []; $GLOBALS["step"] = 0;'
            .'register_shutdown_function(static function () { echo json_encode($GLOBALS["events"]); });';
        foreach (['pmssSkipSymlink', 'pmssPermissionTargetExists', 'pmssEnsureRootOwnership', 'pmssPermissionStepsRun',
            'pmssSystemPermissionsTreesApply', 'pmssSystemPermissionsSecretsApply'] as $name) {
            $script .= \pmssTestingFunctionExport($name, 'SystemPermissionsFixture');
        }
        // Execute the actual CLI wiring too, replacing only includes already exported above.
        $main = preg_replace('/\A#![^\n]*\n<\?php\s*|^require_once [^\n]*;\n/m', '', $this->pmssReadRepoFile('scripts/util/setupPermissions.php'));
        $script .= 'try { '.$main.' } catch (\\Throwable $error) {'
            .'$GLOBALS["events"][] = ["throw", get_class($error), $error->getMessage()]; exit(99); }';
        $result = $this->pmssExecInlinePhp($script);
        return ['rc' => $result['rc'], 'events' => $this->pmssDecodeJsonArray($result['output'])];
    }
}
