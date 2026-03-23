<?php
namespace PMSS\Tests;

/**
 * Keeps repo-relative fixture reads hermetic and consistent across tests.
 */
trait RepoFileReadTrait
{
    private function readRepoFile(string $relativePath): string
    {
        $contents = @file_get_contents(dirname(__DIR__, 4).'/'.$relativePath);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Failed to read '.$relativePath);
        return (string)$contents;
    }
}
