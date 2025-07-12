#!/usr/bin/php
<?php
$usage = "Usage: portManager.php [view|assign|release] USER [SERVICE]\n";
if ($argc < 3) die($usage);
$action = strtolower($argv[1]);
$user   = $argv[2];
$service = isset($argv[3]) ? $argv[3] : 'lighttpd';
$portDir = '/etc/seedbox/runtime/ports';
if (!file_exists($portDir)) mkdir($portDir, 0755, true);
$portFile = "$portDir/{$service}-{$user}";

switch ($action) {
    case 'view':
        if (file_exists($portFile)) {
            echo trim(file_get_contents($portFile)) . "\n";
        } else echo "No port assigned\n";
        break;

    case 'assign':
        if (file_exists($portFile)) {
            echo trim(file_get_contents($portFile)) . "\n";
            break;
        }
        $used = [];
        foreach (glob("$portDir/{$service}-*") as $f) {
            $p = (int) trim(@file_get_contents($f));
            if ($p) $used[$p] = true;
        }
        do {
            $port = rand(2000, 38000);
        } while (isset($used[$port]));
        file_put_contents($portFile, $port);
        chmod($portFile, 0640);
        echo $port . "\n";
        break;

    case 'release':
        if (file_exists($portFile)) {
            unlink($portFile);
            echo "Port released\n";
        } else echo "No port assigned\n";
        break;
    default:
        die($usage);
}
?>
