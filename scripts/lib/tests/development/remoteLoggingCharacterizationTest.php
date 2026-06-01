<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/logging.php';

class RemoteLoggingCharacterizationTest extends TestCase
{
    public function testInvalidOverridesFallBackToDefaultPortAndProtocol(): void
    {
        $fixture = $this->pmssRemoteLoggingFixture('pmss-remote-logging-char-');
        $messages = [];

        file_put_contents($fixture['cfgDir'].'/logging.conf', implode("\n", [
            '# Comments and malformed lines must be ignored',
            'remote_logging_enabled=yes',
            'junk line without equals',
            'remote_host=logs.example.net',
            'remote_port=not-a-port',
            'remote_protocol=sctp',
            '',
        ]));
        file_put_contents($fixture['cfgDir'].'/template.rsyslog-remote.conf', implode("\n", [
            '*.* @@%%PMSS_RSYSLOG_REMOTE_HOST%%:%%PMSS_RSYSLOG_REMOTE_PORT%%',
            '# protocol=%%PMSS_RSYSLOG_PROTOCOL%%',
            '',
        ]));

        $this->pmssApplyRemoteLoggingFixture($fixture, $messages, ['PMSS_DRY_RUN' => '1']);

        $this->pmssAssertFileContainsAllStrings($fixture['target'], [
            '*.* @@logs.example.net:514',
            '# protocol=tcp',
        ], 'Expected rendered remote logging config');
        $this->pmssAssertMessagesContain($messages, 'Applied remote logging: logs.example.net:514 (tcp)', 'Expected apply log to reflect defaulted port and protocol');
    }

    public function testTemplateReplacementStaysGlobalAcrossRepeatedPlaceholders(): void
    {
        $fixture = $this->pmssRemoteLoggingFixture('pmss-remote-logging-char-');

        file_put_contents($fixture['cfgDir'].'/logging.conf', implode("\n", [
            'remote_logging_enabled=1',
            'remote_host=mirror.example.net',
            'remote_port=10514',
            'remote_protocol=udp',
            '',
        ]));
        file_put_contents($fixture['cfgDir'].'/template.rsyslog-remote.conf', implode("\n", [
            '*.* @%%PMSS_RSYSLOG_REMOTE_HOST%%:%%PMSS_RSYSLOG_REMOTE_PORT%%',
            '# copy=%%PMSS_RSYSLOG_REMOTE_HOST%%:%%PMSS_RSYSLOG_REMOTE_PORT%%',
            '# protocol=%%PMSS_RSYSLOG_PROTOCOL%%/%%PMSS_RSYSLOG_PROTOCOL%%',
            '',
        ]));

        $messages = [];
        $this->pmssApplyRemoteLoggingFixture($fixture, $messages, ['PMSS_DRY_RUN' => '1']);

        $this->pmssAssertFileContainsAllStrings($fixture['target'], [
            '*.* @mirror.example.net:10514',
            '# copy=mirror.example.net:10514',
            '# protocol=udp/udp',
        ], 'Expected rendered remote logging config');
    }
}
