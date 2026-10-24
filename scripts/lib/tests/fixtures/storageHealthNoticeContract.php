<?php
/** Isolate the storage notice include order from the development runner. */
$root = dirname(__DIR__, 4);
if ($argv[1] === 'partial') {
    require $argv[2];
    echo json_encode([function_exists('pmssStorageHealthHomeRaidActivity'),
        function_exists('pmssStorageHealthHostPressureStateRead'), function_exists('pmssStorageHealthNoticeHtmlRead')]);
    exit;
}
if ($argv[1] === 'bridge') {
    try {
        require $argv[2];
    } catch (RuntimeException $exception) {
        echo $exception->getMessage();
        exit;
    }
    exit(1);
}
foreach (explode(',', $argv[1]) as $context) {
    require_once $root.($context === 'operator' ? '/scripts/lib/storageHealth.php' : '/etc/skel/www/storageHealthNotice.php');
}

$notices = [];
foreach ([
    null, [], ['array' => 'md0'], ['array' => 'md0', 'flags' => ['degraded']],
    ['array' => 'md0', 'operation' => 'check'],
    ['array' => 'md<&"\'', 'operation' => 'resync<&"\'', 'flags' => ['degraded'],
        'progress' => '12.3%', 'eta' => '<15min', 'speed' => '1&2K/sec'],
    ['array' => 'md0', 'operation' => 'reshape', 'progress' => '0', 'eta' => '', 'speed' => null],
    ['array' => "\xff", 'operation' => 'check'],
] as $activity) {
    $notices[] = pmssStorageHealthHomeRaidNoticeHtmlBuild($activity);
}
$summaries = [];
foreach (['', 'reshape', 'check = 12.3% finish=15.4min speed=123456K/sec',
    'recovery resync = 0.0% finish=none speed=0K/sec', 'unchecked = 20%'] as $line) {
    $summaries[] = pmssStorageHealthRaidActivitySummaryParse($line);
}
echo json_encode(['notices' => $notices, 'summaries' => $summaries], JSON_UNESCAPED_SLASHES);
