<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/update.php';

class SkeletonPathTest extends TestCase
{
    public function testSkeletonBaseOverride(): void
    {
        $temp = sys_get_temp_dir().'/pmss-skel-override-'.bin2hex(random_bytes(4));
        $original = getenv('PMSS_SKEL_DIR');
        putenv('PMSS_SKEL_DIR='.$temp);
        try {
            $this->assertEquals($temp, \pmssSkeletonBase());
            $this->assertEquals($temp.'/foo/bar', \pmssSkeletonPath('foo/bar'));
        } finally {
            if ($original === false) {
                putenv('PMSS_SKEL_DIR');
            } else {
                putenv('PMSS_SKEL_DIR='.$original);
            }
        }
    }

    public function testSkeletonPathHandlesTrailingSlash(): void
    {
        putenv('PMSS_SKEL_DIR=/etc/skel/');
        $this->assertEquals('/etc/skel/foo', \pmssSkeletonPath('foo'));
        putenv('PMSS_SKEL_DIR');
    }
}
