/* WP AI Calendar [wpaical_upcoming]: show card times in the visitor's timezone, with a site-time toggle. */
(function () {
  'use strict';
  var KEY = 'wpaicalTz';
  var viewer = Intl.DateTimeFormat().resolvedOptions().timeZone;

  function meridiem(s) { return s.replace(/[\s ]*(AM|PM)$/i, ''); }
  function fmt(d, tz, opts) {
    opts.timeZone = tz;
    return new Intl.DateTimeFormat('en-US', opts).format(d).replace(/ /g, ' ');
  }
  function abbr(d, tz) {
    var p = new Intl.DateTimeFormat('en-US', { timeZone: tz, timeZoneName: 'short' }).formatToParts(d);
    var n = p.filter(function (x) { return x.type === 'timeZoneName'; })[0];
    return n ? n.value : tz;
  }

  function initCards(scope) {
  (scope || document).querySelectorAll('.wpaical-up').forEach(function (root) {
    if (root.__wpaical) { return; }
    root.__wpaical = true;
    var site = root.getAttribute('data-tz');
    var cards = root.querySelectorAll('.wpaical-card[data-start]'); // date-less cards have no data-start and are left alone
    try { new Intl.DateTimeFormat('en-US', { timeZone: site }); } catch (e) { return; }
    if (!site || !cards.length || viewer === site) { return; } // same zone: nothing to convert

    var mode = 'viewer';
    try { if (localStorage.getItem(KEY) === 'site') { mode = 'site'; } } catch (e) {}

    var bar = document.createElement('div');
    bar.className = 'wpaical-tz';
    bar.innerHTML = '<span>Show times in:</span><button type="button" data-m="viewer"></button><button type="button" data-m="site"></button>';
    var grid = root.querySelector('.wpaical-up-grid');
    grid.parentNode.insertBefore(bar, grid);

    function apply() {
      var tz = mode === 'site' ? site : viewer;
      var first = new Date(cards[0].getAttribute('data-start'));
      bar.querySelector('[data-m=viewer]').textContent = 'Your time (' + abbr(first, viewer) + ')';
      bar.querySelector('[data-m=site]').textContent = 'Event time (' + abbr(first, site) + ')';
      bar.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-m') === mode ? 'true' : 'false'); });
      cards.forEach(function (c) {
        var s = new Date(c.getAttribute('data-start'));
        var e = new Date(c.getAttribute('data-end'));
        var dtEl = c.querySelector('.wpaical-dt');
        if (!dtEl || c.getAttribute('data-allday')) { return; } // all-day events keep their own date
        var day = fmt(s, tz, { weekday: 'short', month: 'short', day: 'numeric' });
        var a = fmt(s, tz, { hour: 'numeric', minute: '2-digit' });
        var b = fmt(e, tz, { hour: 'numeric', minute: '2-digit' });
        var same = a.slice(-2) === b.slice(-2);
        dtEl.textContent = day + ' \u00b7 ' + (same ? meridiem(a) : a) + '\u2013' + b + ' ' + abbr(s, tz);
      });
    }

    bar.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-m]');
      if (!b) { return; }
      mode = b.getAttribute('data-m');
      try { localStorage.setItem(KEY, mode); } catch (e) {}
      apply();
    });
    apply();
  });
  }
  window.WPAICalInit = window.WPAICalInit || {};
  window.WPAICalInit.cards = initCards;
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { initCards(); }); } else { initCards(); }
})();
