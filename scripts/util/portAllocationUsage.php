#!/usr/bin/php
<?php
/**
 * Example script showing how to request a configuration using the
 * port allocation utilities. Ports are chosen automatically and
 * stored under /var/run/pmss/ports so subsequent runs will not
 * reuse them.
 */
require_once __DIR__ . '/../lib/rtorrentConfig.php';

$rt = new rtorrentConfig([
    'ramBlock' => 250,
    'peers' => ['minimum' => 6, 'maximum' => 32],
    'uploadSlots' => 7,
], 'scgi:##scgiPort');

$config = $rt->createConfig([
    'ram' => 256,
    'dht' => 'no',
    'pex' => 'no'
]);

print "Allocated ports:\n";
print '  SCGI: ' . $config['config']['scgiPort'] . "\n";
print '  DHT: ' . $config['config']['dhtPort'] . "\n";
print '  Listen: ' . $config['config']['listenPort'] . "\n";



