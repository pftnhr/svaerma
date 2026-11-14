// Check-in page: geolocation → nearby places (Overpass) / search (Photon/Nominatim) → confirm.
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var root = document.getElementById('checkin');
  if (!root) return;

  var baseRadius = parseInt(root.dataset.radius, 10) || 150;
  var maxRadius = parseInt(root.dataset.maxRadius, 10) || 1000;
  var radius = baseRadius;
  var position = null; // {lat, lon, accuracy}

  var statusEl = document.getElementById('geo-status');
  var listEl = document.getElementById('places');
  var messageEl = document.getElementById('places-message');
  var widenBtn = document.getElementById('widen');
  var form = document.getElementById('checkin-form');
  var confirmEl = document.getElementById('confirm');
  var manualToggle = document.getElementById('manual-toggle');
  var manualEl = document.getElementById('manual');

  form.elements.timezone.value = Intl.DateTimeFormat().resolvedOptions().timeZone || '';

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function setStatus(text) {
    statusEl.textContent = text;
  }

  // type: 'quiet' (progress, nothing found), 'info' or 'warning'
  function showMessage(text, type) {
    messageEl.className = 'sv-nearby__message sv-alert sv-alert--' + (type || 'quiet');
    messageEl.textContent = text;
    messageEl.hidden = false;
  }

  function hideMessage() {
    messageEl.hidden = true;
  }

  // Same place, same tilt: -9° to +9° derived from the OSM id (or the name).
  function tilt(p) {
    var key = (p.osm_type || '') + (p.osm_id || p.name || '');
    var hash = 0;
    for (var i = 0; i < key.length; i++) hash = (hash * 31 + key.charCodeAt(i)) | 0;
    return (Math.abs(hash) % 19) - 9;
  }

  // "01.11.2026" → "01.11." in the current year, otherwise unchanged.
  function shortDate(date) {
    var year = String(new Date().getFullYear());
    return date && date.slice(-4) === year ? date.slice(0, -4) : date;
  }

  function formatDistance(m) {
    if (m === null || m === undefined) return '';
    return m < 1000 ? m + ' m' : (m / 1000).toFixed(1).replace('.', ',') + ' km';
  }

  function getJson(url) {
    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (res.status === 401) {
            window.location.href = '/login';
          }
          if (!res.ok) {
            throw new Error((data.error || 'Fehler ' + res.status) + (data.detail ? ' (' + data.detail + ')' : ''));
          }
          return data;
        });
      });
  }

  function renderPlaces(places, emptyText) {
    listEl.textContent = '';
    if (!places.length) {
      showMessage(emptyText, 'quiet');
      return;
    }
    hideMessage();
    places.forEach(function (p) {
      var item = el('button', 'sv-place');
      item.type = 'button';

      var body = el('span', 'sv-place__body');
      body.appendChild(el('span', 'sv-place__name', p.name));
      var meta = [p.category, p.address].filter(Boolean).join(' · ');
      if (meta) body.appendChild(el('span', 'sv-place__meta', meta));
      if (p.visits > 0) {
        body.appendChild(el('span', 'sv-chip', 'schon gestempelt · ' + shortDate(p.last_visit) + (p.visits > 1 ? ' · ' + p.visits + '×' : '')));
      }
      item.appendChild(body);
      if (p.distance !== null && p.distance !== undefined) {
        var stamp = el('span', 'sv-stamp', formatDistance(p.distance));
        stamp.style.setProperty('--sv-tilt', tilt(p) + 'deg');
        item.appendChild(stamp);
      }
      item.addEventListener('click', function () { choose(p); });
      listEl.appendChild(item);
    });
  }

  function choose(p) {
    form.elements.lat.value = p.lat;
    form.elements.lon.value = p.lon;
    form.elements.place_name.value = p.name;
    form.elements.osm_type.value = p.osm_type || '';
    form.elements.osm_id.value = p.osm_id || '';
    document.getElementById('confirm-title').textContent = p.name;
    var meta = [p.category, p.address].filter(Boolean).join(' · ');
    if (!p.osm_type) meta = 'Eigener Ort · aktueller Standort';
    document.getElementById('confirm-meta').textContent = meta;
    if (typeof confirmEl.showModal === 'function') {
      if (!confirmEl.open) confirmEl.showModal();
    } else {
      confirmEl.setAttribute('open', '');
    }
  }

  function closeConfirm() {
    if (typeof confirmEl.close === 'function') {
      confirmEl.close();
    } else {
      confirmEl.removeAttribute('open');
    }
  }

  document.getElementById('confirm-close').addEventListener('click', closeConfirm);
  // A click on the backdrop (outside the sheet) closes it.
  confirmEl.addEventListener('click', function (e) {
    if (e.target === confirmEl) closeConfirm();
  });

  function loadNearby() {
    if (!position) return;
    // Never search a smaller area than the GPS uncertainty.
    var r = Math.min(maxRadius, Math.max(radius, Math.round(position.accuracy || 0)));
    showMessage('Suche Orte im Umkreis von ' + r + ' m …', 'quiet');
    listEl.textContent = '';
    getJson('/places/nearby?lat=' + position.lat + '&lon=' + position.lon + '&radius=' + r)
      .then(function (data) {
        renderPlaces(data.places, 'Keine Orte im Umkreis von ' + r + ' m gefunden. Erweitere den Umkreis, suche nach dem Namen oder lege einen eigenen Ort an.');
        if (data.notice && data.places.length) showMessage(data.notice, 'info');
        widenBtn.hidden = r >= maxRadius;
      })
      .catch(function (err) {
        showMessage(err.message, 'warning');
      });
  }

  function locate() {
    if (!navigator.geolocation) {
      setStatus('Dein Browser unterstützt keine Standortbestimmung. Nutze die Suche.');
      return;
    }
    setStatus('Standort wird ermittelt …');
    navigator.geolocation.getCurrentPosition(function (pos) {
      position = { lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: pos.coords.accuracy };
      setStatus('Standort ±' + Math.round(pos.coords.accuracy) + ' m');
      radius = baseRadius;
      loadNearby();
    }, function (err) {
      var text = err.code === 1
        ? 'Standortzugriff verweigert. Erlaube ihn in den Browser-Einstellungen oder nutze die Suche.'
        : 'Standort konnte nicht ermittelt werden. Nutze die Suche.';
      setStatus(text);
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 30000 });
  }

  document.getElementById('geo-refresh').addEventListener('click', locate);

  widenBtn.addEventListener('click', function () {
    radius = Math.min(maxRadius, radius * 2);
    loadNearby();
  });

  document.getElementById('search-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var q = document.getElementById('search-q').value.trim();
    if (q.length < 2) return;
    var url = '/places/search?q=' + encodeURIComponent(q);
    if (position) url += '&lat=' + position.lat + '&lon=' + position.lon;
    showMessage('Suche „' + q + '“ …', 'quiet');
    listEl.textContent = '';
    widenBtn.hidden = true;
    getJson(url)
      .then(function (data) { renderPlaces(data.places, 'Nichts gefunden für „' + q + '“.'); })
      .catch(function (err) { showMessage(err.message, 'warning'); });
  });

  manualToggle.addEventListener('click', function () {
    var open = manualToggle.getAttribute('aria-expanded') !== 'true';
    manualToggle.setAttribute('aria-expanded', String(open));
    manualEl.hidden = !open;
    if (open) document.getElementById('manual-name').focus();
  });

  document.getElementById('manual-use').addEventListener('click', function () {
    var name = document.getElementById('manual-name').value.trim();
    if (!name) {
      document.getElementById('manual-name').focus();
      return;
    }
    if (!position) {
      showMessage('Für einen eigenen Ort brauche ich deinen Standort.', 'warning');
      return;
    }
    choose({ name: name, lat: position.lat, lon: position.lon });
  });

  locate();
});
