/* =============================================================================
   scanner.js — camera QR scanning for the attendance module.
   Uses the vendored html5-qrcode library. The scanned value is posted to
   api/attendance/scan.php, which re-validates the signed token server side —
   the browser never decides whether a check-in is valid.
   ============================================================================= */
(function (global) {
  'use strict';

  var Z = global.ZDSPGC;

  function Scanner(options) {
    this.region   = document.getElementById(options.region || 'scanner-region');
    this.endpoint = options.endpoint;
    this.onResult = options.onResult || function () {};
    this.instance = null;
    this.running  = false;
    this.lastCode = null;
  }

  Scanner.prototype.start = function () {
    if (!this.region) { return; }
    if (typeof global.Html5Qrcode !== 'function') {
      this.region.innerHTML = '<p class="hint" style="padding:16px">The scanner library did not load. '
        + 'Use the manual entry box below instead.</p>';
      return;
    }
    var self = this;
    this.instance = new global.Html5Qrcode(this.region.id, { fps: 10, qrbox: 220 });
    this.instance.start(
      { facingMode: 'environment' },
      { fps: 10 },
      function (text) { self.handle(text); },
      function () { /* per-frame decode miss: ignore */ }
    ).then(function () {
      self.running = true;
    }).catch(function (error) {
      self.running = false;
      self.region.innerHTML = '<p class="hint" style="padding:16px">Camera unavailable: '
        + (error && error.message ? error.message : 'permission denied')
        + '. Use the manual entry box below, or allow camera access in your browser.</p>';
    });
  };

  Scanner.prototype.stop = function () {
    if (this.instance && this.running) {
      this.instance.stop().catch(function () { /* already stopped */ });
      this.running = false;
    }
  };

  /** Debounces repeated reads of the same code, then sends it to the server. */
  Scanner.prototype.handle = function (text) {
    if (!text || text === this.lastCode) { return; }
    this.lastCode = text;
    var self = this;
    window.setTimeout(function () { self.lastCode = null; }, 4000);

    Z.api(self.endpoint, { method: 'POST', body: { token: text } })
      .then(function (payload) {
        self.onResult(true, payload);
      })
      .catch(function (error) {
        self.onResult(false, error);
      });
  };

  global.ZDSPGCScanner = Scanner;
})(window);
