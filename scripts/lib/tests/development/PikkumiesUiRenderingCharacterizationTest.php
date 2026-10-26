<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/nginxUserHosts.php';
require_once dirname(__DIR__, 2).'/nginxConfig/pikkumiesUi.php';

/** Byte locks for all account UI route families, captured before their decomposition. */
final class PikkumiesUiRenderingCharacterizationTest extends TestCase
{
    public function testCompleteRouteRenderingSnapshots(): void
    {
        $live = $this->pmssMakeTempDir('pmss-ui-rendering-');
        mkdir($live.'/second-user.other.example');
        foreach (['fullchain.pem', 'privkey.pem'] as $file) {
            file_put_contents($live.'/second-user.other.example/'.$file, 'fixture');
        }
        $snapshots = [];
        foreach ([
            ['first-user', 'node.example', 'service.example', 12345],
            ['second-user', 'other.example', 'different.example', 54321],
        ] as $case => $arguments) {
            foreach (['pmssPikkumiesUiNginxBlock', 'pmssPikkumiesHermesNginxBlock', 'pmssHermesUiNginxBlock'] as $render) {
                $block = $render(...array_merge($arguments, [$live]));
                $snapshots[$case.':'.$render] = hash('sha256', str_replace($live, '/fixture/tls', $block));
            }
        }
        foreach (['pmssPikkumiesUiDefaultNginxBlock', 'pmssPikkumiesHermesDefaultNginxBlock', 'pmssHermesUiDefaultNginxBlock'] as $render) {
            $snapshots[$render] = hash('sha256', $render("    ssl_certificate /fixture/fullchain.pem;\n"));
        }
        foreach ([['/__pmss-ui-b', "'none'", 'DENY'], ['/__pmss-ui-c', 'https://node.example', 'ALLOW-FROM https://node.example']] as $case => $arguments) {
            $snapshots['login:'.$case] = hash('sha256', \pmssPikkumiesHermesLoginLocations($arguments[0], 12345, $arguments[1], $arguments[2]));
        }
        $this->assertSame([
            '0:pmssPikkumiesUiNginxBlock' => '96ee74768fdd97a6b58a69f69abdd753e688b57ee9febd8a864fb647d9e9960f',
            '0:pmssPikkumiesHermesNginxBlock' => '307608b940c92265050f0b68e1aed5c9c4c46e37bceef296e8d106951fd37a56',
            '0:pmssHermesUiNginxBlock' => '94e39d85737475913e1f2bf6286b3db6b6a4a0ba081a946b3de84b1e639f4dee',
            '1:pmssPikkumiesUiNginxBlock' => 'cb7261a24eff6dd6f370a285286716135913150fa1e5422c8db6b715173f4220',
            '1:pmssPikkumiesHermesNginxBlock' => '32641f9321dd936aa4bf2b80da6c4a3781801584e70229dbf07abf40a2bec8df',
            '1:pmssHermesUiNginxBlock' => 'b5995e01b2e148c452e1d6a4fccace5fb22c039db76773e9fd05123b993d1024',
            'pmssPikkumiesUiDefaultNginxBlock' => '7fadf2e46af7fffd71c83d06f8a4975b8bc59f3634fb26afb33d69fc5c175b12',
            'pmssPikkumiesHermesDefaultNginxBlock' => 'f3c91ef9285939390d82ec29bf0d1084aa9b8c900c24ac90337a1e81312daa73',
            'pmssHermesUiDefaultNginxBlock' => '2e1ed059ba0468170e6965ff932b6ec738161026582b39fbd831e5eb11fd639d',
            'login:0' => '6e3cdb83795656b97624d3565eae4f3d0416898edc6c2799d3b02ef35b5e9eea',
            'login:1' => 'cd23b6fdcdcaf7cd05daac723c3b4d84ebf05fcdabeb92fa83b73287b8981248',
        ], $snapshots);
    }
}
