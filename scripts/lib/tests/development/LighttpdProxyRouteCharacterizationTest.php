<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/proxyFragments.php';

class LighttpdProxyRouteCharacterizationTest extends TestCase
{
    /** The historical include must expose the same helpers from their focused owners. */
    public function testOriginalIncludeLoadsEveryMovedHelper(): void
    {
        foreach ([
            'scripts/lib/lighttpd/proxyRule.php' => ['pmssLighttpdProxyRuleFragment'],
            'scripts/lib/lighttpd/proxyRoutes.php' => ['pmssLighttpdManagedProxyFragment'],
            'scripts/lib/lighttpd/proxyPorts.php' => ['pmssLighttpdProxyPortEnsure', 'pmssLighttpdProxyPortsEnsure'],
        ] as $path => $functions) {
            foreach ($functions as $function) {
                $this->assertSame(realpath($this->pmssRepoPath($path)), (new \ReflectionFunction($function))->getFileName());
            }
        }
    }

    /** Literal proxy bodies freeze spacing, header order, maps and trailing delimiters. */
    public function testManagedRoutesKeepTheirLegacyBytes(): void
    {
        $server = "  proxy.server = ( \"\" => ( (\n    \"host\" => \"127.0.0.1\",\n    \"port\" => 23456\n  ) ) ),\n";
        $forwarded = "  proxy.forwarded = ( \"for\" => 1,\n                      \"host\" => 1,\n                      \"by\" => 1\n  ),\n";
        $upgrade = "  proxy.header = (\n      \"upgrade\" => \"enable\"";
        $end = "\n  )\n}\n\n";
        $strip = '  setenv.set-request-header = ( "Authorization" => "" )'."\n";
        $cases = [
            'deluge' => "# PMSS-managed: Deluge reverse proxy.\n# Legacy path /deluge-fixtur01/ kept for compatibility until at least 2028-01-28.\n\n"
                .'$HTTP["url"] =~ "^/user-fixtur01/deluge($|/)" {'."\n  auth.require = ()\n".$server.$upgrade
                .",\n      \"map-urlpath\" => (\n         \"/user-fixtur01/deluge/\"  => \"/\",\n         \"/user-fixtur01/deluge\" => \"\"\n       )".$end
                .'$HTTP["url"] =~ "^/deluge-fixtur01($|/)" {'."\n  auth.require = ()\n".$server.$upgrade
                .",\n      \"map-urlpath\" => (\n         \"/deluge-fixtur01/\"  => \"/\",\n         \"/deluge-fixtur01\" => \"\"\n       )".$end,
            'rclone' => "# PMSS-managed: rclone reverse proxy.\n\n"
                .'$HTTP["url"] =~ "^/user-fixtur01/rclone/" {'."\n  auth.require = ()\n"
                .'  $HTTP["request-method"] == "POST" {'."\n"
                .'    $REQUEST_HEADER["Content-Length"] == "" {'."\n"
                .'      setenv.set-request-header = ( "Content-Length" => "0" )'."\n    }\n  }\n".$server.$upgrade.$end,
            'qbittorrent' => "# PMSS-managed: qBittorrent reverse proxy.\n\n"
                .'$HTTP["url"] == "/user-fixtur01/qbittorrent" {'."\n"
                .'  url.redirect = ( "" => "/user-fixtur01/qbittorrent/" )'."\n}\n\n"
                .'$HTTP["url"] =~ "^/user-fixtur01/qbittorrent/" {'."\n".$strip.$server
                ."  proxy.replace-http-host = \"disable\"\n".$forwarded.$upgrade
                .",\n      \"map-urlpath\" => (\n         \"/user-fixtur01/qbittorrent/\"  => \"/\"\n       )".$end,
            'transmission' => "# PMSS-managed: Transmission Docker reverse proxy.\n\n"
                .'$HTTP["url"] == "/user-fixtur01/transmission" {'."\n"
                .'  url.redirect = ( "" => "/user-fixtur01/transmission/web/" )'."\n}\n\n"
                .'$HTTP["url"] =~ "^/user-fixtur01/transmission/" {'."\n".$strip.$server.$upgrade
                .",\n      \"map-urlpath\" => (\n         \"/user-fixtur01/transmission/\"  => \"/transmission/\"\n       )".$end,
            'invidious' => "# PMSS-managed: Invidious reverse proxy.\n\n"
                .'$HTTP["url"] =~ "^/public-fixtur01/invidious($|/)" {'."\n".$server.$forwarded.$upgrade
                .",\n      \"map-urlpath\" => (\n         \"/public-fixtur01/invidious/\"  => \"/\",\n         \"/public-fixtur01/invidious\" => \"\"\n       )".$end
                .'$HTTP["url"] =~ "^/user-fixtur01/apps/invidious($|/)" {'."\n".$server.$forwarded.$upgrade
                .",\n      \"map-urlpath\" => (\n         \"/user-fixtur01/apps/invidious/\"  => \"/\",\n         \"/user-fixtur01/apps/invidious\" => \"\"\n       )".$end,
        ];
        foreach ($cases as $name => $expected) {
            $this->assertSame($expected, \pmssLighttpdManagedProxyFragment($name, 'fixtur01', 23456), $name);
        }
    }

    /** Map commas depend on position, including a numeric final key, with no trailing comma. */
    public function testRuleMapsKeepEncounterOrderAndLegacySpacing(): void
    {
        $expected = <<<'CONF'
$HTTP["url"] =~ "^/fixture/" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => 23456
  ) ) ),
  proxy.header = (
      "upgrade" => "enable",
      "map-urlpath" => (
         "/second/"  => "/b/",
         "/first" => "/a",
         "0" => "zero"
       )
  )
}
CONF;
        $this->assertSame($expected, \pmssLighttpdProxyRuleFragment('^/fixture/', 23456,
            ['/second/' => '/b/', '/first' => '/a', 0 => 'zero']));
    }

    /** Catalog flags keep the exact panel gate, credential strip and scheme-map combinations. */
    public function testCatalogPoliciesKeepTheirLegacyBytes(): void
    {
        $strip = '  setenv.set-request-header = ( "Authorization" => "" )'."\n";
        $bearer = "  auth.require = ()\n"
            .'  $REQUEST_HEADER["Authorization"] =~ "^Basic " {'."\n"
            .'    setenv.set-request-header = ( "Authorization" => "" )'."\n  }\n";
        $scheme = <<<'CONF'
  $HTTP["scheme"] == "http" {
    setenv.set-request-header = ( "X-Fixture-Proto" => "http" )
  }
  $HTTP["scheme"] == "https" {
    setenv.set-request-header = ( "X-Fixture-Proto" => "https" )
  }

CONF;
        $stripScheme = <<<'CONF'
  $HTTP["scheme"] == "http" {
    setenv.set-request-header = ( "X-Fixture-Proto" => "http", "Authorization" => "" )
  }
  $HTTP["scheme"] == "https" {
    setenv.set-request-header = ( "X-Fixture-Proto" => "https", "Authorization" => "" )
  }

CONF;
        $prefix = <<<'CONF'
# PMSS-managed: Fixture reverse proxy.

$HTTP["url"] == "/user-fixtur01/fixture-route" {
  url.redirect = ( "" => "/user-fixtur01/fixture-route/" )
}

$HTTP["url"] =~ "^/user-fixtur01/fixture-route/" {

CONF;
        $suffix = <<<'CONF'
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => 23456
  ) ) ),
  proxy.forwarded = ( "for" => 1,
                      "host" => 1,
                      "by" => 1
  ),
  proxy.header = (
      "upgrade" => "enable"
  )
}


CONF;
        $path = $this->pmssMakeTempDir('pmss-proxy-catalog-', 0700).'/catalog.php';
        $this->pmssWithEnv(['PMSS_TEST_MODE' => '1', 'PMSS_TOGGLE_APP_CATALOG_PATH' => $path], function () use ($path, $strip, $bearer, $scheme, $stripScheme, $prefix, $suffix): void {
            foreach ([
                [[], ''],
                [['stripAuthorization' => true], $strip],
                [['bearerAuthorization' => true], "  auth.require = ()\n"],
                [['bearerAuthorization' => true, 'stripAuthorization' => true], $bearer],
                [['forwardedProtoHeader' => 'X-Fixture-Proto'], $scheme],
                [['stripAuthorization' => true, 'forwardedProtoHeader' => 'X-Fixture-Proto'], $strip.$stripScheme],
                [['bearerAuthorization' => true, 'stripAuthorization' => true, 'forwardedProtoHeader' => 'X-Fixture-Proto'], $bearer.$stripScheme],
            ] as [$flags, $headers]) {
                $this->pmssWriteFile($path, '<?php return '.var_export(['fixture-route' => ['label' => 'Fixture'] + $flags], true).';');
                chmod($path, 0600);
                $this->assertSame($prefix.$headers.$suffix, \pmssLighttpdManagedProxyFragment('fixture-route', 'fixtur01', 23456));
            }
        });
    }

    /** Unknown routes must keep the empty result; they must not publish a generic proxy. */
    public function testUnknownRoutesKeepTheirEmptyResult(): void
    {
        foreach (['', 'fixture-unknown', 'DELUGE', 'qBittorrent', 'invidious/'] as $name) {
            $this->assertSame('', \pmssLighttpdManagedProxyFragment($name, 'fixtur01', 23456));
        }
    }
}
