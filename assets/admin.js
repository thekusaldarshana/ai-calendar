/* WP AI Calendar admin: programs table, quick add, quick edit. */
(function () {
  'use strict';
  var NEW = '__new__';

  // "+ New category..." reveals its text box (programs table, quick add and quick edit all share this).
  document.addEventListener('change', function (e) {
    if (!e.target.classList || !e.target.classList.contains('wpaical-cat-select')) { return; }
    var holder = e.target.closest('tr, p, td, form');
    var neu = holder && holder.querySelector('.wpaical-cat-new');
    if (!neu) { return; }
    var show = e.target.value === NEW;
    neu.hidden = !show;
    if (show) { neu.focus(); }
  });

  // ---- Programs table
  var table = document.getElementById('wpaical-programs');
  if (table) {
    var body = table.querySelector('tbody');
    var tpl = document.getElementById('wpaical-row-template');
    var empty = function () {
      var msg = document.getElementById('wpaical-empty');
      if (msg) { msg.hidden = body.querySelectorAll('tr').length > 0; }
    };
    body.addEventListener('click', function (e) {
      var btn = e.target.closest('.wpaical-remove');
      if (!btn) { return; }
      e.preventDefault();
      btn.closest('tr').remove();
      empty();
    });
    document.getElementById('wpaical-add-row').addEventListener('click', function (e) {
      e.preventDefault();
      var node = tpl.content.firstElementChild.cloneNode(true);
      body.appendChild(node);
      empty();
      node.querySelector('.wpaical-title').focus();
    });
    empty();
  }

  // ---- Quick add: All day disables the time fields
  var allDay = document.getElementById('wpaical-qa-allday');
  if (allDay) {
    allDay.addEventListener('change', function () {
      document.querySelectorAll('.wpaical-qa-time').forEach(function (i) { i.disabled = allDay.checked; });
    });
  }

  // ---- Quick edit (one shared editing row, moved under the clicked event)
  var upcoming = document.getElementById('wpaical-upcoming');
  var qeTpl = document.getElementById('wpaical-qe-tpl');
  if (!upcoming || !qeTpl) { return; }

  function closeEditor() {
    var open = upcoming.querySelector('.wpaical-qe-row');
    if (open) {
      open.previousElementSibling.hidden = false;
      open.remove();
    }
  }

  upcoming.addEventListener('click', function (e) {
    if (e.target.closest('.wpaical-qe-cancel')) { e.preventDefault(); closeEditor(); return; }
    var btn = e.target.closest('.wpaical-qe-open');
    if (!btn) { return; }
    e.preventDefault();
    closeEditor();
    var row = btn.closest('tr');
    var d = row.dataset;
    var edit = qeTpl.content.firstElementChild.cloneNode(true);
    var f = function (n) { return edit.querySelector('[name="' + n + '"]'); };

    edit.querySelector('.wpaical-qe-title').textContent = d.title;
    f('event_id').value = d.id;
    f('date').value = d.date;
    f('start_time').value = d.start;
    f('end_time').value = d.end;
    var sel = f('category');
    if (d.cat) {
      var has = Array.prototype.some.call(sel.options, function (o) { return o.value === d.cat; });
      if (!has) { sel.insertBefore(new Option(d.cat, d.cat), sel.options[1]); }
      sel.value = d.cat;
    }
    if (d.allday) {
      ['date', 'start_time', 'end_time'].forEach(function (n) { f(n).disabled = true; });
      edit.querySelector('.wpaical-qe-note').hidden = false;
    }
    if (d.series) {
      var scope = edit.querySelector('.wpaical-qe-scope');
      scope.hidden = false;
      scope.addEventListener('change', function () {
        var whole = scope.querySelector('input[value="series"]').checked;
        if (!d.allday) { f('date').disabled = whole; } // a series keeps its own start date
      });
    }
    row.hidden = true;
    row.after(edit);
    sel.focus();
  });
})();
