<?php


        require_once __DIR__ . '/../scripts/lib/rtorrentConfig.php';

/**
 * Integration tests for rtorrentConfig port allocation.
 *
 * These tests create multiple configurations to ensure ports are issued
 * uniquely and remain reserved after the allocation module is reinstantiated.
 */
class PortAllocationTest extends \PHPUnit\Framework\TestCase {
    /*
     * Default resource configuration used for building rTorrent configs.
     */
    private $resourceConfig = [
        'ramBlock' => 250,
        'peers' => ['minimum' => 6, 'maximum' => 32],
        'uploadSlots' => 7,
    ];
    /* Template string for SCGI port substitution. */
    private $template = 'scgi:##scgiPort';
    /*
     * Base directory where port allocations are tracked. The tests create and
     * remove this path as needed.
     */
    private $baseDir = '/var/run/pmss/ports';

    /**
     * Prepare a clean port allocation directory for each test run.
    */
    protected function setUp(): void {
        if (!class_exists('rtorrentConfig')) {
            $this->markTestSkipped('rtorrentConfig class is missing');
        }
        require_once __DIR__ . '/../scripts/util/portManager.php';
        if (!class_exists('portManager')) {
            $this->markTestSkipped('portManager class is missing');
        }
        if (file_exists($this->baseDir)) {
            system('rm -rf '.escapeshellarg($this->baseDir));
        }
        if (!@mkdir($this->baseDir, 0755, true) && !is_dir($this->baseDir)) {
            $this->markTestSkipped('Unable to prepare port directory');
        }
    }

    /**
     * Clean up any files created under the port directory.
     */
    protected function tearDown(): void {
        if (is_dir($this->baseDir)) {
            system('rm -rf '.escapeshellarg($this->baseDir));
        }
    }

    /**
     * Ensure each service receives a unique port when multiple configurations
     * are generated in a row.
     *
     * This simulates setting up several users one after another.
     */
    public function testUniquePortsMultipleUsers(): void {
        $rt = new rtorrentConfig($this->resourceConfig, $this->template);
        $ports = [];
        for ($i = 0; $i < 5; $i++) {
            $conf = $rt->createConfig(['ram' => 256, 'dht' => 'no', 'pex' => 'no']);
            $ports[] = $conf['config'];
        }
        $scgi = array_column($ports, 'scgiPort');
        $dht = array_column($ports, 'dhtPort');
        $listen = array_column($ports, 'listenPort');

        $this->assertSame(count($scgi), count(array_unique($scgi)), 'SCGI ports should be unique');
        $this->assertSame(count($dht), count(array_unique($dht)), 'DHT ports should be unique');
        $this->assertSame(count($listen), count(array_unique($listen)), 'Listen ports should be unique');
    }

    /**
     * After creating several configurations and reinitialising the allocation
     * module, verify that previously used ports are not reissued.
     *
     * Persistence ensures no port clashes happen if the management script
     * restarts.
     */
    public function testPortPersistenceAfterRestart(): void {
        $rt1 = new rtorrentConfig($this->resourceConfig, $this->template);
        $first = [];
        for ($i = 0; $i < 3; $i++) {
            $conf = $rt1->createConfig(['ram' => 256, 'dht' => 'no', 'pex' => 'no']);
            $first[] = $conf['config'];
        }

        // restart allocation module
        $rt2 = new rtorrentConfig($this->resourceConfig, $this->template);
        $second = [];
        for ($i = 0; $i < 3; $i++) {
            $conf = $rt2->createConfig(['ram' => 256, 'dht' => 'no', 'pex' => 'no']);
            $second[] = $conf['config'];
        }

        foreach ($second as $cfg) {
            $this->assertNotContains($cfg['scgiPort'], array_column($first, 'scgiPort'), 'SCGI port persisted');
            $this->assertNotContains($cfg['dhtPort'], array_column($first, 'dhtPort'), 'DHT port persisted');
            $this->assertNotContains($cfg['listenPort'], array_column($first, 'listenPort'), 'Listen port persisted');
        }
    }

    /**
     * Ports currently in use should not be handed out by PortManager.
     */
    public function testAvoidsPortsInUse(): void {
        require_once __DIR__ . '/../scripts/util/portManager.php';
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $bound = socket_bind($sock, '0.0.0.0', 50000);
        if (!$bound) {
            $this->markTestSkipped('Unable to bind to test port');
        }
        socket_listen($sock);

        $cmd = trim(shell_exec('command -v ss'));
        if ($cmd === '') {
            $this->markTestSkipped('ss command not available');
        }

        $port = portManager::allocate('test', 49990, 50010);
        $this->assertNotSame(50000, $port, 'Allocated port must not be the one in use');

        socket_close($sock);
    }
}
