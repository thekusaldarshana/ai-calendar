/* AI Calendar: Program cards editor (inline edit rows, media picker, shortcode box). */
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    var edit = e.target.closest('.wpaical-edit-ev');
    if (edit) {
      e.preventDefault();
      var row = edit.closest('tr');
      var panel = row.nextElementSibling;
      if (panel && panel.classList.contains('wpaical-evedit')) {
        panel.hidden = !panel.hidden;
      }
      return;
    }
    var cancel = e.target.closest('.wpaical-cancel');
    if (cancel) {
      e.preventDefault();
      var tr = cancel.closest('tr.wpaical-evedit');
      if (tr) { tr.hidden = true; }
      return;
    }
    var clear = e.target.closest('.wpaical-clear');
    if (clear) {
      e.preventDefault();
      var f = clear.closest('.wpaical-imgfield');
      f.querySelector('.wpaical-imgurl').value = '';
      var prev = f.querySelector('.wpaical-imgprev');
      prev.hidden = true;
      prev.removeAttribute('src');
      return;
    }
    var pick = e.target.closest('.wpaical-pick');
    if (pick) {
      e.preventDefault();
      if (!window.wp || !wp.media) { return; }
      var field = pick.closest('.wpaical-imgfield');
      var frame = wp.media({ title: 'Choose a picture', button: { text: 'Use this picture' }, library: { type: 'image' }, multiple: false });
      frame.on('select', function () {
        var a = frame.state().get('selection').first().toJSON();
        var url = (a.sizes && a.sizes.large && a.sizes.large.url) || a.url;
        field.querySelector('.wpaical-imgurl').value = url;
        var img = field.querySelector('.wpaical-imgprev');
        img.src = url;
        img.hidden = false;
      });
      frame.open();
    }
  });

  document.addEventListener('input', function (e) {
    if (!e.target.classList || !e.target.classList.contains('wpaical-imgurl')) { return; }
    var img = e.target.closest('.wpaical-imgfield').querySelector('.wpaical-imgprev');
    if (e.target.value) { img.src = e.target.value; img.hidden = false; } else { img.hidden = true; }
  });

  document.querySelectorAll('.wpaical-sc').forEach(function (i) {
    i.addEventListener('focus', function () { i.select(); });
  });
})();
