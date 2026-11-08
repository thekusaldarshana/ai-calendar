/* WP AI Calendar admin: programs table (add/remove rows, "new category" reveal). */
(function () {
  'use strict';
  var table = document.getElementById('wpaical-programs');
  if (!table) { return; }
  var body = table.querySelector('tbody');
  var tpl = document.getElementById('wpaical-row-template');
  var NEW = '__new__';

  function syncRow(row) {
    var sel = row.querySelector('.wpaical-cat-select');
    var neu = row.querySelector('.wpaical-cat-new');
    var show = sel.value === NEW;
    neu.hidden = !show;
    if (show) { neu.focus(); }
  }

  body.addEventListener('change', function (e) {
    if (e.target.classList.contains('wpaical-cat-select')) { syncRow(e.target.closest('tr')); }
  });

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

  function empty() {
    var msg = document.getElementById('wpaical-empty');
    if (msg) { msg.hidden = body.querySelectorAll('tr').length > 0; }
  }
  empty();

})();
