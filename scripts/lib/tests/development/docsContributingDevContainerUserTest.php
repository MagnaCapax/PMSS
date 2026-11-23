<?php
namespace PMSS\Tests {

/** The documented development-container workflow must not run the suite as root. */
class DocsContributingDevContainerUserTest extends TestCase
{
    public function testDocumentedDockerRunUsesTheCallersUidAndGid(): void
    {
        $doc = $this->pmssReadRepoFile('CONTRIBUTING.md');
        $this->assertStringContainsAndOmitsStrings([
            'docker run --rm -it --user "$(id -u):$(id -g)" -v "$PWD":/workspace -w /workspace pmss-dev',
            'uid 0',
        ], [], $doc);
    }

    public function testPreflightWarnsWhenTheSuiteWouldRunAsRoot(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/testing/check-tools.sh', 'running as root');
    }
}

}
