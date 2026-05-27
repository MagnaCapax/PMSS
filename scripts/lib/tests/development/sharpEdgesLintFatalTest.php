<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SharpEdgesLintFatalTest extends TestCase
{
    public function testSharpEdgesLintIncludesFatalPatterns(): void
    {
        $path = 'scripts/testing/sharp-edges-lint.sh';
        $src  = (string) file_get_contents($path);
        $this->assertStringContainsString('fatalScan()', $src, 'scripts/testing/sharp-edges-lint.sh must define fatalScan()');
        $this->assertStringContainsAllStrings(["rm[[:space:]]+-rf[[:space:]]+/([[:space:];]|$)", "rm[[:space:]]+-rf[[:space:]]+/home([[:space:];]|$)", "rm[[:space:]]+-rf[[:space:]]+/home/([[:space:];]|$|\\*)", "rm[[:space:]]+-rf[[:space:]]+\\\"/home", "rm[[:space:]]+-rf[[:space:]]+/home/\\$[A-Za-z_][A-Za-z0-9_]*", "rm[[:space:]]+-rf[[:space:]]+\\$[A-Za-z_][A-Za-z0-9_]*([[:space:];]|$)", 'rm[[:space:]]+-rf[[:space:]]+[$]HOME', "rm[[:space:]]+-rf[[:space:]]+~"], $src);
    }

    public function testFatalPatternsCatchCommonAbusiveForms(): void
    {
        $patterns = [
            '/rm[[:space:]]+-rf[[:space:]]+\/([[:space:];]|$)/',
            '/rm[[:space:]]+-rf[[:space:]]+\/home([[:space:];]|$)/',
            '/rm[[:space:]]+-rf[[:space:]]+\/home\/([[:space:];]|$|\*)/',
            '/rm[[:space:]]+-rf[[:space:]]+\"\/home/',
            '/rm[[:space:]]+-rf[[:space:]]+\/home\/\$[A-Za-z_][A-Za-z0-9_]*/',
            '/rm[[:space:]]+-rf[[:space:]]+\$[A-Za-z_][A-Za-z0-9_]*([[:space:];]|$)/',
            '/rm[[:space:]]+-rf[[:space:]]+\$HOME([\/[:space:];]|$)/',
            '/rm[[:space:]]+-rf[[:space:]]+~([\/[:space:];]|$)/',
        ];

        $lines = [
            'rm -rf /',
            'rm   -rf   /home',
            'rm -rf /home/',
            'rm -rf /home/*',
            'rm -rf "/home/*"',
            'rm -rf /home/$user',
            'rm -rf $user',
            'rm -rf $HOME',
            'rm -rf $HOME/data',
            'rm -rf ~',
            'rm -rf ~/data',
            'if [ "$x" = 1 ]; then rm -rf /home/$target; fi',
            'danger() { rm -rf "/home"; }',
        ];

        foreach ($lines as $line) {
            $matched = false;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $matched = true;
                    break;
                }
            }
            $this->assertTrue($matched, 'Expected fatal pattern to match line: '.$line);
        }
    }
}
