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
        $cfgDir = $this->pmssMakeTempDir('pmss-remote-logging-cfg-');
        $targetDir = $this->pmssMakeTempDir('pmss-remote-logging-rsyslog-');
        $messages = [];

        $this->applyRemoteLogging([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
        ], $messages);

        $this->assertTrue(!file_exists($targetDir.'/50-pmss-remote.conf'), 'unexpected remote logging config created');
        $this->assertEquals([], $messages);
    }

    public function testDisabledLoggingRemovesStaleConfig(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-remote-logging-cfg-');
        $targetDir = $this->pmssMakeTempDir('pmss-remote-logging-rsyslog-');
        $target = $targetDir.'/50-pmss-remote.conf';
        $messages = [];

        file_put_contents($cfgDir.'/logging.conf', "remote_logging_enabled=0\n");
        file_put_contents($target, "*.* @@old.example:514\n");

        $this->applyRemoteLogging([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
        ], $messages);

        $this->assertTrue(!file_exists($target), 'stale remote logging config should be removed');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Removed remote logging config (disabled)'), 'expected removal log');
        $this->assertEquals([], $GLOBALS['PMSS_PROFILE'] ?? []);
    }

    public function testInvalidEnabledLoggingWarnsAndRemovesStaleConfig(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-remote-logging-cfg-');
        $targetDir = $this->pmssMakeTempDir('pmss-remote-logging-rsyslog-');
        $target = $targetDir.'/50-pmss-remote.conf';
        $messages = [];

        file_put_contents($cfgDir.'/logging.conf', "remote_logging_enabled=1\n");
        file_put_contents($target, "*.* @@old.example:514\n");

        $this->applyRemoteLogging([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
        ], $messages);

        $this->assertTrue(!file_exists($target), 'invalid config should remove stale forwarding file');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Remote logging enabled but invalid: Remote host not configured'), 'expected invalid-config warning');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Removed remote logging config (disabled)'), 'expected stale-config removal log');
    }

    public function testValidLoggingWritesRenderedConfig(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-remote-logging-cfg-');
        $targetDir = $this->pmssMakeTempDir('pmss-remote-logging-rsyslog-');
        $target = $targetDir.'/50-pmss-remote.conf';
        $messages = [];

        file_put_contents($cfgDir.'/logging.conf', implode("\n", [
            'remote_logging_enabled=1',
            'remote_host=logserver.example.com',
            'remote_port=1514',
            'remote_protocol=udp',
            '',
        ]));
        file_put_contents($cfgDir.'/template.rsyslog-remote.conf', implode("\n", [
            '*.* @%%PMSS_RSYSLOG_REMOTE_HOST%%:%%PMSS_RSYSLOG_REMOTE_PORT%%',
            '# protocol=%%PMSS_RSYSLOG_PROTOCOL%%',
            '',
        ]));

        $this->applyRemoteLogging([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
        ], $messages);

        $this->pmssAssertFileContainsAllStrings($target, [
            '*.* @logserver.example.com:1514',
            '# protocol=udp',
        ], 'expected rendered remote logging config');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Applied remote logging: logserver.example.com:1514 (udp)'), 'expected apply log');
    }

    public function testMissingTemplateWarnsWithoutWritingConfig(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-remote-logging-cfg-');
        $targetDir = $this->pmssMakeTempDir('pmss-remote-logging-rsyslog-');
        $messages = [];

        file_put_contents($cfgDir.'/logging.conf', implode("\n", [
            'remote_logging_enabled=1',
            'remote_host=logserver.example.com',
            '',
        ]));

        $this->applyRemoteLogging([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
        ], $messages);

        $this->assertTrue(!file_exists($targetDir.'/50-pmss-remote.conf'), 'target config should not be created without a template');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Remote logging template missing'), 'expected missing-template warning');
    }

    public function testWriteManagedConfigFileRejectsSymlinkTarget(): void
    {
        $targetDir = $this->pmssMakeTempDir('pmss-remote-logging-rsyslog-');
        $realDir = $this->pmssMakeTempDir('pmss-remote-logging-real-');
        $realTarget = $realDir.'/50-pmss-remote.conf';
        $target = $targetDir.'/50-pmss-remote.conf';
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
        $cfgDir = $this->pmssMakeTempDir('pmss-remote-logging-cfg-');
        $targetDir = $this->pmssMakeTempDir('pmss-remote-logging-rsyslog-');
        $realDir = $this->pmssMakeTempDir('pmss-remote-logging-real-');
        $realTarget = $realDir.'/50-pmss-remote.conf';
        $target = $targetDir.'/50-pmss-remote.conf';
        $messages = [];

        file_put_contents($cfgDir.'/logging.conf', implode("\n", [
            'remote_logging_enabled=1',
            'remote_host=logserver.example.com',
            '',
        ]));
        file_put_contents($cfgDir.'/template.rsyslog-remote.conf', "*.* @@%%PMSS_RSYSLOG_REMOTE_HOST%%:%%PMSS_RSYSLOG_REMOTE_PORT%%\n");
        file_put_contents($realTarget, "*.* @@old.example:514\n");
        symlink($realTarget, $target);

        $this->applyRemoteLogging([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
        ], $messages);

        $this->assertEquals("*.* @@old.example:514\n", file_get_contents($realTarget));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe remote logging config target'), 'expected unsafe-target warning');
        $this->assertTrue(!$this->pmssMessagesContain($messages, 'Applied remote logging:'), 'symlink target must prevent apply logging');
    }

    public function testDisabledLoggingPreservesSymlinkedTargetDirectory(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-remote-logging-cfg-');
        $realTargetDir = $this->pmssMakeTempDir('pmss-remote-logging-real-rsyslog-');
        $targetDir = $this->pmssMakeTempPath('pmss-remote-logging-', '-rsyslog-link');
        $realTarget = $realTargetDir.'/50-pmss-remote.conf';
        $messages = [];

        file_put_contents($cfgDir.'/logging.conf', "remote_logging_enabled=0\n");
        file_put_contents($realTarget, "*.* @@old.example:514\n");
        symlink($realTargetDir, $targetDir);

        try {
            $this->applyRemoteLogging([
                'PMSS_CONFIG_DIR' => $cfgDir,
                'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
            ], $messages);

            $this->assertEquals("*.* @@old.example:514\n", file_get_contents($realTarget));
            $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe remote logging config directory'), 'expected unsafe-directory warning');
            $this->assertTrue(!$this->pmssMessagesContain($messages, 'Removed remote logging config (disabled)'), 'unsafe directory must prevent stale-config removal log');
        } finally {
            if (is_link($targetDir)) {
                @unlink($targetDir);
            }
        }
    }

    private function applyRemoteLogging(array $values, array &$messages): void
    {
        $this->pmssWithEnv($values, function () use (&$messages): void {
            \pmssApplyRemoteLogging($this->pmssMakeArrayLogger($messages));
        });
    }
}
