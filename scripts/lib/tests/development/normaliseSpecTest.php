<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';

class NormaliseSpecTest extends TestCase
{
    public function testNormalisesSpecs(): void
    {
        foreach ([
            'git main' => 'git/main',
            'main' => 'git/main',
            'release 2025-07-12' => 'release:2025-07-12',
            'release:' => 'release',
            'release/' => 'release',
            'git/dev:2024-07-01' => 'git/dev:2024-07-01',
            'git/https://example.com/repo.git:beta' => 'git/https://example.com/repo.git:beta',
            '  git    dev  ' => 'git/dev',
            'https://example.com/repo.git' => 'git/https://example.com/repo.git',
            'release_1' => 'release:_1',
            "git\nmain" => 'git/main',
            'release' => 'release',
            'git/dev:2025-02-03 12:34' => 'git/dev:2025-02-03 12:34',
            '@@bad??!!' => '',
        ] as $input => $expected) {
            $this->assertEquals($expected, normaliseSpec($input), 'Spec: '.$input);
        }
    }

    public function testDefaultSpecFormat(): void
    {
        if (is_file(\VERSION_FILE)) {
            throw new SkipTest('VERSION_FILE exists on this host; default spec depends on stored spec');
        }

        $parsed = \parseArguments(['update.php']);
        $this->assertEquals('git/main', $parsed['spec'] ?? '');
    }
}
