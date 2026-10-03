/**
 * @file
 * Encart d'accompagnement : bouton « Masquer » mémorisé 30 jours.
 */
(function (Drupal, once) {
  'use strict';
  var KEY = 'websource_preorder_support_hidden';
  var TTL = 30 * 24 * 3600 * 1000;
  Drupal.behaviors.wspoSupport = {
    attach: function (context) {
      once('wspo-support', '[data-wspo-support]', context).forEach(function (el) {
        try {
          var t = parseInt(window.localStorage.getItem(KEY), 10);
          if (t && Date.now() - t < TTL) { el.hidden = true; return; }
        } catch (e) { /* stockage indisponible */ }
        var btn = el.querySelector('.wspo-support__hide');
        if (btn) {
          btn.addEventListener('click', function () {
            el.hidden = true;
            try { window.localStorage.setItem(KEY, String(Date.now())); } catch (e) { /* ignoré */ }
          });
        }
      });
    }
  };
})(Drupal, once);
