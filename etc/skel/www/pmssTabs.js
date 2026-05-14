/* Minimal jQuery tabs helper for the local GUI fallback. */
(function ($) {
    'use strict';

    if (typeof $ === 'undefined' || typeof $.fn.tabs === 'function') {
        return;
    }

    /* Emulate the tiny subset of jquery.tabs used by index.php. */
    $.fn.tabs = function (options) {
        var settings = $.extend({ onShow: null }, options || {});

        return this.each(function () {
            var container = $(this);
            container.find('> ul').addClass('tabs-nav');
            var panes = container.find('> #content > .tabs-container');
            var tabLinks = container.find('> ul a[href^="#"]');

            function showTab(hash) {
                panes.hide();
                tabLinks.parent().removeClass('tabs-selected');
                container.find('> ul a[href="' + hash + '"]').parent().addClass('tabs-selected');
                $(hash).show();

                if (typeof settings.onShow === 'function') {
                    settings.onShow();
                }
            }

            tabLinks.on('click', function (event) {
                if (this.hash === '') {
                    return;
                }

                event.preventDefault();
                showTab(this.hash);
            });

            panes.hide();
            if (tabLinks.length > 0) {
                showTab(tabLinks.first()[0].hash);
            }
        });
    };
}(window.jQuery));
