<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/updateBootstrapShim.php';

class SkeletonPathTest extends TestCase
{
    public function testSkeletonBaseOverride(): void
    {
        $temp = $this->pmssMakeTempDir('pmss-skel-override-');
        $this->pmssWithEnv(['PMSS_SKEL_DIR' => $temp], function () use ($temp): void {
            $this->assertEquals($temp, \pmssSkeletonBase());
            $this->assertEquals($temp.'/foo/bar', \pmssSkeletonBase().'/foo/bar');
        });
    }

    public function testSkeletonBaseNormalizesTrailingSlash(): void
    {
        $this->pmssWithEnv(['PMSS_SKEL_DIR' => '/etc/skel/'], function (): void {
            $this->assertEquals('/etc/skel', \pmssSkeletonBase());
        });
    }
}
