/* AI Calendar [wpaical_calendar]: month / week / list calendar of the cached events. */
(function () {
  'use strict';

  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var DOWS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var VIEWER_TZ = Intl.DateTimeFormat().resolvedOptions().timeZone;
  var instances = 0;
  function validTz(tz) {
    try { new Intl.DateTimeFormat('en-US', { timeZone: tz }); return !!tz; } catch (e) { return false; }
  }
  var TZ_KEY = 'wpaicalCalTz';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function safeUrl(u) { return /^https?:\/\//i.test(u || '') ? u : ''; }
  function hexToRgba(hex, a) {
    var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
    if (!m) { return 'rgba(110,110,110,' + a + ')'; }
    var n = parseInt(m[1], 16);
    return 'rgba(' + (n >> 16) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
  }

  // ---- timezone maths (event times arrive as wall-clock strings in the site timezone) ----
  function tzParts(utcMs, tz) {
    var p = new Intl.DateTimeFormat('en-US', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }).formatToParts(new Date(utcMs));
    function g(t) { return +p.filter(function (x) { return x.type === t; })[0].value; }
    return { y: g('year'), m: g('month'), d: g('day'), hh: g('hour'), mm: g('minute'), ss: g('second') };
  }
  function offsetMs(utcMs, tz) {
    var q = tzParts(utcMs, tz);
    return Date.UTC(q.y, q.m - 1, q.d, q.hh, q.mm, q.ss) - utcMs;
  }
  function zonedToUtc(y, m, d, hh, mm, tz) {
    var guess = Date.UTC(y, m - 1, d, hh, mm);
    var u = guess - offsetMs(guess, tz);
    return guess - offsetMs(u, tz);
  }
  function tzName(tz, long) {
    try {
      var n = new Intl.DateTimeFormat('en-US', { timeZone: tz, timeZoneName: long ? 'long' : 'short' }).formatToParts(new Date()).filter(function (x) { return x.type === 'timeZoneName'; })[0];
      return n ? n.value : tz;
    } catch (e) { return tz; }
  }
  function fmtTime(hh, mm) {
    var ap = hh >= 12 ? 'PM' : 'AM';
    var h = hh % 12 || 12;
    return h + ':' + pad(mm) + ' ' + ap;
  }
  function fmtRange(a, b) { // "5:00 – 7:00 PM"
    var s = fmtTime(a.hh, a.mm), e = fmtTime(b.hh, b.mm);
    return (s.slice(-2) === e.slice(-2) ? s.slice(0, -3) : s) + '–' + e;
  }

  function Calendar(root) {
    var cfg;
    try { cfg = JSON.parse(root.getAttribute('data-config')); } catch (e) { return; }
    var uid = 'wc-mt-' + (++instances);
    if (!validTz(cfg.tz)) { cfg.tz = VIEWER_TZ; }
    var self = this;
    var tzMode = 'viewer';
    try { if (localStorage.getItem(TZ_KEY) === 'site') { tzMode = 'site'; } } catch (e) {}
    var view = cfg.view;
    if (window.innerWidth <= 480 && cfg.views.indexOf('week') !== -1) { view = 'week'; }
    var activeCat = 'all';
    var SRC = {};            // id -> event as sent by the API
    var EVENTS = [];         // converted to the displayed timezone
    var loaded = {};         // 'YYYY-MM' -> true
    var failed = false, loading = false;
    var slugs = {};
    cfg.cats.forEach(function (c) { slugs[c.name.toLowerCase()] = c.slug; });
    var needles = (cfg.program || '').split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
    var only = (cfg.only || []).map(function (s) { return s.toLowerCase(); });

    function viewTz() { return tzMode === 'site' ? cfg.tz : VIEWER_TZ; }
    function sameZone() { return VIEWER_TZ === cfg.tz; }
    function todayParts() { return tzParts(Date.now(), viewTz()); }
    var t0 = todayParts();
    var cur = { y: t0.y, m: t0.m - 1 };
    var weekStart = new Date(t0.y, t0.m - 1, t0.d);
    weekStart.setDate(weekStart.getDate() - ((weekStart.getDay() - cfg.weekStart + 7) % 7));

    function slug(cat) { return slugs[(cat || '').toLowerCase()] || ''; }
    function dc(cat) { var s = slug(cat); return s ? ' data-cat="' + esc(s) + '"' : ''; }
    function label(cat) { return (cfg.labels && cfg.labels[cat]) || cat; }

    // ---- data ----
    function matches(ev) {
      var t = (ev.title || '').toLowerCase();
      if (needles.length) {
        var ok = needles.some(function (n) { return cfg.match === 'exact' ? t === n : (cfg.match === 'contains' ? t.indexOf(n) !== -1 : t.indexOf(n) === 0); });
        if (!ok) { return false; }
      }
      if (only.length) {
        var c = ev.categories && ev.categories[0] ? ev.categories[0].name.toLowerCase() : '';
        if (only.indexOf(c) === -1) { return false; }
      }
      return true;
    }
    function convert() {
      var tz = viewTz();
      EVENTS = Object.keys(SRC).map(function (id) {
        var ev = SRC[id];
        if (!matches(ev)) { return null; }
        var sp = ev.start_date.split(' '), ep = ev.end_date.split(' ');
        var sd = sp[0].split('-').map(Number), ed = ep[0].split(' ')[0].split('-').map(Number);
        var cat = ev.categories && ev.categories[0] ? ev.categories[0].name : '';
        var o = { id: ev.id, title: ev.title, cat: cat, link: safeUrl(ev.url), loc: ev.location || '', allDay: !!ev.all_day };
        if (ev.all_day) {
          o.y = sd[0]; o.m = sd[1]; o.d = sd[2]; o.endY = ed[0]; o.endM = ed[1]; o.endD = ed[2];
          o.sort = 0; o.label = 'All day';
        } else {
          var st = sp[1].split(':').map(Number), en = ep[1].split(':').map(Number);
          var sp2 = tzParts(zonedToUtc(sd[0], sd[1], sd[2], st[0], st[1], cfg.tz), tz);
          var ep2 = tzParts(zonedToUtc(ed[0], ed[1], ed[2], en[0], en[1], cfg.tz), tz);
          o.y = sp2.y; o.m = sp2.m; o.d = sp2.d; o.sort = sp2.hh * 60 + sp2.mm;
          o.label = fmtRange(sp2, ep2);
        }
        return o;
      }).filter(Boolean);
    }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function ensureMonth(y, m) { // m is 0-based
      var key = y + '-' + pad(m + 1);
      if (loaded[key]) { return Promise.resolve(); }
      var from = new Date(y, m, 1); from.setDate(from.getDate() - 7);
      var to = new Date(y, m + 1, 0); to.setDate(to.getDate() + 7);
      return fetch(cfg.rest + (cfg.rest.indexOf('?') < 0 ? '?' : '&') + 'per_page=500&start_date=' + iso(from) + '&end_date=' + iso(to))
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) {
          if (d.timezone && validTz(d.timezone)) { cfg.tz = d.timezone; }
          (d.events || []).forEach(function (e) { SRC[e.id] = e; });
          loaded[key] = true;
        });
    }
    function monthsNeeded() {
      if (view === 'week') {
        var a = new Date(weekStart), b = new Date(weekStart); b.setDate(b.getDate() + 6);
        var out = [[a.getFullYear(), a.getMonth()]];
        if (b.getMonth() !== a.getMonth()) { out.push([b.getFullYear(), b.getMonth()]); }
        return out;
      }
      return [[cur.y, cur.m]];
    }
    function load() {
      loading = true; failed = false; draw();
      return Promise.all(monthsNeeded().map(function (p) { return ensureMonth(p[0], p[1]); }))
        .then(function () { loading = false; convert(); draw(); })
        .catch(function () { loading = false; failed = true; draw(); });
    }

    // ---- helpers ----
    function eventsOn(y, m, d) {
      return EVENTS.filter(function (e) {
        if (activeCat !== 'all' && e.cat !== activeCat) { return false; }
        if (e.allDay && (e.endY !== e.y || e.endM !== e.m || e.endD !== e.d)) { // multi-day all-day
          var t = new Date(y, m - 1, d).getTime();
          return t >= new Date(e.y, e.m - 1, e.d).getTime() && t <= new Date(e.endY, e.endM - 1, e.endD).getTime();
        }
        return e.y === y && e.m === m && e.d === d;
      }).sort(function (a, b) { return a.sort - b.sort; });
    }
    function isToday(y, m, d) { var t = todayParts(); return t.y === y && t.m === m && t.d === d; }
    function title() {
      if (cfg.title) { return cfg.title; }
      if (view === 'week') {
        var a = new Date(weekStart), b = new Date(weekStart); b.setDate(b.getDate() + 6);
        var sl = MONTHS[a.getMonth()].slice(0, 3) + ' ' + a.getDate();
        var el = a.getMonth() === b.getMonth() ? '' + b.getDate() : MONTHS[b.getMonth()].slice(0, 3) + ' ' + b.getDate();
        return sl + '–' + el + ', ' + b.getFullYear();
      }
      return MONTHS[cur.m] + ' ' + cur.y;
    }
    var tgt = cfg.newTab ? ' target="_blank" rel="noopener"' : '';
    function itemHtml(e, withDate) {
      var name = e.link ? '<a class="wc-name" href="' + esc(e.link) + '"' + tgt + '>' + esc(e.title) + '</a>' : '<span class="wc-name">' + esc(e.title) + '</span>';
      return '<div class="wc-item"><span class="wc-time">' + esc(e.label) + '</span>' + name +
        (e.cat ? '<span class="wc-tag"' + dc(e.cat) + '>' + esc(e.cat) + '</span>' : '') +
        (e.loc ? '<span class="wc-loc">' + esc(e.loc) + '</span>' : '') + '</div>';
    }
    function rowHtml(date, evs) {
      var td = isToday(date.getFullYear(), date.getMonth() + 1, date.getDate());
      return '<div class="wc-row' + (td ? ' today' : '') + '"><div class="wc-rdate"><div class="wc-rdow">' + DOWS[date.getDay()] + '</div><div class="wc-rnum">' + date.getDate() + '</div></div><div class="wc-revs">' +
        (evs.length ? evs.map(itemHtml).join('') : '<p class="wc-none">No events</p>') + '</div></div>';
    }

    // ---- rendering ----
    var shell = null;
    function build() {
      root.innerHTML = '';
      shell = document.createElement('div');
      root.appendChild(shell);
      var ov = document.createElement('div');
      ov.className = 'wc-overlay'; ov.hidden = true;
      ov.innerHTML = '<div class="wc-modal" role="dialog" aria-modal="true" aria-labelledby="' + uid + '"><button type="button" class="wc-close" aria-label="Close">&#10005;</button><h3 id="' + uid + '"></h3><div class="wc-mbody"></div></div>';
      root.appendChild(ov);
      self.overlay = ov;
      ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('.wc-close')) { closeModal(); } });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !ov.hidden) { closeModal(); } });
      shell.addEventListener('click', onClick);
      shell.addEventListener('keydown', function (e) {
        var d = e.target.closest && e.target.closest('.wc-day[data-d]');
        if (d && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); d.click(); }
      });
    }
    var lastFocus = null;
    function openModal(y, m, d, evs, opener) {
      lastFocus = opener || null;
      var dt = new Date(y, m - 1, d);
      self.overlay.querySelector('h3').textContent = DOWS[dt.getDay()] + ', ' + MONTHS[m - 1] + ' ' + d;
      self.overlay.querySelector('.wc-mbody').innerHTML = evs.length ? evs.map(function (e) {
        return '<div class="wc-mevt"><div class="wc-mtitle"><div class="wc-mrow"><span class="wc-time">' + esc(e.label) + '</span>' +
          (e.cat ? '<span class="wc-tag"' + dc(e.cat) + '>' + esc(e.cat) + '</span>' : '') + '</div>' +
          '<span class="wc-name">' + esc(e.title) + '</span>' + (e.loc ? '<span class="wc-loc">' + esc(e.loc) + '</span>' : '') +
          (e.link ? '<a class="wc-link" href="' + esc(e.link) + '"' + tgt + '>View event details &#8594;</a>' : '') + '</div></div>';
      }).join('') : '<p class="wc-none">No events on this day.</p>';
      self.overlay.hidden = false;
      self.overlay.querySelector('.wc-close').focus();
    }
    function closeModal() { self.overlay.hidden = true; if (lastFocus) { lastFocus.focus(); } }

    function tzLabel(tz, long) {
      var US = { 'America/New_York': 'Eastern Time (ET)', 'America/Chicago': 'Central Time (CT)', 'America/Denver': 'Mountain Time (MT)', 'America/Los_Angeles': 'Pacific Time (PT)' };
      return US[tz] || tzName(tz, long);
    }
    function draw() {
      var ae = document.activeElement, fsel = null;
      if (ae && shell && shell.contains(ae)) {
        ['data-view', 'data-nav', 'data-tz', 'data-filter'].some(function (a) {
          if (ae.hasAttribute(a)) { fsel = '[' + a + '="' + String(ae.getAttribute(a)).replace(/["\\]/g, '\\$&') + '"]'; return true; }
          return false;
        });
      }
      var wasToday = !!(ae && ae.classList && ae.classList.contains('wc-today') && shell && shell.contains(ae));
      if (wasToday) { fsel = '.wc-today'; }
      drawInner();
      if (fsel) { try { var el = shell.querySelector(fsel); if (el) { el.focus(); } } catch (e) {} }
    }
    function drawInner() {
      var showTz = cfg.tzToggle && !sameZone();
      var tzGroup = showTz ? '<div class="wc-views wc-tzgroup" role="group" aria-label="Show event times in">' +
        '<button type="button" data-tz="viewer" aria-pressed="' + (tzMode === 'viewer') + '">Your time</button>' +
        '<button type="button" data-tz="site" aria-pressed="' + (tzMode === 'site') + '">' + esc(tzLabel(cfg.tz, false)) + '</button></div>' : '';
      var viewBtns = cfg.views.length > 1 ? '<div class="wc-views" role="group" aria-label="View">' + cfg.views.map(function (v) {
        return '<button type="button" data-view="' + v + '" aria-pressed="' + (v === view) + '">' + (v === 'list' ? 'List' : v === 'week' ? 'Week' : 'Month') + '</button>';
      }).join('') + '</div>' : '';
      var legend = '';
      if (cfg.legend && cfg.cats.length) {
        legend = '<div class="wc-legend" role="group" aria-label="Filter by category"><button type="button" class="wc-chip" data-filter="" data-cat="all" aria-pressed="' + (activeCat === 'all') + '"><span class="dot"></span>All Events</button>' +
          cfg.cats.map(function (c) {
            return '<button type="button" class="wc-chip" data-filter="' + esc(c.name) + '" data-cat="' + esc(c.slug) + '" aria-pressed="' + (activeCat === c.name) + '"><span class="dot"></span>' + esc(label(c.name)) + '</button>';
          }).join('') + '</div>';
      }
      var body;
      if (loading && !EVENTS.length) { body = '<div class="wc-status">Loading events…</div>'; }
      else if (failed) { body = '<div class="wc-status">We couldn\'t load the events. <button type="button" data-retry="1">Try again</button></div>'; }
      else if (view === 'month') { body = monthHtml(); }
      else if (view === 'week') { body = '<div class="wc-agenda">' + weekHtml() + '</div>'; }
      else { body = '<div class="wc-agenda">' + listHtml() + '</div>'; }

      shell.innerHTML = '<div class="wc-head"><div>' + (cfg.eyebrow ? '<div class="wc-eyebrow">' + esc(cfg.eyebrow) + '</div>' : '') + '<h2 class="wc-title">' + esc(title()) + '</h2></div>' +
        '<div class="wc-controls">' + viewBtns + tzGroup + '<div class="wc-nav"><button type="button" class="wc-navbtn" data-nav="-1" aria-label="Previous">&#8249;</button><button type="button" class="wc-today">Today</button><button type="button" class="wc-navbtn" data-nav="1" aria-label="Next">&#8250;</button></div></div></div>' +
        '<p class="wc-tznote">Times shown in ' + esc(tzLabel(viewTz(), true)) + '.</p>' + legend + '<div class="wc-card">' + body + '</div>';
    }
    function dowRow() {
      var h = '<div class="wc-dow">';
      for (var i = 0; i < 7; i++) { h += '<div>' + DOWS[(cfg.weekStart + i) % 7] + '</div>'; }
      return h + '</div>';
    }
    function monthHtml() {
      var first = new Date(cur.y, cur.m, 1).getDay();
      var lead = (first - cfg.weekStart + 7) % 7;
      var days = new Date(cur.y, cur.m + 1, 0).getDate();
      var h = dowRow() + '<div class="wc-grid">';
      for (var i = 0; i < lead; i++) { h += '<div class="wc-day empty"></div>'; }
      for (var d = 1; d <= days; d++) {
        var evs = eventsOn(cur.y, cur.m + 1, d);
        var shown = evs.slice(0, cfg.chips);
        h += '<div class="wc-day' + (isToday(cur.y, cur.m + 1, d) ? ' today' : '') + '" data-d="' + d + '" tabindex="0" role="button" aria-label="' + MONTHS[cur.m] + ' ' + d + ', ' + evs.length + ' events"><span class="wc-num">' + d + '</span>';
        if (evs.length) {
          h += '<div class="wc-evs">' + shown.map(function (e) {
            return '<div class="wc-ev"' + dc(e.cat) + '><span class="dot"></span><span class="t">' + esc(e.title) + '</span></div>';
          }).join('') + (evs.length > shown.length ? '<div class="wc-more">+' + (evs.length - shown.length) + ' more</div>' : '') + '</div>';
        }
        h += '</div>';
      }
      return h + '</div>';
    }
    function weekHtml() {
      var h = '';
      for (var i = 0; i < 7; i++) {
        var dt = new Date(weekStart); dt.setDate(dt.getDate() + i);
        h += rowHtml(dt, eventsOn(dt.getFullYear(), dt.getMonth() + 1, dt.getDate()));
      }
      return h;
    }
    function listHtml() {
      var days = new Date(cur.y, cur.m + 1, 0).getDate(), h = '';
      for (var d = 1; d <= days; d++) {
        var evs = eventsOn(cur.y, cur.m + 1, d);
        if (evs.length) { h += rowHtml(new Date(cur.y, cur.m, d), evs); }
      }
      return h || '<p class="wc-none" style="padding:6px 2px">No events this month.</p>';
    }

    // ---- interaction ----
    function onClick(e) {
      var t = e.target;
      var nav = t.closest('[data-nav]');
      if (nav) {
        var s = +nav.getAttribute('data-nav');
        if (view === 'week') { weekStart.setDate(weekStart.getDate() + 7 * s); }
        else { cur.m += s; if (cur.m < 0) { cur.m = 11; cur.y--; } if (cur.m > 11) { cur.m = 0; cur.y++; } }
        return load();
      }
      if (t.closest('.wc-today')) {
        var n = todayParts(); cur = { y: n.y, m: n.m - 1 };
        weekStart = new Date(n.y, n.m - 1, n.d);
        weekStart.setDate(weekStart.getDate() - ((weekStart.getDay() - cfg.weekStart + 7) % 7));
        return load().then(function () { if (window.innerWidth <= 480) { openModal(n.y, n.m, n.d, eventsOn(n.y, n.m, n.d), null); } });
      }
      var vb = t.closest('[data-view]');
      if (vb) {
        view = vb.getAttribute('data-view');
        if (view === 'week') { // keep the week inside the month being looked at
          var tp = todayParts();
          if (!(tp.y === cur.y && tp.m - 1 === cur.m)) { weekStart = new Date(cur.y, cur.m, 1); weekStart.setDate(weekStart.getDate() - ((weekStart.getDay() - cfg.weekStart + 7) % 7)); }
        }
        return load();
      }
      var cb = t.closest('.wc-chip');
      if (cb) { activeCat = cb.getAttribute('data-filter') || 'all'; return draw(); }
      var tz = t.closest('[data-tz]');
      if (tz) {
        tzMode = tz.getAttribute('data-tz');
        try { localStorage.setItem(TZ_KEY, tzMode); } catch (err) {}
        convert(); return draw();
      }
      if (t.closest('[data-retry]')) { return load(); }
      var day = t.closest('.wc-day[data-d]');
      if (day) { var d = +day.getAttribute('data-d'); openModal(cur.y, cur.m + 1, d, eventsOn(cur.y, cur.m + 1, d), day); }
    }

    build();
    load();
  }

  function init(scope) {
    (scope || document).querySelectorAll('.wpaical-cal[data-config]').forEach(function (r) {
      if (r.__wpaical) { return; } // already running (page builders may call this again)
      r.__wpaical = true;
      try { new Calendar(r); } catch (e) { if (window.console) { console.error('AI Calendar:', e); } }
    });
  }
  window.WPAICalInit = window.WPAICalInit || {};
  window.WPAICalInit.calendar = init;
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { init(); }); } else { init(); }
})();
