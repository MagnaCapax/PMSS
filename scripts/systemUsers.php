#!/usr/bin/php
<?php
require_once 'lib/users.php';
$users = users::systemUsers();

foreach($users AS $thisUser)
 echo $thisUser . "\n";
