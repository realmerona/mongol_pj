<?php
// Hidden development page: ?view=Styleguide
// Renders every shared UI component in the current theme so changes can be
// reviewed in light/dark without clicking through the whole app.
?>
<div style="max-width: 1000px; margin: 0 auto; text-align: left;">
  <h1 style="color: var(--text-heading);">Component Styleguide</h1>
  <p style="color: var(--text-secondary);">Internal reference page. Toggle the theme in the sidebar to review both modes.</p>

  <h3 style="text-align:left;">Icons (static/icons.svg)</h3>
  <div style="display:flex; flex-wrap:wrap; gap:16px; background: var(--bg-card); padding: 16px; border-radius: var(--radius); box-shadow: var(--shadow-sm);">
    <?php
    $styleguide_icons = ['home', 'chart', 'bird', 'zap', 'music', 'activity', 'file-text', 'sliders', 'grid', 'clock', 'send', 'cloud', 'search', 'trending-up', 'menu', 'sun', 'moon', 'mic'];
    foreach ($styleguide_icons as $sg_icon) {
      echo '<span style="display:flex; flex-direction:column; align-items:center; gap:4px; width:72px; color: var(--text-primary);">'
         . '<svg style="width:22px;height:22px;" aria-hidden="true"><use href="static/icons.svg#' . $sg_icon . '"></use></svg>'
         . '<code style="font-size:0.7em; color: var(--text-muted);">' . h($sg_icon) . '</code></span>';
    }
    ?>
  </div>

  <h3 style="text-align:left;">Confidence badges</h3>
  <div style="background: var(--bg-card); padding: 16px; border-radius: var(--radius); box-shadow: var(--shadow-sm);">
    <span class="feed-badge high">95%</span>
    <span class="feed-badge med">80%</span>
    <span class="feed-badge low">62%</span>
  </div>

  <h3 style="text-align:left;">Status pills</h3>
  <div style="background: var(--bg-card); padding: 16px; border-radius: var(--radius); box-shadow: var(--shadow-sm);">
    <span class="ui-status-pill ui-status-active">Active</span>
    <span class="ui-status-pill ui-status-inactive">Inactive</span>
    <span class="ui-status-pill ui-status-unknown">Unknown</span>
  </div>

  <h3 style="text-align:left;">Messages</h3>
  <div class="ui-message ui-message-info" role="status"><strong>Info</strong><span>Something neutral happened.</span></div>
  <div class="ui-message ui-message-success" role="status"><strong>Success</strong><span>The action completed.</span></div>
  <div class="ui-message ui-message-warning" role="status"><strong>Warning</strong><span>Something needs attention.</span></div>
  <div class="ui-message ui-message-error" role="alert"><strong>Error</strong><span>The action failed.</span></div>

  <h3 style="text-align:left;">Skeleton loading</h3>
  <div style="background: var(--bg-card); padding: 16px; border-radius: var(--radius); box-shadow: var(--shadow-sm);">
    <div class="ui-skeleton-block" aria-hidden="true">
      <span class="ui-skeleton-line" style="width:90%"></span>
      <span class="ui-skeleton-line" style="width:76%"></span>
      <span class="ui-skeleton-line" style="width:62%"></span>
    </div>
  </div>

  <h3 style="text-align:left;">Buttons &amp; links</h3>
  <div style="background: var(--bg-card); padding: 16px; border-radius: var(--radius); box-shadow: var(--shadow-sm); display:flex; gap:12px; align-items:center;">
    <button type="button">Standard button</button>
    <a href="?view=Styleguide">Standard link</a>
  </div>

  <h3 style="text-align:left;">Table</h3>
  <table style="width:100%;">
    <tr><th>Species</th><th>Detections</th><th>Confidence</th></tr>
    <tr><td>Carolina Wren</td><td>128</td><td><span class="feed-badge high">94%</span></td></tr>
    <tr><td>Northern Cardinal</td><td>96</td><td><span class="feed-badge med">81%</span></td></tr>
  </table>

  <h3 style="text-align:left;">Design tokens</h3>
  <div style="background: var(--bg-card); padding: 16px; border-radius: var(--radius); box-shadow: var(--shadow-sm);">
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <?php
      $styleguide_swatches = ['--accent', '--success', '--warning', '--danger', '--bg-primary', '--bg-card', '--text-primary', '--text-secondary', '--border'];
      foreach ($styleguide_swatches as $sg_var) {
        echo '<span style="display:flex; flex-direction:column; align-items:center; gap:4px;">'
           . '<span style="width:48px; height:32px; border-radius:6px; border:1px solid var(--border); background: var(' . $sg_var . ');"></span>'
           . '<code style="font-size:0.65em; color: var(--text-muted);">' . h($sg_var) . '</code></span>';
      }
      ?>
    </div>
  </div>
</div>
