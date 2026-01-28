<?php
namespace PMSS\Tests\Production;

use PMSS\Tests\TestCase;

require_once __DIR__.'/../common/TestCase.php';

class ServiceIntentTest extends TestCase
{
    private const SYSTEMD_ENABLED_STATES = [
        'enabled',
        'enabled-runtime',
        'static',
        'linked',
        'linked-runtime',
        'alias',
        'indirect',
        'generated',
    ];

    /**
     * Ensure the matrix lists every core daemon plus detection hints.
     */
    public function testExpectedServiceListDocumented(): void
    {
        $matrix = $this->expectedServices();
        $this->assertTrue(count($matrix) >= 5, 'Core service list should cover baseline daemons');
        foreach ($matrix as $name => $meta) {
            $this->assertTrue(
                isset($meta['units']) || isset($meta['init']) || isset($meta['binaries']),
                $name.' must define discovery hints'
            );
        }
    }

    /**
     * Confirm production hosts expose at least one control artefact per service.
     */
    public function testServicesExposeStartArtefacts(): void
    {
        if (!$this->onLiveHost()) {
            $this->assertTrue(true, 'Skipping service artefact checks outside live hosts');
            return;
        }

        $missing = [];
        foreach ($this->expectedServices() as $name => $meta) {
            if ($this->serviceArtefactsAvailable($meta)) {
                continue;
            }
            if (!($meta['optional'] ?? false)) {
                $missing[] = $name;
            }
        }

        $this->assertTrue(empty($missing), 'Services missing control artefacts: '.implode(', ', $missing));
    }

    /**
     * Validate critical systemd units are enabled when systemd is present.
     */
    public function testCriticalServicesEnabledWhenSystemdAvailable(): void
    {
        if (!$this->onLiveHost() || !$this->systemctlAvailable()) {
            $this->assertTrue(true, 'Skipping systemd enablement checks without systemctl');
            return;
        }

        $unexpected = [];
        foreach ($this->expectedServices() as $name => $meta) {
            if (($meta['systemd'] ?? false) !== true) {
                continue;
            }

            $units = $meta['units'] ?? [];
            if (empty($units)) {
                continue;
            }

            $enabled = false;
            foreach ($units as $unit) {
                if (!$this->systemdUnitExists($unit)) {
                    continue;
                }
                $state = $this->systemctlState($unit);
                if ($state !== null && in_array($state, self::SYSTEMD_ENABLED_STATES, true)) {
                    $enabled = true;
                    break;
                }
            }

            if (!$enabled && !($meta['optional'] ?? false)) {
                $unexpected[] = $name;
            }
        }

        $this->assertTrue(empty($unexpected), 'Services not enabled via systemd: '.implode(', ', $unexpected));
    }

    /**
     * Describe the baseline services and their discovery hints.
     */
    private function expectedServices(): array
    {
        return [
            'nginx' => [
                'units'    => ['nginx.service'],
                'init'     => ['nginx'],
                'binaries' => ['nginx'],
                'configs'  => ['/etc/nginx', '/etc/init.d/nginx'],
                'systemd'  => true,
            ],
            'proftpd' => [
                'units'    => ['proftpd.service'],
                'init'     => ['proftpd'],
                'binaries' => ['proftpd'],
                'configs'  => ['/etc/proftpd/proftpd.conf'],
                'systemd'  => true,
            ],
            'openvpn' => [
                'units'    => ['openvpn.service', 'openvpn@server.service'],
                'init'     => ['openvpn'],
                'binaries' => ['openvpn'],
                'configs'  => ['/etc/openvpn'],
                'systemd'  => true,
            ],
            'rtorrent' => [
                'binaries' => ['rtorrent'],
                'configs'  => ['/usr/local/bin/rtorrent', '/etc/seedbox/config/template.rtorrent.rc'],
            ],
            'watchdog' => [
                'units'    => ['watchdog.service'],
                'init'     => ['watchdog'],
                'binaries' => ['watchdog'],
                'configs'  => ['/etc/watchdog.conf'],
                'systemd'  => true,
                'optional' => true,
            ],
            'wireguard' => [
                'units'    => ['wg-quick@wg0.service'],
                'binaries' => ['wg-quick', 'wg'],
                'configs'  => ['/etc/wireguard'],
                'systemd'  => true,
                'optional' => true,
            ],
        ];
    }

    /**
     * Detect whether we are running on a provisioned PMSS host.
     */
    private function onLiveHost(): bool
    {
        return is_dir('/scripts') && is_file('/scripts/util/systemTest.php');
    }

    /**
     * Check that at least one artefact exists for the given service metadata.
     */
    private function serviceArtefactsAvailable(array $meta): bool
    {
        foreach ($meta['units'] ?? [] as $unit) {
            if ($this->systemdUnitExists($unit)) {
                return true;
            }
        }

        foreach ($meta['init'] ?? [] as $script) {
            if (is_file('/etc/init.d/'.$script)) {
                return true;
            }
        }

        foreach ($meta['configs'] ?? [] as $path) {
            if (is_file($path) || is_dir($path)) {
                return true;
            }
        }

        foreach ($meta['binaries'] ?? [] as $binary) {
            if ($this->binaryExists($binary)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether systemctl is available on the host.
     */
    private function systemctlAvailable(): bool
    {
        static $cached;
        if ($cached === null) {
            $output = @shell_exec('command -v systemctl 2>/dev/null');
            $cached = is_string($output) && trim($output) !== '';
        }
        return $cached;
    }

    /**
     * Inspect whether a systemd unit file exists locally or via systemctl.
     */
    private function systemdUnitExists(string $unit): bool
    {
        $paths = [
            '/etc/systemd/system/'.$unit,
            '/lib/systemd/system/'.$unit,
            '/usr/lib/systemd/system/'.$unit,
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        if (!$this->systemctlAvailable()) {
            return false;
        }

        $cmd = sprintf('systemctl list-unit-files %s 2>/dev/null', escapeshellarg($unit));
        $output = @shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            return false;
        }

        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'unit file') === 0) {
                continue;
            }
            if (strpos($line, $unit) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the enablement state reported by systemd.
     */
    private function systemctlState(string $unit): ?string
    {
        if (!$this->systemctlAvailable()) {
            return null;
        }

        $cmd = sprintf('systemctl is-enabled %s 2>/dev/null', escapeshellarg($unit));
        $output = @shell_exec($cmd);
        if (!is_string($output)) {
            return null;
        }

        $state = trim($output);
        if ($state === '' || stripos($state, 'failed') === 0) {
            return null;
        }

        return $state;
    }

    /**
     * Detect whether a binary is available in PATH.
     */
    private function binaryExists(string $binary): bool
    {
        $cmd = sprintf('command -v %s 2>/dev/null', escapeshellarg($binary));
        $output = @shell_exec($cmd);
        return is_string($output) && trim($output) !== '';
    }
}
