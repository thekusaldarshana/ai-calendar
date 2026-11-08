/* WP AI Calendar: re-run the widgets' scripts when Elementor (re)renders them in the editor. */
(function ($) {
  'use strict';
  $(window).on('elementor/frontend/init', function () {
    if (!window.elementorFrontend || !window.elementorFrontend.hooks) { return; }
    window.elementorFrontend.hooks.addAction('frontend/element_ready/wpaical_calendar.default', function ($el) {
      if (window.WPAICalInit && window.WPAICalInit.calendar) { window.WPAICalInit.calendar($el[0]); }
    });
    window.elementorFrontend.hooks.addAction('frontend/element_ready/wpaical_upcoming.default', function ($el) {
      if (window.WPAICalInit && window.WPAICalInit.cards) { window.WPAICalInit.cards($el[0]); }
    });
  });
})(jQuery);
