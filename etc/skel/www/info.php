<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Seedbox info</title>
    <link href="https://static.pulsedmedia.com/wc/css/screen.css" rel="stylesheet" media="screen" />
    <!-- Chart.js v4 (UMD build exposes global Chart) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  </head>
<body>
	<div id="wrap">
          <div id="full_page">
          <div class="full_top_nohd"><!-- top design --></div>
          <div class="full_body">
<h1>Seedbox information</h1>
            <div class="portfoliobox">
                

<div id="stats">
 <? include 'stats.php'; ?>
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
