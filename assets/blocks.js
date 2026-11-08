/* AI Calendar blocks for the WordPress block editor (no build step). */
(function (wp) {
  'use strict';
  var el = wp.element.createElement;
  var C = wp.components, BE = wp.blockEditor || wp.editor;
  var SSR = wp.serverSideRender;

  function text(label, props, key, help) {
    return el(C.TextControl, { label: label, help: help, value: props.attributes[key] || '', onChange: function (v) { var o = {}; o[key] = v; props.setAttributes(o); } });
  }
  function select(label, props, key, options) {
    return el(C.SelectControl, { label: label, value: props.attributes[key], options: options, onChange: function (v) { var o = {}; o[key] = v; props.setAttributes(o); } });
  }
  function num(label, props, key, min, max) {
    return el(C.RangeControl, { label: label, value: props.attributes[key], min: min, max: max, onChange: function (v) { var o = {}; o[key] = v; props.setAttributes(o); } });
  }
  function toggle(label, props, key) {
    return el(C.ToggleControl, { label: label, checked: !!props.attributes[key], onChange: function (v) { var o = {}; o[key] = v; props.setAttributes(o); } });
  }
  function edit(block, panels) {
    return function (props) {
      return el('div', (BE.useBlockProps ? BE.useBlockProps() : {}),
        el(BE.InspectorControls, null, panels(props)),
        el(SSR, { block: block, attributes: props.attributes }));
    };
  }

  wp.blocks.registerBlockType('wpaical/upcoming', {
    title: 'AI Event Cards', icon: 'screenoptions', category: 'widgets',
    description: 'Cards for upcoming events from your calendar, optionally for one program.',
    keywords: ['events', 'calendar', 'cards'],
    edit: edit('wpaical/upcoming', function (p) {
      return el(C.PanelBody, { title: 'Events', initialOpen: true },
        text('Program', p, 'program', 'Events whose title starts with this. Empty = all events. Comma separated for several.'),
        select('Match title', p, 'match', [{ label: 'Starts with', value: 'prefix' }, { label: 'Exactly', value: 'exact' }, { label: 'Contains', value: 'contains' }]),
        text('Only category', p, 'category'),
        num('Maximum cards', p, 'limit', 1, 50),
        num('Look ahead (days)', p, 'days', 7, 730),
        select('Recurring events', p, 'series', [{ label: 'Every date', value: 'all' }, { label: 'Only the next date of each series', value: 'next' }]),
        text('Heading', p, 'heading'), text('Sub-heading', p, 'lead'), text('Button text', p, 'button'), text('When there are no events', p, 'empty'));
    }),
    save: function () { return null; }
  });

  wp.blocks.registerBlockType('wpaical/calendar', {
    title: 'AI Calendar', icon: 'calendar-alt', category: 'widgets',
    description: 'A month, week and list calendar of your events. The interactive calendar appears on the live page; this preview shows the upcoming list.',
    keywords: ['events', 'calendar', 'schedule'],
    edit: edit('wpaical/calendar', function (p) {
      return el(C.PanelBody, { title: 'Calendar', initialOpen: true },
        text('Views', p, 'views', 'Any of: month, week, list (comma separated).'),
        select('Start on', p, 'default_view', [{ label: 'Month', value: 'month' }, { label: 'Week', value: 'week' }, { label: 'List', value: 'list' }]),
        text('Only program', p, 'program'), text('Only categories', p, 'categories'),
        text('Rename categories', p, 'labels', 'Like: Day-long=Day-long / Retreat'),
        text('Small label above the title', p, 'eyebrow'), text('Title', p, 'title', 'Empty shows the month name.'),
        select('Week starts on', p, 'week_start', [{ label: 'WordPress setting', value: '' }, { label: 'Sunday', value: '0' }, { label: 'Monday', value: '1' }, { label: 'Saturday', value: '6' }]),
        num('Events shown per day', p, 'chips', 1, 6),
        toggle('Category filter chips', p, 'legend'), toggle('Your time / event time switch', p, 'timezone_toggle'), toggle('Open event links in a new tab', p, 'new_tab'));
    }),
    save: function () { return null; }
  });
})(window.wp);
