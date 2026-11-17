<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/pikkumiesUi.php';

/** Explicit rendering examples from the panel route contract, independent of release snapshots. */
final class PikkumiesUiRenderingCharacterizationTest extends TestCase
{
    public function testCompleteLighttpdRouteExamples(): void
    {
        foreach (['first', 'second2'] as $user) {
            foreach (['pikkumies' => \pmssLighttpdPikkumiesUiBlock($user),
                'pikkumies-hermes' => \pmssLighttpdPikkumiesHermesBlock($user)] as $route => $actual) {
                $prefix = '/user-'.$user.'/'.$route.'/';
                $socket = '/home/'.$user.'/.mcxPikkumies/pikkumies/local/ui/'.($route === 'pikkumies' ? '' : 'hermes/').'ui.sock';
                // Only comments and surrounding blank lines are incidental to the rendered contract.
                $actual = trim(preg_replace('/^#[^\n]*\n/m', '', $actual));
                $expected = '$HTTP["url"] =~ "^'.$prefix.'" {' ."\n"
                    .'    setenv.set-request-header = ( "Authorization" => "" )'."\n";
                if ($route === 'pikkumies-hermes') $expected .= '    setenv.set-request-header += ( "X-Forwarded-Prefix" => "/user-'.$user.'/pikkumies-hermes" )'."\n";
                $expected .= '    proxy.server = ( "" => (( "socket" => "'.$socket.'" )) )'."\n"
                    .'    proxy.header = ( "upgrade" => "enable", "map-urlpath" => ( "'.$prefix.'" => "/" ) )'."\n"
                    ."    server.max-read-idle = 3600\n    server.max-write-idle = 3600\n}";
                $this->assertSame($expected, $actual);
            }
        }
    }
}
