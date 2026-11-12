<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

/** Hostname file-boundary coverage for scripts/lib/runtime/filesystemHost.php. */
class RuntimeHostnameInputSafetyTest extends TestCase
{
    public function testNulContentsUseTheUnmodifiedReadFailureDefault(): void
    {
        $path = $this->pmssMakeTempFile('pmss-hostname-input-');
        foreach ([
            'leading' => "\0node.example.test\n",
            'inside label' => "no\0de.example.test\n",
            'between labels' => "node.\0example.test\n",
            'after hostname' => "node.example.test\0\n",
            'after newline' => "node.example.test\n\0",
            'after whitespace' => "node.example.test \t\0\t",
            'only NUL' => "\0",
            'whitespace and NUL' => " \t\0\r\n",
        ] as $label => $raw) {
            // No host file is read; each corrupt payload lives in a tracked fixture.
            $this->pmssWriteFile($path, $raw);
            $this->assertSame('', \pmssHostnameRead('', $path), $label);
            $this->assertSame(' fallback.example.test ', \pmssHostnameRead(' fallback.example.test ', $path), $label);
        }
    }

    public function testOrdinaryTextRetainsTrimmingAndCallerOwnedValidation(): void
    {
        $path = $this->pmssMakeTempFile('pmss-hostname-valid-');
        foreach ([
            ["node.example.test\n", 'node.example.test'],
            [" \tNode.Example.Test\r\n", 'Node.Example.Test'],
            ['127.0.0.1', '127.0.0.1'],
            ['', ''],
            [" \t\r\n", ''],
            ["node name\n", 'node name'],
            ["node.example.test\nsecond-line", "node.example.test\nsecond-line"],
        ] as [$raw, $expected]) {
            // Empty or otherwise invalid DNS text is still the caller's policy.
            $this->pmssWriteFile($path, $raw);
            $this->assertSame($expected, \pmssHostnameRead('fallback.example.test', $path));
        }
    }

    public function testMissingAndNulPathsKeepTheExistingDefault(): void
    {
        $root = $this->pmssMakeTempDir('pmss-hostname-path-');
        $path = $this->pmssWriteFile($root.'/hostname', "node.example.test\n");
        foreach ([$root.'/missing', "\0".$path, $path."\0", $path."\0suffix"] as $invalid) {
            // A NUL path must not read the valid fixture it appears to name.
            $this->assertSame('', \pmssHostnameRead('', $invalid));
            $this->assertSame(' fallback.example.test ', \pmssHostnameRead(' fallback.example.test ', $invalid));
        }
    }
}
