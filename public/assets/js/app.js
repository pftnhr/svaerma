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

  // Settings accordion: only one section open. Browsers that know <details name>
  // do this themselves; this is the fallback for older ones.
  document.querySelectorAll('details[name]').forEach(function (details) {
    details.addEventListener('toggle', function () {
      if (!details.open) return;
      document.querySelectorAll('details[name="' + details.getAttribute('name') + '"]').forEach(function (other) {
        if (other !== details && other.open) other.open = false;
      });
    });
  });

  // A link to /settings#api opens that section.
  if (location.hash.length > 1) {
    var target = document.getElementById(location.hash.slice(1));
    if (target && target.tagName === 'DETAILS') target.open = true;
  }

  // PWA: register the service worker (served by PHP at /sw.js, see PwaController).
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(function () { /* not critical */ });
  }

  // Read-only fields (e.g. a new API token) select their content on focus for easy copying.
  document.querySelectorAll('[data-select-on-focus]').forEach(function (input) {
    input.addEventListener('focus', function () { input.select(); });
  });
});
