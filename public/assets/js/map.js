// Leaflet maps: a single point on the detail page, all check-ins on /map.
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  if (!window.L) return;

  var ATTRIBUTION = '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende';
  // Marker colours come from CSS variables, so themes and the dark variant set them.
  // The overview map draws on a canvas (fast with thousands of points), which CSS
  // cannot style directly; the class is there for the SVG marker on detail pages.
  var css = getComputedStyle(document.documentElement);
  function cssVar(name, fallback) {
    return css.getPropertyValue(name).trim() || fallback;
  }
  var MARKER = {
    className: 'sv-marker',
    radius: 7,
    weight: 2,
    color: cssVar('--sv-marker-stroke', '#fff'),
    fillColor: cssVar('--sv-marker-fill', '#D93A16'),
    fillOpacity: 0.95,
  };

  function tiles(map, url) {
    L.tileLayer(url, { maxZoom: 19, attribution: ATTRIBUTION }).addTo(map);
  }

  // Detail page
  document.querySelectorAll('[data-map-point]').forEach(function (el) {
    var lat = parseFloat(el.dataset.lat);
    var lon = parseFloat(el.dataset.lon);
    var map = L.map(el, { scrollWheelZoom: false }).setView([lat, lon], 17);
    tiles(map, el.dataset.tiles);
    // Leaflet inserts string content as HTML; a text node keeps the name plain text.
    L.circleMarker([lat, lon], MARKER).addTo(map).bindTooltip(document.createTextNode(el.dataset.name || ''));
  });

  // All check-ins
  var all = document.getElementById('map-all');
  if (!all) return;

  var map = L.map(all, { preferCanvas: true, worldCopyJump: true }).setView([51.1, 10.4], 6);
  tiles(map, all.dataset.tiles);

  fetch(all.dataset.src, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    .then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    })
    .then(function (rows) {
      var loading = all.querySelector('.sv-map-loading');
      if (loading) loading.remove();
      if (!rows.length) return;

      var bounds = [];
      rows.forEach(function (r) {
        // r = [id, lat, lon, name, path, date]
        var popup = document.createElement('div');
        var link = document.createElement('a');
        link.href = r[4];
        link.textContent = r[3];
        var date = document.createElement('div');
        date.className = 'sv-muted sv-small';
        date.textContent = r[5].split('-').reverse().join('.');
        popup.appendChild(link);
        popup.appendChild(date);
        L.circleMarker([r[1], r[2]], MARKER).addTo(map).bindPopup(popup);
        bounds.push([r[1], r[2]]);
      });
      map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
    })
    .catch(function (err) {
      var loading = all.querySelector('.sv-map-loading');
      if (loading) loading.textContent = 'Karte konnte nicht geladen werden (' + err.message + ').';
    });
});
