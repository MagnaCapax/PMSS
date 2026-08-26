<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/mediaStackPorts.php';

final class MediaStackPortManagerTest extends TestCase
{
    /** @var string */
    private $home;

    /** @var string */
    private $user;

    public function setUp(): void
    {
        $this->user = 'alice';
        $root = $this->pmssMakeTempDir('pmss-media-stack-ports-');
        $this->home = $root.'/'.$this->user;
        $this->pmssEnsureDir($this->home);
        $this->pmssTrackEnvOverrides(array(
            'PMSS_PORT_MANAGER_DIR' => $this->pmssMakeTempDir('pmss-media-stack-managed-'),
            'PMSS_PORT_MANAGER_LEGACY_DIR' => $this->pmssMakeTempDir('pmss-media-stack-legacy-'),
        ));
    }

    public function testEnsureAdoptsConfiguredIniAndXmlPorts(): void
    {
        $this->pmssWriteFile($this->home.'/.config/sabnzbd/sabnzbd.ini', "port = 23001\n");
        $this->pmssWriteFile($this->home.'/.config/radarr/config.xml', "<Config><Port>23002</Port></Config>\n");

        $ports = \pmssMediaStackPortsEnsure($this->user, $this->home);

        $this->assertSame(23001, $ports['sabnzbd']);
        $this->assertSame(23002, $ports['radarr']);
        $this->assertSame('23001', file_get_contents($this->home.'/.media-stack-port-sabnzbd'));
        $this->assertSame('23002', file_get_contents($this->home.'/.media-stack-port-radarr'));
    }

    public function testEnsureAssignsDistinctPortsForEverySupportedApp(): void
    {
        $ports = \pmssMediaStackPortsEnsure($this->user, $this->home);

        $this->assertSame(6, count($ports));
        $this->assertSame(6, count(array_unique($ports)));
        foreach ($ports as $app => $port) {
            $this->assertTrue(\pmssNetworkPortInRange($port, \PMSS_PORT_MANAGER_MIN_PORT, \PMSS_PORT_MANAGER_MAX_PORT));
            $this->assertSame((string) $port, file_get_contents($this->home.'/.media-stack-port-'.$app));
        }
    }

    public function testEnsureDoesNotAdoptAnotherManagedServicePort(): void
    {
        $this->pmssWriteFile($this->home.'/.config/radarr/config.xml', "<Config><Port>23003</Port></Config>\n");
        \pmssPortManagerAssignServicePort('bob', 'lighttpd', 23003);

        $ports = \pmssMediaStackPortsEnsure($this->user, $this->home);

        $this->assertTrue(isset($ports['radarr']));
        $this->assertTrue($ports['radarr'] !== 23003);
    }

    public function testEnsureDoesNotAdoptCurrentlyListeningPort(): void
    {
        $this->pmssWriteFile($this->home.'/.config/radarr/config.xml', "<Config><Port>23005</Port></Config>\n");
        $errno = 0;
        $error = '';
        $server = @stream_socket_server(
            'tcp://0.0.0.0:23005',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($server === false) {
            throw new SkipTest('Port 23005 is unavailable for listener fixture');
        }

        $ports = \pmssMediaStackPortsEnsure($this->user, $this->home);
        fclose($server);

        $this->assertTrue(isset($ports['radarr']));
        $this->assertTrue($ports['radarr'] !== 23005);
    }

    public function testConfiguredPortReaderRejectsOutOfRangeAndSymlinkedFiles(): void
    {
        $definitions = \pmssMediaStackPortDefinitions();
        $config = $this->home.'/.config/radarr/config.xml';
        $this->pmssWriteFile($config, "<Config><Port>65000</Port></Config>\n");
        $this->assertSame(null, \pmssMediaStackConfiguredPortRead($this->home, $definitions['radarr']));

        unlink($config);
        $outside = $this->pmssMakeTempFile('pmss-media-stack-outside-');
        file_put_contents($outside, "<Config><Port>23004</Port></Config>\n");
        $this->pmssCreateSymlinkOrSkip($outside, $config);
        $this->assertSame(null, \pmssMediaStackConfiguredPortRead($this->home, $definitions['radarr']));
    }

    public function testEnsureRejectsMismatchedHomeScope(): void
    {
        $this->assertSame(array(), \pmssMediaStackPortsEnsure($this->user, dirname($this->home).'/someone-else'));
    }

    public function testTerminateUserReleasesEveryMediaStackReservation(): void
    {
        $source = $this->pmssReadRepoFile('scripts/terminateUser.php');
        foreach (array_keys(\pmssMediaStackPortDefinitions()) as $app) {
            $this->assertStringContainsString('media-stack-'.$app, $source);
        }
    }
}
