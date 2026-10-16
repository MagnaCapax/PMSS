<?php
/** Shared, inert metadata for the secured customer toggle apps. */
return array(
    'slskd' => array(
        'label' => 'slskd', 'description' => 'Soulseek client', 'listingDescription' => 'Web client for the Soulseek network',
        'listingCategory' => 'Downloads and requests', 'listingIndex' => 2, 'listingAvailable' => true,
        'enable' => '.slskdEnable', 'exe' => '/opt/slskd/slskd',
        'securedMarker' => '.pmss/apps/slskd.secured', 'stateFile' => '.pmss/apps/slskd.state',
        'restartFile' => '.pmss/apps/slskd.restart', 'loginFile' => '.pmss/apps/slskd.login',
        'binaries' => array('/opt/slskd/slskd'), 'processNeedle' => 'slskd', 'processFull' => false,
        'launch' => array('/opt/slskd/slskd', '--config', '{config}'), 'workingDirectory' => '/opt/slskd',
        'config' => '.config/slskd/slskd.yml', 'configRenderer' => 'pmssToggleAppSlskdConfig',
        'extraPortServices' => array('slskd-peer'), 'credentialMode' => 'config',
        'watchdogLabel' => 'slskd', 'watchdogProcess' => 'slskd',
    ),
    'copyparty' => array(
        'label' => 'Copyparty', 'description' => 'Private file sharing', 'listingDescription' => 'File server with resumable uploads and share links',
        'listingCategory' => 'Photos, files and passwords', 'listingIndex' => 2, 'listingAvailable' => true,
        'enable' => '.copypartyEnable', 'exe' => '/usr/bin/python3',
        'securedMarker' => '.pmss/apps/copyparty.secured', 'stateFile' => '.pmss/apps/copyparty.state',
        'restartFile' => '.pmss/apps/copyparty.restart', 'loginFile' => '.pmss/apps/copyparty.login',
        'script' => '/usr/local/bin/copyparty-sfx.py', 'binaries' => array('/usr/local/bin/copyparty-sfx.py'),
        'processNeedle' => '/usr/local/bin/copyparty-sfx.py', 'processFull' => true,
        'launch' => array('python3', '/usr/local/bin/copyparty-sfx.py', '-c', '{config}'), 'workingDirectory' => '',
        'config' => '.config/copyparty/copyparty.conf', 'configRenderer' => 'pmssToggleAppCopypartyConfig',
        'extraPortServices' => array(), 'dataDirectory' => 'data', 'credentialMode' => 'config',
        'watchdogLabel' => 'Copyparty', 'watchdogProcess' => 'python3',
    ),
);
