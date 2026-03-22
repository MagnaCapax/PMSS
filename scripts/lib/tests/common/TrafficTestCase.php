<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';

/**
 * Shared traffic-test fixtures for hermetic processor and statistics suites.
 */
abstract class TrafficTestCase extends TestCase
{
    /** Build an isolated traffic fixture tree for a test. */
    protected function makeTrafficPaths(string $prefix = 'pmss-traffic-', bool $withPasswd = false): array
    {
        $root = $this->pmssMakeTempDir($prefix);
        $paths = [
            'traffic_dir' => $root.'/traffic',
            'home_dir'    => $root.'/home',
            'runtime_dir' => $root.'/run',
        ];

        @mkdir($paths['traffic_dir'], 0755, true);
        @mkdir($paths['home_dir'], 0755, true);
        @mkdir($paths['runtime_dir'], 0755, true);
        if ($withPasswd) {
            $paths['passwd_file'] = $root.'/passwd';
            file_put_contents($paths['passwd_file'], "alice:x:1000:1000::{$paths['home_dir']}/alice:/bin/bash\n");
        }

        return $paths;
    }

    /** Create a user home and optional traffic marker file inside a fixture tree. */
    protected function createTrafficUser(array $paths, string $user, bool $withTrafficFile = true): void
    {
        if ($withTrafficFile) {
            file_put_contents($paths['traffic_dir'].'/'.$user, 'seed');
        }
        @mkdir($paths['home_dir'].'/'.$user, 0755, true);
    }

    /** Remove a traffic fixture tree once the test no longer needs it. */
    protected function cleanupTrafficPaths(array $paths): void
    {
        $this->pmssRemoveTree(dirname($paths['traffic_dir']));
    }
}
