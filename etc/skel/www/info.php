<?php
/**
 * PMSS: Seedbox information page.
 *
 * Renders the info view used by the main GUI tab frame. Shows server and
 * account statistics via stats.php and provides a lightweight, responsive
 * layout for the seedbox information view.
 *
 * Original concept and implementation: Aleksi Ursin, circa 2010–2015.
 *
 * Copyright (C) 2010-2025 Magna Capax Finland Oy
 */

$pmssHomeRaidActivity = null;
$pmssHomeRaidNoticeHtml = '';
// Customer-side storage-health notice: see storageHealthNotice.php for rationale.
// The full /scripts/lib/storageHealth.php (with SMART/NVMe paths) remains
// operator-side; customer only needs the /proc/mdstat reader subset which
// has been inlined.
$pmssStorageHealthNoticeLib = __DIR__.'/storageHealthNotice.php';
if (file_exists($pmssStorageHealthNoticeLib)) {
    require_once $pmssStorageHealthNoticeLib;
    if (function_exists('pmssStorageHealthHomeRaidActivity')) {
        $pmssHomeRaidActivity = pmssStorageHealthHomeRaidActivity();
        $pmssHomeRaidNoticeHtml = pmssStorageHealthHomeRaidNoticeHtmlBuild($pmssHomeRaidActivity);
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Seedbox info</title>
    <link href="screen.css" rel="stylesheet" media="screen" />
    <!-- Chart.js v4 (UMD build exposes global Chart) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
      .portfoliobox {
        margin-left: 10px;
        margin-right: 10px;
      }
      #stats {
        margin-left: 0;
        margin-right: 0;
      }
      .pmss-raid-notice {
        flex: 0 0 100%;
        margin: 14px 0 18px;
        padding: 12px 14px;
        border: 1px solid #d8a55a;
        background: #fff6e5;
        color: #5f3b00;
      }
      .pmss-raid-notice-error {
        border-color: #cc8f8f;
        background: #fff1f1;
        color: #7a1a1a;
      }
      .pmss-raid-notice p {
        margin: 8px 0 0;
      }
      .pmss-raid-icon {
        color: #b22222;
        margin-right: 6px;
      }
      .pmss-raid-meta {
        margin-top: 8px;
        font-weight: 600;
      }
    </style>
  </head>
<body>
	<div id="wrap">
          <div id="full_page">
          <div class="full_top_nohd"><!-- top design --></div>
          <div class="full_body">
<h1>Seedbox information</h1>
            <div class="portfoliobox">
<?php if ($pmssHomeRaidNoticeHtml !== '') echo $pmssHomeRaidNoticeHtml; ?>

<div id="stats">
 <?php
 $statsPath = __DIR__ . '/stats.php';
 // Fail-soft: keep rendering even if stats.php is missing or unreadable.
 if (is_file($statsPath)) {
     include $statsPath;
 } else {
     echo '<pre>Stats unavailable: missing stats.php.</pre>';
 }
 ?>
</div>

<?php if (is_file(__DIR__ . '/console.php')): ?>
	<!-- GH #326 browser console (ADR 0031): additive; does not replace SSH/ruTorrent/file access. -->
	<div id="pmss-console" style="margin-top:16px">
	  <h2 style="margin:0 0 6px;font-size:1.1em">Shell console</h2>
	  <p style="margin:0 0 10px;color:#555">Open a shell in your browser — no SSH client needed. Runs as your own account.</p>
	  <a href="console.php" target="_blank" rel="noopener" style="display:inline-block;padding:8px 14px;background:#2d6cdf;color:#fff;text-decoration:none;border-radius:4px">Open console</a>
	  <a href="console.php?mode=persist" target="_blank" rel="noopener" style="display:inline-block;padding:8px 14px;margin-left:6px;background:#3a3f4b;color:#fff;text-decoration:none;border-radius:4px">Persistent (tmux)</a>
	</div>
<?php endif; ?>

	<div id="pmss-referral" style="margin-top:16px">
	  <h2 style="margin:0 0 6px;font-size:1.1em">Your referral link</h2>
	  <p style="margin:0 0 10px;color:#555">Every paid account has a personal referral link you can share — anyone who signs up through it is credited to you, and the referral lasts 90 days. Your link and number live in your client area; worked examples and order-form shortcuts are on the <a href="https://wiki.pulsedmedia.com/index.php/Pulsed_Media_Affiliate_Program" target="_blank" rel="noopener">Affiliate Program page in our wiki</a>.</p>
	  <a href="https://pulsedmedia.com/clients/affiliates.php" target="_blank" rel="noopener" style="display:inline-block;padding:8px 14px;background:#2d6cdf;color:#fff;text-decoration:none;border-radius:4px">Get your referral link</a>
	</div>

                
                
            </div>
                
      </div>
      <div class="full_bottom">
      </div>
    </div>
    </div><!--Wrap ends -->

<script>
  // Refresh the page every 10 minutes to update stats without legacy jQuery.
  window.setInterval(function () { window.location.reload(); }, 600000);
</script>
</body>
</html>
