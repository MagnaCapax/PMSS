<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/update/services/logging.php';

class RemoteLoggingTest extends TestCase
{
    use FilesystemCleanupTrait;

    public function setUp(): void
    {
        unset($GLOBALS['PMSS_PROFILE'], $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']);
    }

    public function testMissingLoggingConfigSkipsSilently(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $targetDir = $this->tempDir('rsyslog');
        $messages = [];

        try {
            $this->runRemoteLogging([
                'PMSS_CONFIG_DIR' => $cfgDir,
                'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
            ], $messages);

            $this->assertTrue(!file_exists($targetDir.'/50-pmss-remote.conf'), 'unexpected remote logging config created');
            $this->assertEquals([], $messages);
        } finally {
            $this->cleanup($cfgDir);
            $this->cleanup($targetDir);
        }
    }

    public function testDisabledLoggingRemovesStaleConfig(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $targetDir = $this->tempDir('rsyslog');
        $target = $targetDir.'/50-pmss-remote.conf';
        $messages = [];

        file_put_contents($cfgDir.'/logging.conf', "remote_logging_enabled=0\n");
        file_put_contents($target, "*.* @@old.example:514\n");

        try {
            $this->runRemoteLogging([
                'PMSS_CONFIG_DIR' => $cfgDir,
                'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
            ], $messages);

            $this->assertTrue(!file_exists($target), 'stale remote logging config should be removed');
            $this->assertTrue($this->pmssMessagesContain($messages, 'Removed remote logging config (disabled)'), 'expected removal log');
            $this->assertEquals([], $GLOBALS['PMSS_PROFILE'] ?? []);
        } finally {
            $this->cleanup($cfgDir);
            $this->cleanup($targetDir);
        }
    }

    public function testInvalidEnabledLoggingWarnsAndRemovesStaleConfig(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $targetDir = $this->tempDir('rsyslog');
        $target = $targetDir.'/50-pmss-remote.conf';
        $messages = [];

        file_put_contents($cfgDir.'/logging.conf', "remote_logging_enabled=1\n");
        file_put_contents($target, "*.* @@old.example:514\n");

        try {
            $this->runRemoteLogging([
                'PMSS_CONFIG_DIR' => $cfgDir,
                'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
            ], $messages);

            $this->assertTrue(!file_exists($target), 'invalid config should remove stale forwarding file');
            $this->assertTrue($this->pmssMessagesContain($messages, 'Remote logging enabled but invalid: Remote host not configured'), 'expected invalid-config warning');
            $this->assertTrue($this->pmssMessagesContain($messages, 'Removed remote logging config (disabled)'), 'expected stale-config removal log');
        } finally {
            $this->cleanup($cfgDir);
            $this->cleanup($targetDir);
        }
    }

    public function testValidLoggingWritesRenderedConfig(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $targetDir = $this->tempDir('rsyslog');
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

        try {
            $this->runRemoteLogging([
                'PMSS_CONFIG_DIR' => $cfgDir,
                'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
            ], $messages);

            $this->assertTrue(file_exists($target), 'expected rendered remote logging config');
            $rendered = (string) file_get_contents($target);
            $this->assertStringContainsString('*.* @logserver.example.com:1514', $rendered);
            $this->assertStringContainsString('# protocol=udp', $rendered);
            $this->assertTrue($this->pmssMessagesContain($messages, 'Applied remote logging: logserver.example.com:1514 (udp)'), 'expected apply log');
        } finally {
            $this->cleanup($cfgDir);
            $this->cleanup($targetDir);
        }
    }

    public function testMissingTemplateWarnsWithoutWritingConfig(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $targetDir = $this->tempDir('rsyslog');
        $messages = [];

        file_put_contents($cfgDir.'/logging.conf', implode("\n", [
            'remote_logging_enabled=1',
            'remote_host=logserver.example.com',
            '',
        ]));

        try {
            $this->runRemoteLogging([
                'PMSS_CONFIG_DIR' => $cfgDir,
                'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
            ], $messages);

            $this->assertTrue(!file_exists($targetDir.'/50-pmss-remote.conf'), 'target config should not be created without a template');
            $this->assertTrue($this->pmssMessagesContain($messages, 'Remote logging template missing'), 'expected missing-template warning');
        } finally {
            $this->cleanup($cfgDir);
            $this->cleanup($targetDir);
        }
    }

    public function testWriteManagedConfigFileRejectsSymlinkTarget(): void
    {
        $targetDir = $this->tempDir('rsyslog');
        $realTarget = $this->tempDir('real').'/50-pmss-remote.conf';
        $target = $targetDir.'/50-pmss-remote.conf';
        $messages = [];

        file_put_contents($realTarget, "*.* @@old.example:514\n");
        symlink($realTarget, $target);

        try {
            $result = \pmssWriteManagedConfigFile($target, "*.* @new.example:1514\n", 'remote logging config', $this->pmssMakeArrayLogger($messages));

            $this->assertTrue(!$result, 'symlink target must be rejected');
            $this->assertEquals("*.* @@old.example:514\n", file_get_contents($realTarget));
            $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe remote logging config target'), 'expected unsafe-target warning');
        } finally {
            $this->cleanup($targetDir);
            $this->cleanup(dirname($realTarget));
        }
    }

    public function testRemoteLoggingRejectsSymlinkTargetPath(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $targetDir = $this->tempDir('rsyslog');
        $realTarget = $this->tempDir('real').'/50-pmss-remote.conf';
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

        try {
            $this->runRemoteLogging([
                'PMSS_CONFIG_DIR' => $cfgDir,
                'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
            ], $messages);

            $this->assertEquals("*.* @@old.example:514\n", file_get_contents($realTarget));
            $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe remote logging config target'), 'expected unsafe-target warning');
            $this->assertTrue(!$this->pmssMessagesContain($messages, 'Applied remote logging:'), 'symlink target must prevent apply logging');
        } finally {
            $this->cleanup($cfgDir);
            $this->cleanup($targetDir);
            $this->cleanup(dirname($realTarget));
        }
    }

    private function tempDir(string $suffix): string
    {
        $dir = sys_get_temp_dir().'/pmss-remote-logging-'.bin2hex(random_bytes(4)).'-'.$suffix;
        @mkdir($dir, 0700, true);
        return $dir;
    }

    private function runRemoteLogging(array $values, array &$messages): void
    {
        $this->withEnv($values, function () use (&$messages): void {
            \pmssApplyRemoteLogging($this->pmssMakeArrayLogger($messages));
        });
    }

    private function withEnv(array $values, callable $callback): void
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($value === null ? $key : $key.'='.$value);
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
        }
    }

}
