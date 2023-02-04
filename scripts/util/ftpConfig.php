#!/usr/bin/php
<?php
echo date('Y-m-d H:i:s') . ': Making ProFTPd configuration' . "\n";

$configTemplate = file_get_contents('http://pulsedmedia.com/remote/config/proftpdTemplate.txt');
$hostname = file_get_contents('/etc/hostname');

if (empty($configTemplate) or empty($hostname)) die('No data, hostname or config template is empty!');

$configTemplate = str_replace('%SERVERNAME%', trim($hostname), $configTemplate);

file_put_contents('/etc/proftpd/proftpd.conf', $configTemplate);
`/etc/init.d/proftpd restart`;

