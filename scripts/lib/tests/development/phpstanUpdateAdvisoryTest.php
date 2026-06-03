<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PhpstanUpdateAdvisoryTest extends TestCase
{
    public function testPhpstanAdvisoryWiringStaysScopedAndNonBlocking(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/testing/phpstan-update-advisory.sh' => ['required' => ['phpstan.update.neon.dist', 'scripts/lib/update', 'scripts/testing/phpstan.sh', 'ALLOW_TOOL_SKIP', 'findings detected (non-blocking)', 'exit 0']],
            'scripts/testing/test-all.sh' => ['required' => ['PMSS_LINT_PHPSTAN_UPDATE', 'phpstan-update-advisory.sh']],
            'phpstan.update.neon.dist' => ['required' => ['level: 2', '- scripts/lib/update']],
        ]);
    }
}
