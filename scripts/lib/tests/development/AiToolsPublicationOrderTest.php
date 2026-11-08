<?php
namespace PMSS\Tests\AiToolsPublicationFixture;

/** Record publication without touching shared binaries or requiring root. */
function pmssAiToolsCodexAtomicWrite(string $path, string $content, int $mode): bool
{
    $state = &$GLOBALS['pmssAiToolsPublicationFixture'];
    $state['events'][] = ['write', $path, $content, $mode];
    if ($path === $state['throw']) throw $state['exception'];
    return $path !== $state['fail'];
}

function logmsg(string $message): void
{
    $GLOBALS['pmssAiToolsPublicationFixture']['events'][] = ['log', $message];
}

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../testing/filesystem.php';
require_once __DIR__.'/../../update/apps/aiTools/publication.php';

class AiToolsPublicationOrderTest extends TestCase
{
    /** Export only the publication function; no installer or shell command executes. */
    private function publish(array $files, ?string $fail = null, ?string $throw = null): array
    {
        $namespace = 'PMSS\\Tests\\AiToolsPublicationFixture';
        if (!function_exists($namespace.'\\pmssAiToolsFilesPublish')) eval(\pmssTestingFunctionExport('pmssAiToolsFilesPublish', $namespace));
        $state = ['events' => [], 'fail' => $fail, 'throw' => $throw, 'exception' => new \RuntimeException('publication fixture')];
        $GLOBALS['pmssAiToolsPublicationFixture'] = &$state;
        try {
            \PMSS\Tests\AiToolsPublicationFixture\pmssAiToolsFilesPublish($files, $namespace.'\\logmsg');
        } catch (\Throwable $error) {
            $this->assertSame($state['exception'], $error);
            $state['events'][] = ['throw'];
        } finally {
            unset($GLOBALS['pmssAiToolsPublicationFixture']);
        }
        return $state['events'];
    }

    public function testPublicationOrderAndEveryFailureBoundary(): void
    {
        foreach (['Gemini CLI' => true, 'Claude Code' => false, 'Codex' => false] as $label => $defaults) {
            $files = [['launcher', 'launcher fixture', 0644, 'Unable to install '.$label.' launcher']];
            if ($defaults) $files[] = ['defaults', 'defaults fixture', 0644, 'Unable to install Gemini defaults'];
            $files[] = ['command', 'command fixture', 0755, 'Unable to install '.$label.' command stub'];
            $writes = array_map(static function (array $file): array { return ['write', $file[0], $file[1], $file[2]]; }, $files);
            $this->assertSame($writes, $this->publish($files));
            foreach ($files as $index => $file) {
                $this->assertSame(array_merge(array_slice($writes, 0, $index + 1),
                    [['log', '[WARN] '.$file[3].'; keeping existing command']]), $this->publish($files, $file[0]));
                $this->assertSame(array_merge(array_slice($writes, 0, $index + 1), [['throw']]), $this->publish($files, null, $file[0]));
            }
            $files[0][1] = false;
            $this->assertSame([['log', '[WARN] Unable to install '.$label.' launcher; keeping existing command']], $this->publish($files));
        }
        $this->assertSame([], $this->publish([]));
    }
}
