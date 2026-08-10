<?php
// Year in Birds (Phase 5): a shareable annual recap computed entirely from
// local data - totals, champions, rarest find, busiest day, monthly rhythm.
// "Save as image" draws the recap onto a canvas and downloads a PNG.
error_reporting(E_ERROR);
require_once 'scripts/common.php';

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

$year = isset($_GET['y']) && preg_match('/^\d{4}$/', $_GET['y']) ? $_GET['y'] : date('Y');
$y_start = $year . '-01-01';
$y_end = ($year + 1) . '-01-01';
$w = "Date >= '$y_start' AND Date < '$y_end'";

$cache_key = birdnet_cache_key('year_in_birds', $year, date('Y-m-d'), detections_watermark(), filemtime(__FILE__));
$cached = birdnet_cache_get($cache_key);
if ($cached !== false) {
  echo $cached;
  return;
}
ob_start();

$total = (int) db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE $w", 0, 'year total');
$species_count = (int) db_query_single_safe($db, "SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE $w", 0, 'year species');
$new_species = (int) db_query_single_safe($db, "SELECT COUNT(*) FROM (SELECT Sci_Name, MIN(Date) AS first FROM detections GROUP BY Sci_Name HAVING first >= '$y_start' AND first < '$y_end')", 0, 'year new species');
$top = db_query_one_safe($db, "SELECT Com_Name, Sci_Name, COUNT(*) AS cnt FROM detections WHERE $w GROUP BY Sci_Name ORDER BY cnt DESC LIMIT 1", 'year top species');
$rarest = db_query_one_safe($db, "SELECT Com_Name, Sci_Name, COUNT(*) AS cnt FROM detections WHERE $w GROUP BY Sci_Name ORDER BY cnt ASC, Com_Name ASC LIMIT 1", 'year rarest');
$busiest = db_query_one_safe($db, "SELECT Date, COUNT(*) AS cnt FROM detections WHERE $w GROUP BY Date ORDER BY cnt DESC LIMIT 1", 'year busiest day');
$dawn = db_query_one_safe($db, "SELECT Com_Name, AVG(first_minutes) AS avg_minutes, COUNT(*) AS days FROM (SELECT Com_Name, Date, MIN(CAST(substr(Time,1,2) AS REAL)*60 + CAST(substr(Time,4,2) AS REAL)) AS first_minutes FROM detections WHERE $w AND CAST(substr(Time,1,2) AS INTEGER) BETWEEN 4 AND 10 GROUP BY Sci_Name, Date) GROUP BY Com_Name HAVING days >= 5 ORDER BY avg_minutes ASC LIMIT 1", 'year dawn champion');
$night = db_query_one_safe($db, "SELECT Com_Name, COUNT(*) AS cnt FROM detections WHERE $w AND (CAST(substr(Time,1,2) AS INTEGER) >= 22 OR CAST(substr(Time,1,2) AS INTEGER) < 4) GROUP BY Sci_Name ORDER BY cnt DESC LIMIT 1", 'year night owl');

$monthly = array_fill(1, 12, 0);
$m_res = db_query_safe($db, "SELECT CAST(strftime('%m', Date) AS INTEGER) AS m, COUNT(*) AS cnt FROM detections WHERE $w GROUP BY m", 'year monthly');
while ($row = db_fetch_assoc_safe($m_res)) {
  $monthly[(int)$row['m']] = (int)$row['cnt'];
}

$dawn_time = '';
if ($dawn) {
  $mins = (int) $dawn['avg_minutes'];
  $dawn_time = format_minutes_label($mins);
}

$site_name = get_sitename();
$stats = [
  ['value' => format_number($total), 'label' => 'detections'],
  ['value' => format_number($species_count), 'label' => 'species heard'],
  ['value' => format_number($new_species), 'label' => 'new species'],
];
$champions = [];
if ($top) {
  $champions[] = ['title' => 'Bird of the year', 'name' => $top['Com_Name'], 'detail' => format_number((int)$top['cnt']) . ' detections'];
}
if ($rarest && (!$top || $rarest['Sci_Name'] !== $top['Sci_Name'])) {
  $champions[] = ['title' => 'Rarest find', 'name' => $rarest['Com_Name'], 'detail' => 'only ' . format_number((int)$rarest['cnt']) . ' detection' . ((int)$rarest['cnt'] === 1 ? '' : 's')];
}
if ($busiest) {
  $champions[] = ['title' => 'Busiest day', 'name' => date('F j', strtotime($busiest['Date'])), 'detail' => format_number((int)$busiest['cnt']) . ' detections'];
}
if ($dawn) {
  $champions[] = ['title' => 'Dawn chorus champion', 'name' => $dawn['Com_Name'], 'detail' => 'first song around ' . $dawn_time];
}
if ($night) {
  $champions[] = ['title' => 'Night owl', 'name' => $night['Com_Name'], 'detail' => format_number((int)$night['cnt']) . ' detections after dark'];
}
?>
<div class="year-page">
  <div class="timeline-header">
    <h2><?php echo nav_icon('award'); ?> Your <?php echo h($year); ?> in Birds</h2>
    <div class="timeline-datenav">
      <a class="ui-button-link" href="?view=Year&amp;y=<?php echo $year - 1; ?>">&larr; <?php echo $year - 1; ?></a>
      <?php if ($year < date('Y')) { ?><a class="ui-button-link" href="?view=Year&amp;y=<?php echo $year + 1; ?>"><?php echo $year + 1; ?> &rarr;</a><?php } ?>
      <button type="button" class="ui-button-link" id="yearDownloadBtn" <?php echo $total === 0 ? 'disabled' : ''; ?>>Save as image</button>
    </div>
  </div>

  <?php if ($total === 0) { ?>
    <div class="ui-message ui-message-info" role="status"><strong>No detections in <?php echo h($year); ?></strong><span>Pick another year above.</span></div>
  <?php } else { ?>
  <div class="now-kpis year-kpis">
    <?php foreach ($stats as $s) { ?>
      <div class="ui-card kpi-mini"><div class="kpi-mini-value"><?php echo $s['value']; ?></div><div class="kpi-mini-label"><?php echo h($s['label']); ?></div></div>
    <?php } ?>
  </div>

  <div class="year-champions">
    <?php foreach ($champions as $c) { ?>
      <div class="ui-card year-champ">
        <div class="year-champ-title"><?php echo h($c['title']); ?></div>
        <div class="year-champ-name"><?php echo h($c['name']); ?></div>
        <div class="year-champ-detail"><?php echo h($c['detail']); ?></div>
      </div>
    <?php } ?>
  </div>

  <div class="ui-card">
    <h3><?php echo nav_icon('chart'); ?> Month by month</h3>
    <div class="year-months">
      <?php
      $max_month = max(1, max($monthly));
      $month_names = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      for ($m = 1; $m <= 12; $m++) { ?>
        <div class="year-month">
          <div class="year-month-bar-wrap"><div class="year-month-bar" style="height: <?php echo max(2, round(($monthly[$m] / $max_month) * 100)); ?>%" title="<?php echo $month_names[$m] . ': ' . format_number($monthly[$m]); ?>"></div></div>
          <div class="year-month-label"><?php echo $month_names[$m]; ?></div>
        </div>
      <?php } ?>
    </div>
  </div>
  <?php } ?>
</div>

<script>
(function () {
  'use strict';
  var btn = document.getElementById('yearDownloadBtn');
  if (!btn || btn.disabled) return;
  var data = {
    year: <?php echo js_arg($year); ?>,
    site: <?php echo js_arg($site_name); ?>,
    stats: <?php echo json_encode($stats); ?>,
    champions: <?php echo json_encode($champions); ?>,
    monthly: <?php echo json_encode(array_values($monthly)); ?>
  };

  btn.addEventListener('click', function () {
    var W = 900, H = 700;
    var cvs = document.createElement('canvas');
    var dpr = 2;
    cvs.width = W * dpr; cvs.height = H * dpr;
    var ctx = cvs.getContext('2d');
    ctx.scale(dpr, dpr);

    var g = ctx.createLinearGradient(0, 0, W, H);
    g.addColorStop(0, '#312e81');
    g.addColorStop(1, '#4f46e5');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);

    ctx.fillStyle = 'rgba(255,255,255,0.85)';
    ctx.font = '600 20px Roboto Flex, sans-serif';
    ctx.fillText(data.site, 48, 64);
    ctx.fillStyle = '#fff';
    ctx.font = '800 52px Roboto Flex, sans-serif';
    ctx.fillText(data.year + ' in Birds', 48, 122);

    // headline stats
    var x = 48;
    data.stats.forEach(function (s) {
      ctx.fillStyle = '#fff';
      ctx.font = '800 40px Roboto Flex, sans-serif';
      ctx.fillText(s.value, x, 205);
      ctx.fillStyle = 'rgba(255,255,255,0.75)';
      ctx.font = '500 16px Roboto Flex, sans-serif';
      ctx.fillText(s.label, x, 230);
      x += Math.max(190, ctx.measureText(s.value).width + 90);
    });

    // champions
    var y = 290;
    data.champions.forEach(function (c) {
      ctx.fillStyle = 'rgba(255,255,255,0.65)';
      ctx.font = '700 13px Roboto Flex, sans-serif';
      ctx.fillText(c.title.toUpperCase(), 48, y);
      ctx.fillStyle = '#fff';
      ctx.font = '700 24px Roboto Flex, sans-serif';
      ctx.fillText(c.name, 48, y + 28);
      ctx.fillStyle = 'rgba(255,255,255,0.75)';
      ctx.font = '400 15px Roboto Flex, sans-serif';
      ctx.fillText(c.detail, 320, y + 28);
      y += 62;
    });

    // monthly bars
    var maxM = Math.max.apply(null, data.monthly.concat([1]));
    var names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var bw = 52, bx = 48, baseY = H - 70, maxH = 90;
    data.monthly.forEach(function (cnt, i) {
      var h = Math.max(3, Math.round((cnt / maxM) * maxH));
      ctx.fillStyle = 'rgba(255,255,255,0.85)';
      ctx.fillRect(bx + i * bw + 8, baseY - h, bw - 22, h);
      ctx.fillStyle = 'rgba(255,255,255,0.6)';
      ctx.font = '500 11px Roboto Flex, sans-serif';
      ctx.fillText(names[i], bx + i * bw + 8, baseY + 18);
    });

    ctx.fillStyle = 'rgba(255,255,255,0.5)';
    ctx.font = '400 12px Roboto Flex, sans-serif';
    ctx.fillText('Recorded by BirdNET-Pi', 48, H - 22);

    var a = document.createElement('a');
    a.download = 'year-in-birds-' + data.year + '.png';
    a.href = cvs.toDataURL('image/png');
    a.click();
  });
})();
</script>

<?php
birdnet_cache_put($cache_key, ob_get_contents());
ob_end_flush();
?>
