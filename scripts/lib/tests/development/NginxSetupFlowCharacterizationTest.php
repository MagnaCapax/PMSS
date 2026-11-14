<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock scripts/lib/nginxConfig/setup.php ordering without touching service paths. */
class NginxSetupFlowCharacterizationTest extends TestCase
{
    /** Real setup code, with every host read, mutation and command intercepted. */
    private function runSetup(array $options = []): array
    {
        $script = 'namespace NginxSetupFlowFixture; require_once '
            .var_export($this->pmssRepoPath('scripts/lib/nginxUserHosts.php'), true).';'
            .' $GLOBALS["options"] = '.var_export($options, true).';';
        $script .= <<<'PHP'
$GLOBALS['events'] = $GLOBALS['warnings'] = [];
function record(array $event) {
    $GLOBALS['events'][] = $event;
    if (($GLOBALS['options']['throw'] ?? null) === $event[0]) {
        $class = $GLOBALS['options']['throwClass'] ?? '\RuntimeException';
        throw new $class('fixture fault');
    }
}
function file_get_contents($path) {
    record(['read', $path]);
    $name = basename($path);
    $templates = [
        'template.nginx-user' => 'active', 'template.nginx-user-suspended' => 'suspended',
        'template.nginx-user-moved' => 'moved', 'template.moved.notice.html' => 'notice',
        'template.nginx-site-default' => "listen 80;\nserver_name localhost;\n||SSL_SETTINGS_CONFIGURED_HERE||",
        'template.nginx-site-default-ssl' => " \nssl fallback;\n ",
        'template.nginx-site-default-ssl-lets-encrypt' => "ssl certificate ||SERVER_HOSTNAME||;\n",
    ];
    return array_key_exists($name, $GLOBALS['options']['templates'] ?? [])
        ? $GLOBALS['options']['templates'][$name] : $templates[$name];
}
function file_exists($path) {
    if (strpos($path, '/etc/letsencrypt/') === 0) return $GLOBALS['options']['certificate'] ?? false;
    if ($path === '/etc/nginx/ssl/nginx.crt') return $GLOBALS['options']['selfSigned'] ?? true;
    if ($path === '/etc/nginx/sites-enabled/default') return ($GLOBALS['options']['enabled'] ?? 'file') !== 'missing';
    throw new \RuntimeException('Unexpected existence probe: '.$path);
}
function is_readable($path) { return $GLOBALS['options']['certificateReadable'] ?? true; }
function is_file($path) {
    if ($path === '/etc/seedbox/config/pikkumiesEternals.enabled') return $GLOBALS['options']['marker'] ?? true;
    if ($path === '/opt/pikkumies-agentboard/agentboard') return $GLOBALS['options']['binary'] ?? true;
    if (strpos($path, '/etc/nginx/conf.d/') === 0) return true;
    throw new \RuntimeException('Unexpected file probe: '.$path);
}
function is_link($path) {
    return $path === '/etc/nginx/sites-enabled/default'
        ? ($GLOBALS['options']['enabled'] ?? 'file') === 'link' : ($GLOBALS['options']['obsoleteLink'] ?? false);
}
function fwrite($stream, $bytes) { $GLOBALS['warnings'][] = $bytes; return strlen($bytes); }
function pmssDirEnsureExists($path, $mode) { record(['directory', $path, $mode]); return $GLOBALS['options']['directories'] ?? true; }
function pmssEnsureSafeDir($path, $mode) { record(['mode', $path, $mode]); return $GLOBALS['options']['mode'] ?? true; }
function pmssBackupCriticalConfig($service, $path) { record(['backup', $service, $path]); }
function copy($source, $target) { record(['copy', $source, $target]); return $GLOBALS['options']['copy'] ?? true; }
function pmssFileWriteComplete($path, $content) { record(['write', $path, $content]); return $GLOBALS['options']['write'] ?? true; }
function symlink($target, $path) { record(['link', $target, $path]); return $GLOBALS['options']['symlink'] ?? true; }
function unlink($path) { record(['remove', $path]); return $GLOBALS['options']['remove'] ?? true; }
function passthru($command) { record(['command', $command]); }
function pmssPathTargetIsSafe($path, $a, $b) { record(['safe', $path, $a, $b]); return $GLOBALS['options']['safe'] ?? true; }
function pmssHostnameRead() { record(['hostname']); return $GLOBALS['options']['hostname'] ?? 'NODE.example.test extra'; }
function pmssPikkumiesUiPortAvailable($port) { record(['port', $port]); return $GLOBALS['options']['ports'][$port] ?? true; }
function pmssPikkumiesUiPagesInstall() { record(['pages']); return $GLOBALS['options']['pages'] ?? true; }
function pmssPikkumiesUiDefaultNginxBlock($ssl) { record(['build', 844, $ssl]); return 'A '.$ssl; }
function pmssPikkumiesHermesDefaultNginxBlock($ssl) { record(['build', 845, $ssl]); return 'B '.$ssl; }
function pmssWriteManagedFile($path, $content, $owner, $group, $mode) {
    record(['managed', $path, $content, $owner, $group, $mode]);
    return $GLOBALS['options']['managed'] ?? true;
}
function pmssNginxUserSubdomainTemplates() { record(['subdomains']); return ['public' => 'public', 'publicSuspended' => 'suspended', 'publicMoved' => 'moved']; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/nginxConfig/setup.php', 'NginxSetupFlowFixture', [
            'setupFilesystem.php', 'setupTls.php', 'setupDefaultSite.php', 'setupAccountUi.php',
        ]);
        $script .= 'try { $context = pmssCreateNginxConfigSetup(); $fault = null; }'
            .' catch (\Throwable $e) { $context = null; $fault = [get_class($e), $e->getMessage()]; }'
            .' echo json_encode([$context, $GLOBALS["events"], $GLOBALS["warnings"], $fault]);';
        return $this->pmssRunInlinePhpJson($script);
    }

    /** Explicit pre-refactor publication trace, including the full context key order. */
    public function testCompleteSetupRetainsContextAndOrderedOperations(): void
    {
        [$context, $events, $warnings, $fault] = $this->runSetup();
        $this->assertSame([
            'userTemplate' => 'active', 'suspendedTemplate' => 'suspended', 'movedTemplate' => 'moved',
            'movedNoticeTemplate' => 'notice', 'subdomainEnabled' => true, 'subdomainBase' => 'node.example.test',
            'subdomainConfigDir' => '/etc/nginx/conf.d', 'nginxUsersDir' => '/etc/nginx/users', 'homeBase' => '/home',
            'runtimePortDir' => '/etc/seedbox/runtime/ports', 'billingPublicDir' => '/var/lib/pmss/public/billing',
            'nginxSslBlock' => "ssl fallback;\n", 'publicSubdomainTemplate' => 'public',
            'publicSuspendedTemplate' => 'suspended', 'publicMovedTemplate' => 'moved',
        ], $context);
        $expected = [];
        foreach (['nginx-user', 'nginx-user-suspended', 'nginx-user-moved', 'moved.notice.html'] as $name) {
            $expected[] = ['read', '/etc/seedbox/config/template.'.$name];
        }
        foreach (['/etc/nginx', '/etc/nginx/sites-available', '/etc/nginx/sites-enabled'] as $path) {
            $expected[] = ['directory', $path, 0755];
        }
        foreach (['nginx.conf' => 'nginx-conf', 'proxy_params' => 'nginx-proxy_params', 'webdav_proxy_params' => 'nginx-webdav_proxy_params'] as $target => $source) {
            $expected[] = ['backup', 'nginx', '/etc/nginx/'.$target];
            $expected[] = ['copy', '/etc/seedbox/config/template.'.$source, '/etc/nginx/'.$target];
        }
        $expected[] = ['hostname'];
        foreach (['nginx-site-default', 'nginx-site-default-ssl', 'nginx-site-default-ssl-lets-encrypt'] as $name) {
            $expected[] = ['read', '/etc/seedbox/config/template.'.$name];
        }
        $site = "listen 80 default_server;\nserver_name localhost node.example.test;\n \nssl fallback;\n ";
        foreach (['available', 'enabled'] as $directory) {
            $expected[] = ['backup', 'nginx', '/etc/nginx/sites-'.$directory.'/default'];
            $expected[] = ['write', '/etc/nginx/sites-'.$directory.'/default', $site];
        }
        $expected[] = ['directory', '/etc/nginx/ssl', 0755];
        $expected[] = ['mode', '/etc/nginx/users', 0751];
        $expected[] = ['directory', '/etc/nginx/conf.d', 0755];
        $expected[] = ['port', 844];
        $expected[] = ['pages'];
        foreach ([844 => ['pmss-pikkumies-default.conf', 'A'], 845 => ['pmss-pikkumies-hermes-default.conf', 'B']] as $port => [$name, $prefix]) {
            if ($port === 845) $expected[] = ['port', $port];
            $expected[] = ['build', $port, "ssl fallback;\n"];
            $expected[] = ['managed', '/etc/nginx/conf.d/'.$name, $prefix." ssl fallback;\n", 'root', 'root', 0644];
        }
        $expected[] = ['safe', '/etc/nginx/conf.d/pmss-hermes-default.conf', false, true];
        $expected[] = ['remove', '/etc/nginx/conf.d/pmss-hermes-default.conf'];
        $expected[] = ['subdomains'];
        $this->assertSame($expected, $events);
        $this->assertSame([], $warnings);
        $this->assertSame(null, $fault);
    }

    public function testDefaultSiteLinkPoliciesRetainBackupAndFallbackOrder(): void
    {
        foreach (['file', 'link', 'missing'] as $enabled) {
            foreach ([true, false] as $symlink) {
                [, $events] = $this->runSetup(['enabled' => $enabled, 'symlink' => $symlink]);
                $enabledEvents = array_values(array_filter($events, static function ($event) {
                    return in_array('/etc/nginx/sites-enabled/default', $event, true);
                }));
                $kinds = array_column($enabledEvents, 0);
                $this->assertSame($enabled === 'file' ? ['backup', 'write']
                    : ($enabled === 'link' ? [] : ($symlink ? ['link'] : ['link', 'write'])), $kinds);
            }
        }
    }

    public function testTlsSelectionRetainsRawSiteBytesAndNormalizedContext(): void
    {
        foreach ([false, true] as $certificate) {
            foreach ([false, true] as $readable) {
                [$context, $events] = $this->runSetup(['certificate' => $certificate, 'certificateReadable' => $readable]);
                $ssl = $certificate && $readable ? "ssl certificate NODE.example.test;\n" : " \nssl fallback;\n ";
                $this->assertSame(trim($ssl)."\n", $context['nginxSslBlock']);
                $writes = array_values(array_filter($events, static function ($event) { return $event[0] === 'write'; }));
                $this->assertSame("listen 80 default_server;\nserver_name localhost node.example.test;\n".$ssl, $writes[0][2]);
            }
        }
        foreach ([false, '', " \n\t "] as $ssl) {
            [$context, $events] = $this->runSetup(['templates' => ['template.nginx-site-default-ssl' => $ssl]]);
            $this->assertSame('', $context['nginxSslBlock']);
            $this->assertSame([], array_values(array_filter($events, static function ($e) { return $e[0] === 'managed' || $e[0] === 'port'; })));
        }
        [, $events] = $this->runSetup(['templates' => ['template.nginx-site-default' => false]]);
        $this->assertSame([], array_values(array_filter($events, static function ($e) { return $e[0] === 'write'; })));
    }

    public function testUiGuardsAndOnePageAttemptRetainBothClientPolicies(): void
    {
        foreach ([
            [['marker' => false], []], [['binary' => false], [845]],
            [['ports' => [844 => false]], [845]], [['ports' => [845 => false]], [844]],
            [['ports' => [844 => false, 845 => false]], []],
        ] as [$options, $expected]) {
            [, $events, , $fault] = $this->runSetup($options);
            $builds = array_values(array_filter($events, static function ($e) { return $e[0] === 'build'; }));
            $ports = array_column($builds, 1);
            $this->assertSame($expected, $ports);
            $this->assertSame($expected === [] ? 0 : 1, count(array_filter($events, static function ($e) { return $e[0] === 'pages'; })));
            $this->assertSame(null, $fault);
        }
        [, $events, $warnings] = $this->runSetup(['pages' => false, 'managed' => false]);
        $this->assertSame(1, count(array_filter($events, static function ($e) { return $e[0] === 'pages'; })));
        $this->assertSame([
            "[WARN] Failed to install account UI pages nginx config: /var/www/pikkumies\n",
            "[WARN] Failed to write account UI default nginx config: /etc/nginx/conf.d/pmss-pikkumies-default.conf\n",
            "[WARN] Failed to write Pikkumies Hermes UI default nginx config: /etc/nginx/conf.d/pmss-pikkumies-hermes-default.conf\n",
        ], $warnings);
    }

    public function testObsoleteDefaultsKeepLinkAndSafetyRefusals(): void
    {
        foreach ([['obsoleteLink' => true], ['safe' => false]] as $options) {
            [, $events] = $this->runSetup($options + ['marker' => false]);
            $this->assertSame([], array_values(array_filter($events, static function ($e) { return $e[0] === 'remove'; })));
        }
        [, , $warnings] = $this->runSetup(['marker' => false, 'remove' => false]);
        $this->assertSame(3, count($warnings));
        foreach (['pmss-pikkumies-default.conf', 'pmss-pikkumies-hermes-default.conf', 'pmss-hermes-default.conf'] as $i => $name) {
            $this->assertSame('[WARN] Failed to remove obsolete account UI default nginx config: /etc/nginx/conf.d/'.$name."\n", $warnings[$i]);
        }
    }

    public function testHostnameAndCertificateCreationRetainCommandAndSkipBoundaries(): void
    {
        foreach (['node.example.test', 'host', '', 'bad;host', '192.0.2.1'] as $hostname) {
            [$context, $events, $warnings, $fault] = $this->runSetup(['hostname' => $hostname, 'selfSigned' => false]);
            $commands = array_values(array_filter($events, static function ($e) { return $e[0] === 'command'; }));
            $valid = in_array($hostname, ['node.example.test', 'host', '192.0.2.1'], true);
            $this->assertSame($valid ? [['command', 'openssl req -x509 -nodes -days 365 -newkey rsa:2048 -subj '
                .escapeshellarg('/C=FI/ST=none/L=none/O=PulsedMedia/CN='.$hostname)
                .' -keyout /etc/nginx/ssl/nginx.key -out /etc/nginx/ssl/nginx.crt']] : [], $commands);
            $this->assertSame($hostname === 'node.example.test', $context['subdomainEnabled']);
            $this->assertSame($hostname === '' || $hostname === 'node.example.test' ? []
                : ['Skipping nginx subdomain vhosts (invalid hostname: '.$hostname.")\n"], $warnings);
            $this->assertSame(null, $fault);
        }
    }

    public function testFailuresKeepProgressAndThrowablesStopAtTheOriginalBoundary(): void
    {
        [, $events, $warnings, $fault] = $this->runSetup(['directories' => false, 'copy' => false, 'write' => false, 'mode' => false]);
        $this->assertSame(['subdomains'], end($events));
        $this->assertSame(11, count($warnings));
        $this->assertSame(null, $fault);
        foreach (['read', 'backup', 'write', 'port', 'pages', 'managed', 'remove'] as $stage) {
            foreach (['RuntimeException', 'Error'] as $class) {
                [$context, $events, , $fault] = $this->runSetup(['throw' => $stage, 'throwClass' => '\\'.$class]);
                $this->assertSame(null, $context);
                $this->assertSame([$class, 'fixture fault'], $fault);
                $this->assertSame($stage, end($events)[0]);
            }
        }
    }
}
