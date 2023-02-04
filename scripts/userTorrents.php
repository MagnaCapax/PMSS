#!/usr/bin/php
<?php

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
$changedConfig = array();

foreach($users AS $thisUser) {    // Loop users checking their instances
    $torrents = glob("/home/{$thisUser}/session/*.torrent");
    echo "{$thisUser}: " . number_format(count($torrents)) . "\n";
}