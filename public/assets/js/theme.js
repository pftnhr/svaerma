// Browser bar colour (meta theme-color) from the CSS variable --sv-theme-color,
// so themes and the dark variant set it in CSS. Runs in <head> after the stylesheets.
(function () {
  'use strict';
  var meta = document.querySelector('meta[name="theme-color"]');
  if (!meta) return;
  function apply() {
    var color = getComputedStyle(document.documentElement).getPropertyValue('--sv-theme-color').trim();
    if (color) meta.setAttribute('content', color);
  }
  apply();
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', apply);
  }
})();
