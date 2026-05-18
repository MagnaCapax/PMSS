#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__.'/../lib/agentDiagnostics.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssAgentDiagnosticsMain');
