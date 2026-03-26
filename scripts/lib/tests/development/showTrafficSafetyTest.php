<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/traffic/storage.php';

class ShowTrafficSafetyWakeupProbe
{
    /** @var string */
    public $markerPath;

    public function __construct(string $markerPath)
    {
        $this->markerPath = $markerPath;
    }

    public function __wakeup(): void
    {
        @file_put_contents($this->markerPath, 'triggered');
    }
}

class ShowTrafficSafetyTest extends TestCase
{
    /** @var string */
    private $statsPath;

    /** @var string */
    private $markerPath;

    public function setUp(): void
    {
        $runtimeDir = sys_get_temp_dir().'/pmss-show-traffic-safety-'.bin2hex(random_bytes(4));
        $this->markerPath = $runtimeDir.'/wakeup-marker';
        $this->statsPath = $runtimeDir.'/trafficStats/alice';

        @mkdir(dirname($this->statsPath), 0755, true);
        @file_put_contents($this->statsPath, serialize(new ShowTrafficSafetyWakeupProbe($this->markerPath)));
    }

    public function tearDown(): void
    {
        $this->cleanup(dirname(dirname($this->statsPath)));
    }

    public function testSharedTrafficPayloadReaderRejectsSerializedObjectsWithoutWakeup(): void
    {
        $payload = \pmssTrafficReadSerializedArrayFile($this->statsPath);

        $this->assertEquals(null, $payload);
        $this->assertTrue(!file_exists($this->markerPath));
    }
}
