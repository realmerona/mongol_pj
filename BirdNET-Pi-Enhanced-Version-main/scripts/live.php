<?php
// Live view: the realtime streaming spectrogram (the proven Web Audio canvas
// viewer from spectrogram.php, which draws species labels onto the canvas at
// the position their song was detected) framed with a large "Now hearing"
// ticker and a recent-detections list. Full-screen mode doubles as the kiosk
// display. The embedded viewer brings its own player and gain / compression /
// frequency-shift controls.
error_reporting(E_ERROR);
require_once 'scripts/common.php';
$config = get_config();
$site_name = get_sitename();
?>
<div class="live-page" id="livePage">
  <div class="live-header">
    <h2><span class="live-dot" aria-hidden="true"></span> Live at <?php echo h($site_name); ?></h2>
    <div class="live-controls">
      <button type="button" id="liveFullscreenBtn" class="live-btn"><?php echo nav_icon('grid'); ?> <span>Full screen</span></button>
    </div>
  </div>

  <div class="live-nowhearing ui-card" id="liveNowHearing">
    <div class="live-nowhearing-label">NOW HEARING</div>
    <div class="live-nowhearing-species" id="liveSpecies">Listening&hellip;</div>
    <div class="live-nowhearing-meta" id="liveMeta"></div>
  </div>

  <div class="live-stream-embed ui-card">
    <?php include('spectrogram.php'); ?>
  </div>

  <div class="live-recent ui-card">
    <h3>Recent detections</h3>
    <ul class="feed-list" id="liveRecentList"><li class="visit-empty">Loading&hellip;</li></ul>
  </div>
</div>

<script>
(function () {
  'use strict';
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s); };
  var lastSeenKey = null;

  // Full screen (kiosk) toggle
  document.getElementById('liveFullscreenBtn').addEventListener('click', function () {
    var page = document.getElementById('livePage');
    if (!document.fullscreenElement) {
      (page.requestFullscreen || page.webkitRequestFullscreen || function () {}).call(page);
      page.classList.add('kiosk');
    } else {
      document.exitFullscreen();
      page.classList.remove('kiosk');
    }
  });
  document.addEventListener('fullscreenchange', function () {
    if (!document.fullscreenElement) {
      document.getElementById('livePage').classList.remove('kiosk');
    }
  });

  function refreshTicker() {
    fetch('api/v1/detections/recent?limit=6&_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('recent failed'); return r.json(); })
      .then(function (data) {
        if (!data || data.length === 0) {
          document.getElementById('liveSpecies').textContent = 'All quiet right now';
          document.getElementById('liveMeta').textContent = 'No detections yet today.';
          return;
        }
        var d = data[0];
        var pct = Math.round(d.confidence * 100);
        var key = d.species + d.date + d.time;
        document.getElementById('liveSpecies').textContent = d.species;
        document.getElementById('liveMeta').innerHTML =
          esc(d.time) + ' &middot; ' + pct + '% confidence';
        if (key !== lastSeenKey) {
          lastSeenKey = key;
          var card = document.getElementById('liveNowHearing');
          card.classList.remove('flash');
          void card.offsetWidth; // restart the animation
          card.classList.add('flash');
        }
        document.getElementById('liveRecentList').innerHTML = data.map(function (item) {
          var p = Math.round(item.confidence * 100);
          var cls = p >= 90 ? 'high' : (p >= 75 ? 'med' : 'low');
          return '<li class="feed-item">' +
            '<span class="feed-species">' + esc(item.species) + '</span>' +
            '<span class="feed-badge ' + cls + '">' + p + '%</span>' +
            '<span class="feed-time">' + esc(item.time) + '</span>' +
            '</li>';
        }).join('');
      })
      .catch(function () {});
  }

  refreshTicker();
  setInterval(refreshTicker, 10000);
})();
</script>
