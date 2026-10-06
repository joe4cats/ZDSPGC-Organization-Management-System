/* =============================================================================
   qr.js — client-side QR rendering for the organization system.
   Wraps the vendored qrcode-generator library (MIT, © Kazuhiko Arase), so PHP
   only has to print:  <div data-qr="SIGNED_TOKEN"></div>
   The token is produced and signed by PHP (includes/Qr.php); this file only
   draws it, and can also print it to the current page for a paper poster.
   ============================================================================= */
(function (global) {
  'use strict';

  function build(text) {
    if (typeof global.qrcode !== 'function') { return null; }
    var qr = global.qrcode(0, 'M');   // 0 = pick the smallest version that fits
    qr.addData(text, 'Byte');
    qr.make();
    return qr;
  }

  /** Draws one element's data-qr payload into a canvas. */
  function renderElement(el) {
    var text = el.getAttribute('data-qr');
    if (!text) { return; }
    var qr = build(text);
    if (!qr) { return; }

    var modules = qr.getModuleCount();
    var cell    = parseInt(el.getAttribute('data-qr-cell') || '6', 10);
    var quiet   = 2;
    var px      = (modules + quiet * 2) * cell;

    var canvas = el.querySelector('canvas');
    if (!canvas) {
      canvas = document.createElement('canvas');
      el.appendChild(canvas);
    }
    canvas.width = px;
    canvas.height = px;
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', el.getAttribute('data-qr-label') || 'QR code');

    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, px, px);
    ctx.fillStyle = '#0f3d22';
    for (var r = 0; r < modules; r++) {
      for (var c = 0; c < modules; c++) {
        if (qr.isDark(r, c)) { ctx.fillRect((c + quiet) * cell, (r + quiet) * cell, cell, cell); }
      }
    }
    el.classList.add('qr-ready');
  }

  function renderAll(root) {
    (root || document).querySelectorAll('[data-qr]').forEach(renderElement);
  }

  /** Prints the page (used by the "Print event QR poster" action). */
  function printPage() { window.print(); }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { renderAll(); });
  } else {
    renderAll();
  }

  global.ZDSPGCQr = { render: renderElement, renderAll: renderAll, print: printPage };
})(window);
