<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class UpdateHelpersEnvCodenameTest extends TestCase
{
    public function testOsReleaseAccessors(): void
    {
        foreach ([
            [['ID' => 'debian', 'VERSION_ID' => '12 (bookworm)'], 'getDistroVersion', '12'],
            [['ID' => 'debian', 'VERSION_ID' => 'sid'], 'getDistroVersion', 'sid'],
            [['VERSION_ID' => '11'], 'getDistroName', ''],
            [['ID' => 'debian', 'VERSION_CODENAME' => '  BULLSEYE  '], 'getDistroCodename', 'bullseye'],
            [['ID' => 'debian', 'VERSION_ID' => '12'], 'getDistroCodename', ''],
        ] as [$fields, $function, $expected]) {
            $this->pmssWithOsRelease($fields, function () use ($function, $expected): void {
                $this->assertEquals($expected, \call_user_func('\\'.$function));
            });
        }
    }

    public function testVersionHelpers(): void
    {
        $this->assertEquals(0, \pmssVersionFromCodename('unknown-planet'));

        foreach ([
            ['version-file', "git/main:2025-01-01\n\n", 'git/main:2025-01-01'],
            ['empty-version', '', 'unknown'],
        ] as [$prefix, $content, $expected]) {
            $file = $this->pmssWriteTempFile($prefix, $content, 'pmss-env');
            $this->assertEquals($expected, \getPmssVersion($file));
        }
    }
}
