<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/logging.php';

class RemoteLoggingCharacterizationTest extends TestCase
{
    public function testInvalidOverridesFallBackToDefaultPortAndProtocol(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-remote-logging-char-cfg-');
        $targetDir = $this->pmssMakeTempDir('pmss-remote-logging-char-rsyslog-');
        $target = $targetDir.'/50-pmss-remote.conf';
        $messages = [];

        file_put_contents($cfgDir.'/logging.conf', implode("\n", [
            '# Comments and malformed lines must be ignored',
            'remote_logging_enabled=yes',
            'junk line without equals',
            'remote_host=logs.example.net',
            'remote_port=not-a-port',
            'remote_protocol=sctp',
            '',
        ]));
        file_put_contents($cfgDir.'/template.rsyslog-remote.conf', implode("\n", [
            '*.* @@%%PMSS_RSYSLOG_REMOTE_HOST%%:%%PMSS_RSYSLOG_REMOTE_PORT%%',
            '# protocol=%%PMSS_RSYSLOG_PROTOCOL%%',
            '',
        ]));

        $this->pmssWithEnv([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
            'PMSS_DRY_RUN' => '1',
        ], function () use (&$messages): void {
            \pmssApplyRemoteLogging($this->pmssMakeArrayLogger($messages));
        });

        $this->pmssAssertFileContainsAllStrings($target, [
            '*.* @@logs.example.net:514',
            '# protocol=tcp',
        ], 'Expected rendered remote logging config');
        $this->assertTrue(
            $this->pmssMessagesContain($messages, 'Applied remote logging: logs.example.net:514 (tcp)'),
            'Expected apply log to reflect defaulted port and protocol'
        );
    }

    public function testTemplateReplacementStaysGlobalAcrossRepeatedPlaceholders(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-remote-logging-char-cfg-');
        $targetDir = $this->pmssMakeTempDir('pmss-remote-logging-char-rsyslog-');
        $target = $targetDir.'/50-pmss-remote.conf';

        file_put_contents($cfgDir.'/logging.conf', implode("\n", [
            'remote_logging_enabled=1',
            'remote_host=mirror.example.net',
            'remote_port=10514',
            'remote_protocol=udp',
            '',
        ]));
        file_put_contents($cfgDir.'/template.rsyslog-remote.conf', implode("\n", [
            '*.* @%%PMSS_RSYSLOG_REMOTE_HOST%%:%%PMSS_RSYSLOG_REMOTE_PORT%%',
            '# copy=%%PMSS_RSYSLOG_REMOTE_HOST%%:%%PMSS_RSYSLOG_REMOTE_PORT%%',
            '# protocol=%%PMSS_RSYSLOG_PROTOCOL%%/%%PMSS_RSYSLOG_PROTOCOL%%',
            '',
        ]));

        $this->pmssWithEnv([
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_RSYSLOG_CONF_DIR' => $targetDir,
            'PMSS_DRY_RUN' => '1',
        ], function (): void {
            \pmssApplyRemoteLogging();
        });

        $this->pmssAssertFileContainsAllStrings($target, [
            '*.* @mirror.example.net:10514',
            '# copy=mirror.example.net:10514',
            '# protocol=udp/udp',
        ], 'Expected rendered remote logging config');
    }
}
