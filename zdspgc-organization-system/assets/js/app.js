/* =============================================================================
   ZDSPGC Organization Management System — shared front-end behaviour
   Progressive enhancement only: every page still works without JavaScript.
   ============================================================================= */
(function () {
  'use strict';

  var appUrl   = document.querySelector('meta[name="app-url"]');
  var baseUrl  = appUrl ? appUrl.getAttribute('content') : '/';
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var CSRF     = csrfMeta ? csrfMeta.getAttribute('content') : '';

  var Z = {
    baseUrl: baseUrl,
    csrf: CSRF,

    /** Absolute URL for an application path ("admin/events.php"). */
    url: function (path) {
      return baseUrl.replace(/\/$/, '') + '/' + String(path || '').replace(/^\//, '');
    },

    /* ------------------------------------------------------------- toasts */
    toast: function (message, type, ms) {
      var stack = document.getElementById('toast-stack');
      if (!stack) { return; }
      var icons = {
        success: '<path d="M20 6L9 17l-5-5"/>',
        error:   '<path d="M12 8v5M12 17h.01"/><circle cx="12" cy="12" r="9"/>',
        warning: '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        info:    '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>'
      };
      var el = document.createElement('div');
      el.className = 'toast ' + (type || 'info');
      el.setAttribute('role', 'status');
      el.innerHTML = '<svg class="ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        + ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
        + (icons[type] || icons.info) + '</svg><span></span>';
      el.querySelector('span').textContent = message;
      stack.appendChild(el);
      window.setTimeout(function () {
        el.classList.add('hide');
        window.setTimeout(function () { el.remove(); }, 300);
      }, ms || 4200);
    },

    /* ---------------------------------------------------------- fetch/API */
    /**
     * Calls a JSON endpoint of this application: adds the CSRF header, parses
     * the {success, message, data} envelope and rejects with that message.
     */
    api: function (path, options) {
      var opts = options || {};
      var init = {
        method: opts.method || 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin'
      };
      if (opts.body !== undefined) {
        init.headers['Content-Type'] = 'application/json';
        init.headers['X-CSRF-Token'] = CSRF;
        init.body = JSON.stringify(opts.body);
      } else if (init.method !== 'GET') {
        init.headers['X-CSRF-Token'] = CSRF;
        init.body = new FormData(opts.form || undefined);
      }

      return fetch(this.url(path), init).then(function (response) {
        return response.json().catch(function () {
          throw new Error('The server returned an unexpected response (HTTP ' + response.status + ').');
        }).then(function (payload) {
          if (!response.ok || payload.success === false) {
            var err = new Error(payload.message || 'Request failed.');
            err.status = response.status;
            err.payload = payload;
            throw err;
          }
          return payload;
        });
      });
    },

    /* ------------------------------------------------------------ modals */
    openModal: function (options) {
      var root = document.getElementById('modal-root');
      if (!root) { return; }
      var box = root.querySelector('.modal-box');
      box.classList.toggle('wide', !!options.wide);
      document.getElementById('modal-title').textContent = options.title || 'Dialog';
      document.getElementById('modal-body').innerHTML = options.body || '';
      document.getElementById('modal-foot').innerHTML = options.footer || '';
      root.hidden = false;
      document.body.style.overflow = 'hidden';
      if (typeof options.onOpen === 'function') { options.onOpen(document.getElementById('modal-body')); }
    },

    closeModal: function () {
      var root = document.getElementById('modal-root');
      if (!root) { return; }
      root.hidden = true;
      document.getElementById('modal-body').innerHTML = '';
      document.getElementById('modal-foot').innerHTML = '';
      document.body.style.overflow = '';
    },

    /** Promise-based confirmation dialog (the in-app replacement for window.confirm). */
    confirm: function (options) {
      var opts = options || {};
      var root = document.getElementById('modal-root');
      if (!root) { return Promise.resolve(window.confirm(opts.message || 'Are you sure?')); }

      return new Promise(function (resolve) {
        var body = '<p class="mb">' + (opts.message || 'Are you sure?') + '</p>';
        var foot = '<button class="btn grey" type="button" data-modal-close>' + (opts.cancelLabel || 'Cancel') + '</button>'
          + '<button class="btn ' + (opts.danger ? 'danger' : '') + '" type="button" id="modal-confirm-yes">'
          + (opts.confirmLabel || 'Confirm') + '</button>';

        function done(value) {
          Z.closeModal();
          root.removeEventListener('click', onClick);
          document.removeEventListener('keydown', onKey);
          resolve(value);
        }
        function onKey(event) { if (event.key === 'Escape') { done(false); } }
        function onClick(event) {
          if (event.target.closest('#modal-confirm-yes')) { done(true); }
          else if (event.target.closest('[data-modal-close]')) { done(false); }
        }

        Z.openModal({ title: opts.title || 'Please confirm', body: body, footer: foot });
        root.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey);
        var yes = document.getElementById('modal-confirm-yes');
        if (yes) { yes.focus(); }
      });
    }
  };

  window.ZDSPGC = Z;

  /* ======================================================= global wiring */
  document.addEventListener('DOMContentLoaded', function () {
    // Off-canvas navigation on small screens
    var body     = document.body;
    var menuBtn  = document.getElementById('menu-btn');
    var closeBtn = document.getElementById('sidebar-close');
    var backdrop = document.getElementById('sidebar-backdrop');
    function setNav(open) {
      body.classList.toggle('nav-open', open);
      if (menuBtn) { menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }
    if (menuBtn)  { menuBtn.addEventListener('click', function () { setNav(!body.classList.contains('nav-open')); }); }
    if (closeBtn) { closeBtn.addEventListener('click', function () { setNav(false); }); }
    if (backdrop) { backdrop.addEventListener('click', function () { setNav(false); }); }

    // Password show/hide toggle (works for every .pw-toggle button)
    var PW_EYE     = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    var PW_EYE_OFF = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
      btn.addEventListener('click', function (event) {
        event.preventDefault();
        var wrap  = btn.closest('.pw-wrap');
        var input = wrap ? wrap.querySelector('input') : null;
        if (!input) { return; }
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        var svg = btn.querySelector('svg');
        if (svg) { svg.innerHTML = show ? PW_EYE_OFF : PW_EYE; }
        input.focus();
      });
    });

    // Dismissable flash messages (info/success auto-hide)
    document.querySelectorAll('.flash').forEach(function (flash) {
      var close = flash.querySelector('.flash-close');
      if (close) { close.addEventListener('click', function () { flash.remove(); }); }
      if (flash.classList.contains('flash-success') || flash.classList.contains('flash-info')) {
        window.setTimeout(function () { flash.remove(); }, 8000);
      }
    });

    // Modal close handlers
    document.querySelectorAll('[data-modal-close]').forEach(function (el) {
      el.addEventListener('click', function () { Z.closeModal(); });
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { Z.closeModal(); }
    });

    // data-confirm on links and forms
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (event) {
        var message = el.getAttribute('data-confirm');
        if (el.tagName === 'FORM') {
          event.preventDefault();
          Z.confirm({ title: 'Please confirm', message: message, danger: true, confirmLabel: 'Yes, continue' })
            .then(function (ok) { if (ok) { el.submit(); } });
        } else if (!window.confirm(message)) {
          event.preventDefault();
        }
      });
    });

    // AJAX forms: <form data-ajax="1" data-reload="false">
    document.querySelectorAll('form[data-ajax]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var submit = form.querySelector('[type=submit]');
        if (submit) { submit.disabled = true; }
        Z.api(form.getAttribute('action') || window.location.pathname, { method: 'POST', form: form })
          .then(function (payload) {
            Z.toast(payload.message || 'Saved successfully.', 'success');
            if (form.getAttribute('data-reload') !== 'false') {
              window.setTimeout(function () { window.location.reload(); }, 700);
            }
          })
          .catch(function (error) {
            Z.toast(error.message, 'error');
            if (submit) { submit.disabled = false; }
          });
      });
    });

    // Instant client-side filter: <input data-filter="#container"> — works on
    // table rows (tbody tr) and organization cards (.org-card).
    document.querySelectorAll('[data-filter]').forEach(function (input) {
      var target = document.querySelector(input.getAttribute('data-filter'));
      if (!target) { return; }
      input.addEventListener('input', function () {
        var needle = input.value.trim().toLowerCase();
        var rows   = target.querySelectorAll('tbody tr, .org-card');
        var shown  = 0;
        rows.forEach(function (row) {
          var match = needle === '' || row.textContent.toLowerCase().indexOf(needle) !== -1;
          row.hidden = !match;
          if (match) { shown++; }
        });
        var empty = document.querySelector('[data-filter-empty]');
        if (empty) { empty.hidden = shown !== 0 || needle === ''; }
      });
    });

    // Select-all checkbox for bulk table actions
    document.querySelectorAll('[data-check-all]').forEach(function (master) {
      var scope = document.querySelector(master.getAttribute('data-check-all')) || document;
      master.addEventListener('change', function () {
        scope.querySelectorAll('input[data-row-check]').forEach(function (box) { box.checked = master.checked; });
      });
    });

    // Print buttons
    document.querySelectorAll('[data-print]').forEach(function (el) {
      el.addEventListener('click', function (event) { event.preventDefault(); window.print(); });
    });
  });
})();


