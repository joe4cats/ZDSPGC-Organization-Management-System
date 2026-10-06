/* =============================================================================
   charts.js — dashboard charts.
   The server renders every chart as
     <section class="card chart-card">
       <div class="chart-box"><canvas id="x"></canvas>
         <div class="chart-fallback" hidden></div></div>
       <div class="chart-data" hidden data-chart='{ …json… }'></div>
     </section>
   When Chart.js is available (loaded from the CDN in the page head) the canvas
   is drawn with it. When it is not — an offline campus network, for example —
   the same numbers are drawn as accessible bars, so a dashboard never shows an
   empty box.
   ============================================================================= */
(function () {
  'use strict';

  function configOf(holder) {
    try { return JSON.parse(holder.getAttribute('data-chart')); } catch (e) { return null; }
  }

  /** Draws the values as plain bars (no chart library required). */
  function renderBars(box, config) {
    var fallback = box.querySelector('.chart-fallback');
    var canvas   = box.querySelector('canvas');
    if (!fallback) { return; }

    var labels   = (config.data && config.data.labels) || [];
    var datasets = (config.data && config.data.datasets) || [];
    var first    = datasets[0] ? datasets[0].data || [] : [];
    var max      = 1;
    first.forEach(function (value) { if (typeof value === 'number' && value > max) { max = value; } });

    var html = '<ul class="bar-list">';
    labels.forEach(function (label, index) {
      var value = first[index] === undefined ? 0 : first[index];
      var pct   = Math.max(2, Math.round((Number(value) / max) * 100));
      var safe  = String(label).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
      html += '<li><span class="bar-label" title="' + safe + '">' + safe + '</span>'
        + '<span class="bar-track"><span class="bar-fill" style="width:' + pct + '%"></span></span>'
        + '<span class="bar-value">' + (typeof value === 'number' ? Math.round(value) : value) + '</span></li>';
    });
    fallback.innerHTML = html + '</ul>';
    fallback.hidden = false;
    if (canvas) { canvas.hidden = true; }
  }

  function init() {
    document.querySelectorAll('.chart-card').forEach(function (card) {
      var holder = card.querySelector('.chart-data[data-chart]');
      var box    = card.querySelector('.chart-box');
      var canvas = card.querySelector('canvas');
      if (!holder || !box || !canvas) { return; }

      var config = configOf(holder);
      if (!config) { return; }

      if (typeof window.Chart === 'undefined') {
        renderBars(box, config);
        return;
      }
      try {
        new window.Chart(canvas.getContext('2d'), config);   // eslint-disable-line no-new
      } catch (error) {
        renderBars(box, config);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
