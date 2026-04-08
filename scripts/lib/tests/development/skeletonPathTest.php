<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/update.php';

class SkeletonPathTest extends TestCase
{
    public function testSkeletonBaseOverride(): void
    {
        $temp = $this->pmssMakeTempDir('pmss-skel-override-');
        $original = getenv('PMSS_SKEL_DIR');
        putenv('PMSS_SKEL_DIR='.$temp);
        try {
            $this->assertEquals($temp, \pmssSkeletonBase());
            $this->assertEquals($temp.'/foo/bar', \pmssSkeletonBase().'/foo/bar');
        } finally {
            if ($original === false) {
                putenv('PMSS_SKEL_DIR');
            } else {
                putenv('PMSS_SKEL_DIR='.$original);
            }
        }
    }

    public function testSkeletonBaseNormalizesTrailingSlash(): void
    {
        putenv('PMSS_SKEL_DIR=/etc/skel/');
        $this->assertEquals('/etc/skel', \pmssSkeletonBase());
        putenv('PMSS_SKEL_DIR');
    }
}
