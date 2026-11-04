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
            '0:pmssPikkumiesUiNginxBlock' => '2966ccbef1997bb33a26150790853960c2466826792041f5a17e179cbf4797aa',
            '0:pmssPikkumiesHermesNginxBlock' => 'd1eae7ef4d07689f4375a7c63cbd1664db1b4f8bc24ab3f5e138b5b702f86790',
            '0:pmssHermesUiNginxBlock' => '627cf56c1aa4ef1db9294344f4da5c2db7fc9fe99cda97ad0010654f9c56aa0a',
            '1:pmssPikkumiesUiNginxBlock' => '51400341250eba31af5db5a875d2c16959c71871b68eb68cf8239e0416f4361f',
            '1:pmssPikkumiesHermesNginxBlock' => '1cb31d6ff1a8738113dcf1fb905c4365090296eb0798d074959b1b6a94c72d5d',
            '1:pmssHermesUiNginxBlock' => '027e445af69b7801373520ff22d90ce8e0094c43f76eb347b96ee5e25f15cabe',
            'pmssPikkumiesUiDefaultNginxBlock' => '7fadf2e46af7fffd71c83d06f8a4975b8bc59f3634fb26afb33d69fc5c175b12',
            'pmssPikkumiesHermesDefaultNginxBlock' => 'f3c91ef9285939390d82ec29bf0d1084aa9b8c900c24ac90337a1e81312daa73',
            'pmssHermesUiDefaultNginxBlock' => '2e1ed059ba0468170e6965ff932b6ec738161026582b39fbd831e5eb11fd639d',
            'login:0' => '6e3cdb83795656b97624d3565eae4f3d0416898edc6c2799d3b02ef35b5e9eea',
            'login:1' => 'cd23b6fdcdcaf7cd05daac723c3b4d84ebf05fcdabeb92fa83b73287b8981248',
        ], $snapshots);
    }
}
