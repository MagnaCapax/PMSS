<?php
/** Customer Info statistics: statsResourceRender. Loaded by statsHelpers.php. */

/** Render the resource snapshot and storage I/O blocks. */
function pmssStatsRenderResourceBlocks(array $resourceState): void
{
    $resourceData = is_array($resourceState['data'] ?? null) ? $resourceState['data'] : null;
    if ($resourceData === null) {
        $message = $resourceState['error'] !== null ? $resourceState['error'] : 'Resource data not available.';
        echo '<div class="stats-block resource-summary-block"><h6>Resource snapshot</h6><pre>'.pmssCustomerHtmlAttr($message).'</pre></div>';
        return;
    }

    $snapshot = pmssStatsResourceSnapshotBuild($resourceData);
    ?>
<div class="stats-block resource-summary-block">
    <h6>Resource snapshot</h6>
    <div class="resource-summary-strip">
        <div class="resource-summary-item">
            <span class="resource-summary-label">CPU</span>
            <span class="resource-summary-value"><?php echo pmssCustomerHtmlAttr($snapshot['cpuDisplay']['month'] ?? 'n/a'); ?></span>
            <span class="resource-summary-detail">Week/day/hour: <?php echo pmssCustomerHtmlAttr($snapshot['cpuDisplay']['week'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['cpuDisplay']['day'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['cpuDisplay']['hour'] ?? 'n/a'); ?></span>
        </div>
        <div class="resource-summary-item">
            <span class="resource-summary-label">Memory</span>
            <span class="resource-summary-value"><?php echo pmssCustomerHtmlAttr($snapshot['memoryCurrent']); ?></span>
            <span class="resource-summary-detail">Process: <?php echo pmssCustomerHtmlAttr($snapshot['memoryAnon']); ?> / Cache: <?php echo pmssCustomerHtmlAttr($snapshot['memoryFile']); ?></span>
            <span class="resource-summary-detail">RAM-hours: <?php echo pmssCustomerHtmlAttr($snapshot['ramHoursDisplay']['month'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ramHoursDisplay']['week'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ramHoursDisplay']['day'] ?? 'n/a'); ?></span>
            <span class="resource-summary-detail">Average: <?php echo pmssCustomerHtmlAttr($snapshot['memoryDisplay']['month'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['memoryDisplay']['week'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['memoryDisplay']['day'] ?? 'n/a'); ?></span>
        </div>
        <div class="resource-summary-item">
            <span class="resource-summary-label">Processes</span>
            <span class="resource-summary-value"><?php echo pmssCustomerHtmlAttr($snapshot['tasksCurrent']); ?></span>
            <span class="resource-summary-detail">Current account process count</span>
        </div>
    </div>
    <?php if (count($snapshot['cpuDailyHours']) >= 2): ?>
    <div class="resource-summary-chart">
        <?php
        pmssStatsRenderLineChart('cpuChart', $snapshot['ioDailyLabels'], array(
            pmssStatsChartDataset('Daily CPU (hours)', $snapshot['cpuDailyHours'], 'rgba(129, 199, 132, 0.2)', 'rgb(129, 199, 132)'),
        ));
        ?>
    </div>
    <?php else: ?>
        <div class="docker-note">CPU chart requires 2+ days of data.</div>
    <?php endif; ?>
</div>

<div class="stats-block">
    <h6>Storage I/O</h6>
    <pre style="margin-bottom:12px;">
Resource usage at <?php echo date('Y-m-d H:i:s', (int)$resourceState['time']); ?>:
I/O Read (month/week/day/hour): <?php echo pmssCustomerHtmlAttr($snapshot['ioReadDisplay']['month'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioReadDisplay']['week'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioReadDisplay']['day'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioReadDisplay']['hour'] ?? 'n/a')."\n"; ?>
I/O Write (month/week/day/hour): <?php echo pmssCustomerHtmlAttr($snapshot['ioWriteDisplay']['month'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioWriteDisplay']['week'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioWriteDisplay']['day'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioWriteDisplay']['hour'] ?? 'n/a')."\n"; ?>
Past 30 days total I/O operations: <?php echo pmssCustomerHtmlAttr(pmssFormatIoOperationsShort($snapshot['ioOperationsMonth'])); ?>
    </pre>

    <?php if (count($snapshot['ioDailyLabels']) >= 2): ?>
        <?php
        pmssStatsRenderLineChart('ioChart', $snapshot['ioDailyLabels'], array(
            pmssStatsChartDataset('Daily I/O Read (MiB)', $snapshot['ioDailyRead'], 'rgba(75, 192, 192, 0.2)', 'rgb(75, 192, 192)'),
            pmssStatsChartDataset('Daily I/O Write (MiB)', $snapshot['ioDailyWrite'], 'rgba(244, 67, 54, 0.2)', 'rgb(244, 67, 54)'),
        ));
        pmssStatsRenderLineChart('iopsChart', $snapshot['ioDailyLabels'], array(
            pmssStatsChartDataset('Daily I/O Operations', $snapshot['ioDailyOperations'], 'rgba(255, 193, 7, 0.2)', 'rgb(255, 193, 7)'),
        ));
        ?>
    <?php else: ?>
        <div class="docker-note">Chart requires 2+ days of data.</div>
    <?php endif; ?>
</div>
    <?php
}
