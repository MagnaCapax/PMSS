<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/dpkgSelections.php';

/** Customer-installable PHP applications (native Nextcloud and similar) need these extensions on every host. */
class DpkgBaselinePhpExtensionsTest extends TestCase
{
    /** @return array<string,string> package name => selection state */
    private static function shippedSelections(int $version): array
    {
        $lines = file(dirname(__DIR__, 2).'/update/dpkg/selections-debian'.$version.'.txt', FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $selections = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (is_array($parts) && count($parts) === 2) {
                $selections[$parts[0]] = $parts[1];
            }
        }
        return $selections;
    }

    public function testSupportedBaselinesInstallEveryExtensionNativeNextcloudNeeds(): void
    {
        foreach ([11, 12] as $version) {
            $selections = self::shippedSelections($version);
            foreach (['intl', 'zip', 'gd', 'xml', 'mbstring', 'curl'] as $extension) {
                $this->assertSame('install', $selections['php-'.$extension] ?? null, 'Debian '.$version.' baseline must install php-'.$extension);
            }
        }
    }

    public function testIntlLibraryIsAlreadyPartOfTheBaselineSoTheExtensionAddsNoNewLibrary(): void
    {
        $this->assertSame('install', self::shippedSelections(11)['libicu67:amd64'] ?? null);
        $this->assertSame('install', self::shippedSelections(12)['libicu72:amd64'] ?? null);
    }

    public function testSanitiserKeepsPhpIntlSelectedOnItsOwnRelease(): void
    {
        foreach ([11, 12] as $version) {
            $lines = file(dirname(__DIR__, 2).'/update/dpkg/selections-debian'.$version.'.txt', FILE_IGNORE_NEW_LINES);
            $this->assertTrue(is_array($lines));
            $plan = \pmssDpkgSelectionsSanitise($lines, $version, ...self::pmssMakeCallbacks([true, false]));
            $this->assertTrue(in_array("php-intl\tinstall", $plan['sanitised'], true), 'php-intl survives sanitising on Debian '.$version);
        }
    }
}
