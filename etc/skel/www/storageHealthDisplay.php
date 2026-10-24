<?php
/** Storage status and pressure HTML; loaded by storageHealthNotice.php. */

if (!function_exists('pmssStorageHealthStatusRowHtmlBuild')) {
    /** Render the standing status row, omitting it on kernels without PSI. */
    function pmssStorageHealthStatusRowHtmlBuild(array $state): string
    {
        $status = $state['status'] ?? 'unavailable';
        if ($status === 'absent') return '';
        $message = $status === 'heavy' ? PMSS_STORAGE_HEAVY_TITLE.' — '.PMSS_STORAGE_HEAVY_MESSAGE
            : ($status === 'normal' ? 'No shared-storage congestion in the last 5 minutes.' : 'Status unavailable right now.');
        $values = '';
        if ($status !== 'unavailable') {
            $psi = pmssCustomerNonnegativeFloat($state['psi_io_full_avg300'] ?? null);
            if ($psi !== null) {
                $values = 'I/O wait: '.number_format($psi, 1, '.', '').'% of the last 5 minutes (congested above 20%)';
            }
            $latency = pmssCustomerNonnegativeFloat($state['ioping_home_ms'] ?? null);
            $age = pmssCustomerUnsignedIntegerValue($state['ioping_age_min'] ?? null);
            if ($latency !== null && $age !== null) {
                $values .= ' | Storage response: '.number_format($latency, 1, '.', '').' ms (slow above 100 ms), measured '.$age.' min ago';
            }
        }
        $class = $status === 'heavy' ? '' : ' pmss-storage-status-neutral';
        $message = pmssCustomerHtmlAttr($message);
        $valuesHtml = $values === '' ? '' : '<div class="pmss-raid-meta">'.pmssCustomerHtmlAttr($values).'</div>';
        return '<div class="pmss-raid-notice pmss-storage-status'.$class.'" role="status" aria-live="polite">'
            .'<strong>Server storage</strong><p>'.$message.'</p>'.$valuesHtml
            .'<p><a href="https://pulsedmedia.com/clients/index.php/knowledgebase/118/How-Much-Speed-Does-My-Plan-Include-And-How-to-Get-More.html" target="_blank" rel="noopener">How speed works on a shared server</a></p></div>';
    }
}

if (!function_exists('pmssStorageHealthHostPressureNoticeHtmlBuild')) {
    /** Build a shared-server I/O pressure notice from validated alert state. */
    function pmssStorageHealthHostPressureNoticeHtmlBuild($state): string
    {
        if (!is_array($state) || ($state['status'] ?? null) !== 'heavy') return '';

        $details = [];
        if (isset($state['psi_io_full_avg300']) && $state['psi_io_full_avg300'] >= PMSS_STORAGE_PSI_HEAVY) {
            $details[] = 'I/O wait: '.number_format((float) $state['psi_io_full_avg300'], 1, '.', '').'% of the last 5 minutes';
        }
        if (isset($state['ioping_home_ms']) && $state['ioping_home_ms'] > PMSS_STORAGE_IOPING_SLOW) {
            $details[] = 'Storage response: '.number_format((float) $state['ioping_home_ms'], 1, '.', '').' ms';
        }
        if (empty($details)) return '';

        $detailHtml = pmssCustomerHtmlAttr(implode(' | ', $details));
        $title = pmssCustomerHtmlAttr(PMSS_STORAGE_HEAVY_TITLE);
        $message = pmssCustomerHtmlAttr(PMSS_STORAGE_HEAVY_MESSAGE);
        return <<<HTML
<div class="pmss-raid-notice pmss-host-pressure-notice" role="status" aria-live="polite">
    <strong><span class="pmss-raid-icon" aria-hidden="true">&#10071;</span> {$title}</strong>
    <p>{$message}</p>
    <div class="pmss-raid-meta">{$detailHtml}</div>
</div>
HTML;
    }
}

