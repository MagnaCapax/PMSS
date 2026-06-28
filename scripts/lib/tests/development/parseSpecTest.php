<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/updateBootstrapShim.php';

class ParseSpecTest extends TestCase
{
    public function testParsesSpecs(): void
    {
        foreach ([
            'release' => [
                'type' => 'release',
                'repo' => DEFAULT_REPO,
                'branch' => '',
                'pin' => '',
            ],
            'release:' => [
                'type' => 'release',
                'repo' => DEFAULT_REPO,
                'branch' => '',
                'pin' => '',
            ],
            'release/' => [
                'type' => 'release',
                'repo' => DEFAULT_REPO,
                'branch' => '',
                'pin' => '',
            ],
            'git/main:2025-05-11' => [
                'type' => 'git',
                'repo' => DEFAULT_REPO,
                'branch' => 'main',
                'pin' => '2025-05-11',
            ],
            'git/https://example.com/repo.git:beta' => [
                'type' => 'git',
                'repo' => 'https://example.com/repo.git',
                'branch' => 'beta',
                'pin' => '',
            ],
            'release:2025-07-12' => [
                'type' => 'release',
                'repo' => DEFAULT_REPO,
                'branch' => '',
                'pin' => '2025-07-12',
            ],
            normaliseSpec('dev') => [
                'type' => 'git',
                'repo' => DEFAULT_REPO,
                'branch' => 'dev',
                'pin' => '',
            ],
        ] as $input => $expected) {
            $parsed = parseSpec($input);
            foreach ($expected as $key => $value) {
                $this->assertEquals($value, $parsed[$key], $input.' '.$key);
            }
        }
    }
}
