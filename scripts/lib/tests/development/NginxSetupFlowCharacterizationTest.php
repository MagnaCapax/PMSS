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
function is_dir($path) { return $path === '/var/www/pikkumies' && isset($GLOBALS['options']['legacyPages']); }
function fileowner($path) { return $GLOBALS['options']['legacyOwner'] ?? 0; }
function fileperms($path) { return $GLOBALS['options']['legacyMode'] ?? 0755; }
function scandir($path) { return array_merge(['.', '..'], $GLOBALS['options']['legacyPages']); }
function lstat($path) { return ['uid' => 0, 'mode' => $GLOBALS['options']['pageMode'] ?? 0100644, 'nlink' => $GLOBALS['options']['pageLinks'] ?? 1]; }
function rmdir($path) { record(['rmdir', $path]); return true; }
function is_readable($path) { return $GLOBALS['options']['certificateReadable'] ?? true; }
function is_file($path) {
    if (strpos($path, '/etc/nginx/conf.d/') === 0 || strpos($path, '/var/www/pikkumies/') === 0) return true;
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
        foreach (['pmss-pikkumies-default.conf', 'pmss-pikkumies-hermes-default.conf', 'pmss-hermes-default.conf'] as $name) {
            $expected[] = ['safe', '/etc/nginx/conf.d/'.$name, false, true];
            $expected[] = ['remove', '/etc/nginx/conf.d/'.$name];
        }
        $expected[] = ['safe', '/var/www/pikkumies', true, false];
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

    public function testOnlyRetiredDefaultsAreRemovedRegardlessOfHostConfiguration(): void
    {
        foreach (['node.example.test', 'localhost', ''] as $host) {
            [, $events, , $fault] = $this->runSetup(['hostname' => $host]);
            $removed = array_values(array_filter($events, static function ($e) { return $e[0] === 'remove'; }));
            $this->assertSame([
                ['remove', '/etc/nginx/conf.d/pmss-pikkumies-default.conf'],
                ['remove', '/etc/nginx/conf.d/pmss-pikkumies-hermes-default.conf'],
                ['remove', '/etc/nginx/conf.d/pmss-hermes-default.conf'],
            ], $removed);
            $this->assertSame([], array_values(array_filter($events, static function ($e) { return $e[0] === 'managed'; })));
            $this->assertSame(null, $fault);
        }
    }
    public function testObsoleteDefaultsKeepLinkAndSafetyRefusals(): void
    {
        foreach ([['obsoleteLink' => true], ['safe' => false]] as $options) {
            [, $events] = $this->runSetup($options);
            $this->assertSame([], array_values(array_filter($events, static function ($e) { return $e[0] === 'remove'; })));
        }
        [, , $warnings] = $this->runSetup(['remove' => false]);
        $this->assertSame(3, count($warnings));
        foreach (['pmss-pikkumies-default.conf', 'pmss-pikkumies-hermes-default.conf', 'pmss-hermes-default.conf'] as $i => $name) {
            $this->assertSame('[WARN] Failed to remove obsolete account UI default nginx config: /etc/nginx/conf.d/'.$name."\n", $warnings[$i]);
        }
    }

    public function testRetiredPagesAreRemovedOnlyForTheRootOwnedManagedInventory(): void
    {
        $pages = ['bar.html', 'hermes-login.html', 'not-running.html'];
        [, $events, $warnings, $fault] = $this->runSetup(['legacyPages' => $pages]);
        $pageEvents = array_values(array_filter($events, static function ($e) {
            return $e[0] === 'remove' && strpos($e[1], '/var/www/pikkumies/') === 0;
        }));
        $this->assertSame([
            ['remove', '/var/www/pikkumies/bar.html'], ['remove', '/var/www/pikkumies/hermes-login.html'],
            ['remove', '/var/www/pikkumies/not-running.html'],
        ], $pageEvents);
        $this->assertTrue(in_array(['rmdir', '/var/www/pikkumies'], $events, true));
        $this->assertSame([], $warnings);
        $this->assertSame(null, $fault);
        foreach ([['legacyOwner' => 1000], ['legacyMode' => 0777], ['pageLinks' => 2],
            ['pageMode' => 0120777], ['legacyPages' => ['customer.html']]] as $options) {
            [, $events, , $fault] = $this->runSetup($options + ['legacyPages' => $pages]);
            $this->assertSame([], array_values(array_filter($events, static function ($e) {
                return in_array($e[0], ['remove', 'rmdir'], true) && strpos($e[1], '/var/www/pikkumies') === 0;
            })));
            $this->assertSame(null, $fault);
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
        foreach (['read', 'backup', 'write', 'remove'] as $stage) {
            foreach (['RuntimeException', 'Error'] as $class) {
                [$context, $events, , $fault] = $this->runSetup(['throw' => $stage, 'throwClass' => '\\'.$class]);
                $this->assertSame(null, $context);
                $this->assertSame([$class, 'fixture fault'], $fault);
                $this->assertSame($stage, end($events)[0]);
            }
        }
    }
}
