#!/usr/bin/php
<?php

$usage = "Usage: ./fileCopy.php FILE_TO_COPY   TARGET_PATH   USERNAME\n";
if (!isset($argv[1]) or
    !isset($argv[2]) or
    !isset($argv[3]) ) die($usage);
//print_r($argv); die();
$command = "cp {$argv[1]} /home/{$argv[3]}/{$argv[2]}/; chown {$argv[3]}.{$argv[3]} /home/{$argv[3]}/{$argv[2]}/*; chmod 770 /home/{$argv[3]}/{$argv[2]}/*;";
passthru($command);
