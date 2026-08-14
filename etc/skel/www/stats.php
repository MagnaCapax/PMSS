<?php
/**
 * PMSS frontend statistics section on the customer info page.
 *
 * Original concept and implementation: Aleksi Ursin, circa 2010-2015.
 * Copyright (C) 2010-2025 Magna Capax Finland Oy
 * TODO: status hover logs and allowed restarts.
 */
$pmssStatsRequiredHelpers = array('scriptsInc.php', 'statsHelpers.php');
$pmssStatsMissingHelpers = array();
foreach ($pmssStatsRequiredHelpers as $pmssStatsHelper) {
    $pmssStatsHelperPath = __DIR__.'/'.$pmssStatsHelper;
    if (!is_file($pmssStatsHelperPath) || is_link($pmssStatsHelperPath) || !is_readable($pmssStatsHelperPath)) {
        $pmssStatsMissingHelpers[] = $pmssStatsHelper;
    }
}
if ($pmssStatsMissingHelpers !== array()) {
    echo '<pre>Stats unavailable: missing local panel helper '
        .htmlspecialchars(implode(', ', $pmssStatsMissingHelpers), ENT_QUOTES, 'UTF-8')
        .'.</pre>';
    return;
}
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/statsHelpers.php';

$pmssWebCgroupMemoryStatusLib = __DIR__.'/webCgroupMemoryStatus.php';
if (file_exists($pmssWebCgroupMemoryStatusLib)) {
    require_once $pmssWebCgroupMemoryStatusLib;
}

// Customer-side traffic-limit reader; see ADR 0016.
$pmssUserTrafficLimitLib = __DIR__.'/userTrafficLimit.php';
if (file_exists($pmssUserTrafficLimitLib)) {
    require_once $pmssUserTrafficLimitLib;
}

$pmssDockerEnabledPolicy = null;
$pmssMemoryPressure = function_exists('pmssWebCgroupMemoryStatusRead')
    ? pmssWebCgroupMemoryStatusRead()
    : array('available' => false, 'status' => 'UNAVAILABLE');
$resourceState = pmssStatsSerializedStateRead('../.resourceData', 'Invalid resource data format.');
$pmssBaseResources = pmssStatsBaseResourcesBuild();
$uid = $pmssBaseResources['uid'];
$pmssStatsStatusModel = pmssStatsStatusModelBuild($uid, $pmssDockerEnabledPolicy);
$pmssStatsAppToggles = pmssCustomerManagedAppDefinitions();
$pmssBonusDisplayState = pmssCustomerBonusDisplayStateRead();
$pmssBonusDisplayNote = function_exists('pmssCustomerBonusDisplayNoteBuild')
    ? pmssCustomerBonusDisplayNoteBuild($pmssBonusDisplayState)
    : '';
?>

<style>
.stats-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin: 20px 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
@media (max-width: 1200px) {
    .stats-container { grid-template-columns: 1fr; }
}

.stats-block {
    background: #1e1e1e;
    border-radius: 12px;
    padding: 18px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    color: #e0e0e0;
}
.stats-block h6 {
    margin: 0 0 12px;
    color: #4fc3f7;
    font-size: 1.1em;
    font-weight: 600;
    border-bottom: 1px solid #333;
    padding-bottom: 6px;
}
.stats-bonus-block {
    grid-column: 1 / -1;
    background: linear-gradient(135deg, #12334a 0%, #1d3d55 100%);
    border: 1px solid #4fc3f7;
    text-align: center;
}
.stats-bonus-block .stats-bonus-value {
    color: #ffffff;
    font-size: 2.4rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    line-height: 1.1;
}

.info-line {
    display: flex;
    justify-content: space-between;
    margin: 6px 0;
    font-size: 0.95em;
}
.info-line .label { color: #81c784; font-weight: 500; }
.info-line .value { color: #fff; }

.status-grid {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 8px 12px;
    margin-top: 10px;
    font-size: 0.95em;
}
.status-grid .label { color: #81c784; }
.status-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.status {
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 500;
    text-align: center;
    min-width: 70px;
}
.status.active, .status.low { background: #2e7d32; color: #fff; }
.status.inactive, .status.stopped, .status.high { background: #c62828; color: #fff; }
.status.error    { background: #d32f2f; color: #fff; }
.status.medium { background: #ef6c00; color: #fff; }
.status.throttled { background: #8e0000; color: #fff; }
.status.unavailable { background: #546e7a; color: #fff; }
.pmss-app-toggle {
    border: 0;
    border-radius: 6px;
    padding: 3px 8px;
    color: #fff;
    cursor: pointer;
    font-size: 0.85em;
    line-height: 1.3;
}
.pmss-app-toggle-enable { background: #0277bd; }
.pmss-app-toggle-disable { background: #6d4c41; }
.pmss-app-toggle:disabled {
    cursor: wait;
    opacity: 0.7;
}
.pmss-app-toggle-feedback {
    min-width: 48px;
    color: #b0bec5;
    font-size: 0.85em;
}
.memory-pressure-note {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: 8px;
    background: #111;
    color: #e0e0e0;
    font-size: 0.9em;
}

pre {
    margin: 12px 0 0;
    padding: 10px;
    background: #111;
    border-radius: 6px;
    font-size: 0.85em;
    line-height: 1.4;
    max-height: 360px; /* +20% from 300px */
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.stats-block-base-resources .stats-base-resources-pre {
    max-height: none;
    overflow-y: visible;
}

.resource-summary-block {
    padding: 14px 16px;
}
.resource-summary-strip {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px 18px;
}
.resource-summary-item {
    min-width: 0;
    border-left: 3px solid #4fc3f7;
    padding-left: 10px;
}
.resource-summary-label {
    display: block;
    color: #81c784;
    font-size: 0.82em;
    font-weight: 600;
    text-transform: uppercase;
}
.resource-summary-value {
    display: block;
    color: #fff;
    font-size: 1.02em;
    line-height: 1.35;
    margin-top: 3px;
    overflow-wrap: anywhere;
}
.resource-summary-detail {
    display: block;
    color: #b0bec5;
    font-size: 0.86em;
    line-height: 1.35;
    margin-top: 3px;
}
.resource-summary-chart {
    margin-top: 14px;
}
.resource-summary-chart .traffic-chart {
    margin-top: 0;
}
@media (max-width: 900px) {
    .resource-summary-strip { grid-template-columns: 1fr; }
}

.traffic-chart {
    margin-top: 16px;
    background: #111;
    padding: 12px;
    border-radius: 8px;
    height: 250px;
}

.docker-note {
    font-size: 0.85em;
    color: #ff9800;
    margin-top: 4px;
    font-style: italic;
}
.traffic-ratio {
    font-weight: 600;
}
.traffic-ratio.good { color: #81c784; }
.traffic-ratio.warn { color: #ffb74d; }
.traffic-ratio.bad { color: #ef5350; }
.traffic-ratio.na { color: #b0bec5; }
</style>

<script>
function pmssStatsChartOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top' },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            y: { beginAtZero: true }
        }
    };
}

function pmssStatsToggleApp(button) {
    if (!button) return false;
    var endpoint = button.getAttribute('data-endpoint');
    var action = button.getAttribute('data-action');
    var feedback = button.parentNode ? button.parentNode.querySelector('.pmss-app-toggle-feedback') : null;
    if (!endpoint || !action) return false;

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    if (feedback) feedback.textContent = action === 'start' ? 'Starting...' : 'Disabling...';

    var request = new XMLHttpRequest();
    request.onreadystatechange = function() {
        if (request.readyState !== 4) return;
        if (feedback) feedback.textContent = request.status >= 200 && request.status < 400 ? 'Updated' : 'Retry';
        window.setTimeout(function() {
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.location.reload();
                    return;
                }
            } catch (e) {}
            window.location.reload();
        }, 600);
    };
    request.onerror = function() {
        if (feedback) feedback.textContent = 'Retry';
        button.disabled = false;
        button.removeAttribute('aria-busy');
    };
    request.open('POST', endpoint + '?action=' + encodeURIComponent(action), true);
    request.send('');
    return false;
}
</script>

<div class="stats-container">

  <div class="stats-block stats-bonus-block" role="status">
    <h6>Account bonus</h6>
    <div class="stats-bonus-value"><?php echo pmssCustomerHtmlAttr(pmssCustomerBonusDisplayTextBuild($pmssBonusDisplayState)); ?></div>
    <?php if ($pmssBonusDisplayNote !== ''): ?><div class="stats-bonus-note"><?php echo pmssCustomerHtmlAttr($pmssBonusDisplayNote); ?></div><?php endif; ?>
  </div>

  <!-- LEFT: Base resources -->
  <div class="stats-block stats-block-base-resources">
    <h6>Base Resources (current)</h6>
    <pre class="stats-base-resources-pre"><?php echo pmssCustomerHtmlAttr($pmssBaseResources['text']); ?></pre>
  </div>

  <!-- RIGHT: Server info -->
  <div class="stats-block">
    <h6><?php echo pmssCustomerHtmlAttr(isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'server'); ?> info</h6>

    <div class="info-line">
        <span class="label">IP:</span>
        <span class="value">
<?php
$ipUrl = 'https://pulsedmedia.com/remote/myip.php';
if (file_exists(__DIR__.'/welcomeMessage.php')) require_once __DIR__.'/welcomeMessage.php';
$ip = function_exists('pmssWelcomeHttpContextCreate')
    ? @file_get_contents($ipUrl, false, pmssWelcomeHttpContextCreate())
    : false;
echo pmssCustomerHtmlAttr($ip !== false ? trim($ip) : 'unknown');
?>
        </span>
    </div>

    <div class="status-grid">
        <div class="label">Services:</div>
        <div></div>

        <div>WireGuard</div>
        <div><span class="status <?php echo $pmssStatsStatusModel['wgStatus']; ?>"><?php echo ucfirst($pmssStatsStatusModel['wgStatus']); ?></span></div>

        <div>
            <a href="<?php echo file_exists('openvpn-config.tgz') ? 'openvpn-config.tgz' : '#'; ?>"
               style="color:inherit; text-decoration:none;">
                OpenVPN
            </a>
        </div>
        <div><span class="status <?php echo $pmssStatsStatusModel['ovpnStatus']; ?>"><?php echo ucfirst($pmssStatsStatusModel['ovpnStatus']); ?></span></div>
    </div>

    <div class="status-grid" style="margin-top:12px;">
        <div class="label">Apps:</div>
        <div></div>
        <?php foreach ($pmssStatsStatusModel['apps'] as $name => $status): ?>
            <div><?php echo $name; ?></div>
            <div class="status-actions"><span class="status <?php echo $status; ?>"><?php echo ucfirst($status); ?></span><?php echo pmssStatsAppToggleButtonHtmlBuild($name, $pmssStatsAppToggles); ?><?php if ($name === 'Docker' && $pmssStatsStatusModel['dockerInactiveNote'] !== ''): ?><span class="docker-note"><?php echo pmssCustomerHtmlAttr($pmssStatsStatusModel['dockerInactiveNote']); ?></span><?php endif; ?></div>
        <?php endforeach; ?>
    </div>

    <div class="info-line" style="margin-top:14px;">
        <span class="label">Docker policy:</span>
        <span class="value"><span class="status unavailable">Platform managed</span></span>
    </div>
    <div class="memory-pressure-note">Docker policy changes are handled by platform tooling.</div>

    <pre style="margin-top:16px; font-size:0.9em;">
<?php echo pmssStatsServerResourceTextBuild(); ?>
    </pre>
  </div>

</div>

<?php pmssStatsRenderResourceBlocks($resourceState); ?>

<div class="stats-block">
    <h6>Memory pressure</h6>
<?php if (!$pmssMemoryPressure['available']): ?>
    <pre>Memory pressure data is unavailable for this account.</pre>
<?php else: ?>
    <div class="info-line">
        <span class="label">Memory usage:</span>
        <span class="value"><?php echo pmssCustomerHtmlAttr($pmssMemoryPressure['usage_text']); ?></span>
    </div>
    <div class="info-line">
        <span class="label">Memory pressure:</span>
        <span class="value"><span class="status <?php echo strtolower($pmssMemoryPressure['status']); ?>"><?php echo pmssCustomerHtmlAttr($pmssMemoryPressure['status']); ?></span></span>
    </div>
    <?php if ($pmssMemoryPressure['throttle_events'] !== null): ?>
    <div class="info-line">
        <span class="label">Throttle events:</span>
        <span class="value"><?php echo number_format((int) $pmssMemoryPressure['throttle_events']); ?></span>
    </div>
    <?php endif; ?>
    <div class="info-line">
        <span class="label">Pressure avg10:</span>
        <span class="value"><?php
        echo 'some '.($pmssMemoryPressure['pressure_some_avg10'] !== null ? number_format((float) $pmssMemoryPressure['pressure_some_avg10'], 2, '.', '') : 'n/a')
            .' / full '.($pmssMemoryPressure['pressure_full_avg10'] !== null ? number_format((float) $pmssMemoryPressure['pressure_full_avg10'], 2, '.', '') : 'n/a');
        ?></span>
    </div>
    <?php if ($pmssMemoryPressure['memory_high'] !== null): ?>
    <div class="info-line">
        <span class="label">Throttle threshold:</span>
        <span class="value"><?php echo pmssCustomerHtmlAttr(pmssWebCgroupMemoryStatusFormatBytes($pmssMemoryPressure['memory_high'])); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($pmssMemoryPressure['message'] !== ''): ?>
    <div class="memory-pressure-note"><?php echo pmssCustomerHtmlAttr($pmssMemoryPressure['message']); ?></div>
    <?php endif; ?>
<?php endif; ?>
</div>

<div class="stats-block">
    <h6>Disk usage / Quota</h6>
    <pre><?php
    if (file_exists('../.quota')) {
        $content = file_get_contents('../.quota');
        echo $content;
        $mtime = filemtime('../.quota');
        echo "\nUpdated: " . date('Y-m-d H:i:s', $mtime) . "\n";

        $lines = preg_split('/\r?\n/', trim($content));
        if (count($lines) >= 3 && preg_match('/^\s*\/dev\/\S+\s+(\S+)\s+(\S+)\s+(\S+)/', trim($lines[2]), $m)) {
            echo "\nSummary: Used: {$m[1]}, Soft Limit: {$m[2]}, Hard Limit: {$m[3]}\n";
        }
    } else {
        echo "Quota file not found: ../.quota\n";
    }
    ?></pre>
</div>

<?php pmssStatsRenderTrafficUsageBlock(); ?>
