<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/logging.php';

class RemoteLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['PMSS_PROFILE'], $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']);
    }

    public function testMissingLoggingConfigSkipsSilently(): void
    {
        $fixture = $this->pmssRemoteLoggingFixture();
        $messages = [];

        $this->applyRemoteLogging($fixture, $messages);

        $this->assertTrue(!file_exists($fixture['target']), 'unexpected remote logging config created');
        $this->assertEquals([], $messages);
    }

    public function testDisabledLoggingRemovesStaleConfig(): void
    {
        $fixture = $this->pmssRemoteLoggingFixture();
        $target = $fixture['target'];
        $messages = [];

        file_put_contents($fixture['cfgDir'].'/logging.conf', "remote_logging_enabled=0\n");
        file_put_contents($target, "*.* @@old.example:514\n");

        $this->applyRemoteLogging($fixture, $messages);

        $this->assertTrue(!file_exists($target), 'stale remote logging config should be removed');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Removed remote logging config (disabled)'), 'expected removal log');
        $this->assertEquals([], $GLOBALS['PMSS_PROFILE'] ?? []);
    }

    public function testInvalidEnabledLoggingWarnsAndRemovesStaleConfig(): void
    {
        $fixture = $this->pmssRemoteLoggingFixture();
        $target = $fixture['target'];
        $messages = [];

        file_put_contents($fixture['cfgDir'].'/logging.conf', "remote_logging_enabled=1\n");
        file_put_contents($target, "*.* @@old.example:514\n");

        $this->applyRemoteLogging($fixture, $messages);

        $this->assertTrue(!file_exists($target), 'invalid config should remove stale forwarding file');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Remote logging enabled but invalid: Remote host not configured'), 'expected invalid-config warning');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Removed remote logging config (disabled)'), 'expected stale-config removal log');
    }

    public function testValidLoggingWritesRenderedConfig(): void
    {
        $fixture = $this->pmssRemoteLoggingFixture();
        $target = $fixture['target'];
        $messages = [];

        file_put_contents($fixture['cfgDir'].'/logging.conf', implode("\n", [
            'remote_logging_enabled=1',
            'remote_host=logserver.example.com',
            'remote_port=1514',
            'remote_protocol=udp',
            '',
        ]));
        file_put_contents($fixture['cfgDir'].'/template.rsyslog-remote.conf', implode("\n", [
            '*.* @%%PMSS_RSYSLOG_REMOTE_HOST%%:%%PMSS_RSYSLOG_REMOTE_PORT%%',
            '# protocol=%%PMSS_RSYSLOG_PROTOCOL%%',
            '',
        ]));

        $this->applyRemoteLogging($fixture, $messages);

        $this->pmssAssertFileContainsAllStrings($target, [
            '*.* @logserver.example.com:1514',
            '# protocol=udp',
        ], 'expected rendered remote logging config');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Applied remote logging: logserver.example.com:1514 (udp)'), 'expected apply log');
    }

    public function testMissingTemplateWarnsWithoutWritingConfig(): void
    {
        $fixture = $this->pmssRemoteLoggingFixture();
        $messages = [];

        file_put_contents($fixture['cfgDir'].'/logging.conf', implode("\n", [
            'remote_logging_enabled=1',
            'remote_host=logserver.example.com',
            '',
        ]));

        $this->applyRemoteLogging($fixture, $messages);

        $this->assertTrue(!file_exists($fixture['target']), 'target config should not be created without a template');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Remote logging template missing'), 'expected missing-template warning');
    }

    public function testWriteManagedConfigFileRejectsSymlinkTarget(): void
    {
        $fixture = $this->pmssRemoteLoggingFixture();
        $realDir = $this->pmssMakeTempDir('pmss-remote-logging-real-');
        $realTarget = $realDir.'/50-pmss-remote.conf';
        $target = $fixture['target'];
        $messages = [];

        file_put_contents($realTarget, "*.* @@old.example:514\n");
        symlink($realTarget, $target);

        $result = \pmssWriteManagedPathFile($target, "*.* @new.example:1514\n", 'remote logging config', $this->pmssMakeArrayLogger($messages));

        $this->assertTrue(!$result, 'symlink target must be rejected');
        $this->assertEquals("*.* @@old.example:514\n", file_get_contents($realTarget));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe remote logging config target'), 'expected unsafe-target warning');
    }

    public function testRemoteLoggingRejectsSymlinkTargetPath(): void
    {
        $fixture = $this->pmssRemoteLoggingFixture();
        $realDir = $this->pmssMakeTempDir('pmss-remote-logging-real-');
        $realTarget = $realDir.'/50-pmss-remote.conf';
        $target = $fixture['target'];
        $messages = [];

        file_put_contents($fixture['cfgDir'].'/logging.conf', implode("\n", [
            'remote_logging_enabled=1',
            'remote_host=logserver.example.com',
            '',
        ]));
        file_put_contents($fixture['cfgDir'].'/template.rsyslog-remote.conf', "*.* @@%%PMSS_RSYSLOG_REMOTE_HOST%%:%%PMSS_RSYSLOG_REMOTE_PORT%%\n");
        file_put_contents($realTarget, "*.* @@old.example:514\n");
        symlink($realTarget, $target);

        $this->applyRemoteLogging($fixture, $messages);

        $this->assertEquals("*.* @@old.example:514\n", file_get_contents($realTarget));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe remote logging config target'), 'expected unsafe-target warning');
        $this->assertTrue(!$this->pmssMessagesContain($messages, 'Applied remote logging:'), 'symlink target must prevent apply logging');
    }

    public function testDisabledLoggingPreservesSymlinkedTargetDirectory(): void
    {
        $fixture = $this->pmssRemoteLoggingFixture();
        $realTargetDir = $this->pmssMakeTempDir('pmss-remote-logging-real-rsyslog-');
        $targetDir = $this->pmssMakeTempPath('pmss-remote-logging-', '-rsyslog-link');
        $fixture['targetDir'] = $targetDir;
        $realTarget = $realTargetDir.'/50-pmss-remote.conf';
        $messages = [];

        file_put_contents($fixture['cfgDir'].'/logging.conf', "remote_logging_enabled=0\n");
        file_put_contents($realTarget, "*.* @@old.example:514\n");
        symlink($realTargetDir, $targetDir);

        try {
            $this->applyRemoteLogging($fixture, $messages);

            $this->assertEquals("*.* @@old.example:514\n", file_get_contents($realTarget));
            $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe remote logging config directory'), 'expected unsafe-directory warning');
            $this->assertTrue(!$this->pmssMessagesContain($messages, 'Removed remote logging config (disabled)'), 'unsafe directory must prevent stale-config removal log');
        } finally {
            if (is_link($targetDir)) {
                @unlink($targetDir);
            }
        }
    }

    private function applyRemoteLogging(array $fixture, array &$messages): void
    {
        $this->pmssWithEnv([
            'PMSS_CONFIG_DIR' => $fixture['cfgDir'],
            'PMSS_RSYSLOG_CONF_DIR' => $fixture['targetDir'],
        ], function () use (&$messages): void {
            \pmssApplyRemoteLogging($this->pmssMakeArrayLogger($messages));
        });
    }
}
