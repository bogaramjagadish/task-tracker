// Progressive enhancement only: every rule here is ALSO enforced by the PHP backend.
(function () {
  'use strict';

  // Confirm before destructive actions (e.g. delete).
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var msg = form.getAttribute && form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      ev.preventDefault();
    }
  });

  // Friendly client-side validation for required fields.
  document.querySelectorAll('form[data-validate]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      var firstBad = null;
      form.querySelectorAll('[data-required]').forEach(function (input) {
        var field = input.closest('.field');
        var out = field && field.querySelector('.field-error');
        var empty = input.value.trim() === '';
        if (field) { field.classList.toggle('has-error', empty); }
        if (out && empty) { out.textContent = input.getAttribute('data-required'); }
        if (empty && !firstBad) { firstBad = input; }
      });
      if (firstBad) {
        ev.preventDefault();
        firstBad.focus();
      }
    });
  });

  // Changing a status dropdown saves immediately (the "Set" button is the no-JS fallback).
  document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
    var btn = sel.form && sel.form.querySelector('button');
    if (btn) { btn.hidden = true; }
    sel.addEventListener('change', function () { sel.form.submit(); });
  });
})();
