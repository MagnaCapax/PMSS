<?php
/** Customer Info statistics: statsCharts. Loaded by statsHelpers.php. */

/** Build one Chart.js dataset row with the defaults expected by stats.php. */
function pmssStatsChartDataset(string $label, array $data, string $backgroundColor, string $borderColor): array
{
    return array('label' => $label, 'data' => $data, 'backgroundColor' => $backgroundColor, 'borderColor' => $borderColor);
}

/**
 * Render a Chart.js line chart with the shared PMSS stats defaults.
 */
function pmssStatsRenderLineChart($canvasId, array $labels, array $datasets): void
{
    $normalizedDatasets = array();
    foreach ($datasets as $dataset) {
        if (!is_array($dataset) || !isset($dataset['label'], $dataset['data'], $dataset['backgroundColor'], $dataset['borderColor'])) {
            continue;
        }

        $normalizedDatasets[] = array(
            'label' => (string) $dataset['label'],
            'data' => array_values((array) $dataset['data']),
            'fill' => array_key_exists('fill', $dataset) ? (bool) $dataset['fill'] : true,
            'backgroundColor' => (string) $dataset['backgroundColor'],
            'borderColor' => (string) $dataset['borderColor'],
            'tension' => array_key_exists('tension', $dataset) ? (float) $dataset['tension'] : 0.4,
            'pointRadius' => array_key_exists('pointRadius', $dataset) ? (int) $dataset['pointRadius'] : 3,
        );
    }

    if ($normalizedDatasets === array()) {
        return;
    }

    $encodedLabels = json_encode(array_values($labels));
    $encodedDatasets = json_encode($normalizedDatasets, JSON_UNESCAPED_SLASHES);
    $encodedCanvasId = json_encode((string) $canvasId);
    if (!is_string($encodedLabels) || !is_string($encodedDatasets) || !is_string($encodedCanvasId)) {
        return;
    }

    $safeCanvasId = pmssCustomerHtmlAttr($canvasId);
    echo '<div class="traffic-chart"><canvas id="'.$safeCanvasId.'" width="600" height="250"></canvas></div>'."\n";
    echo "<script>\n";
    echo "document.addEventListener('DOMContentLoaded', () => {\n";
    echo "    if (typeof Chart === 'undefined') return;\n";
    echo "    const element = document.getElementById({$encodedCanvasId});\n";
    echo "    if (!element) return;\n";
    echo "    new Chart(element.getContext('2d'), {\n";
    echo "        type: 'line',\n";
    echo "        data: { labels: {$encodedLabels}, datasets: {$encodedDatasets} },\n";
    echo "        options: pmssStatsChartOptions()\n";
    echo "    });\n";
    echo "});\n";
    echo "</script>\n";
}
