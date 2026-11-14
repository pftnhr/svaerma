// Small global behaviours, loaded on every page.
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  // <form data-confirm="Wirklich?"> asks before submitting (no inline JS because of the CSP).
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });

  // Close buttons in flash messages.
  document.querySelectorAll('[data-dismiss]').forEach(function (button) {
    button.addEventListener('click', function () {
      var alert = button.closest('.sv-alert');
      if (alert) alert.remove();
    });
  });

  // PWA: register the service worker (served by PHP at /sw.js, see PwaController).
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(function () { /* not critical */ });
  }

  // Read-only fields (e.g. a new API token) select their content on focus for easy copying.
  document.querySelectorAll('[data-select-on-focus]').forEach(function (input) {
    input.addEventListener('focus', function () { input.select(); });
  });
});
