/**
 * @file
 * Compte à rebours, bandeau défilant et anti-spam du formulaire d'alerte.
 */
(function (Drupal, once) {
  'use strict';

  function pad(n) { return n; }

  function render(el) {
    var target = parseInt(el.getAttribute('data-target'), 10);
    var mode = el.getAttribute('data-mode') || 'auto';
    var remaining = Math.max(0, target - Math.floor(Date.now() / 1000));
    if (remaining <= 0) {
      el.classList.add('is-done');
      el.textContent = el.getAttribute('data-done') || '';
      return false;
    }
    var d = Math.floor(remaining / 86400);
    var h = Math.floor((remaining % 86400) / 3600);
    var m = Math.floor((remaining % 3600) / 60);
    var s = remaining % 60;
    var show = {d: true, h: true, m: true, s: false};
    if (mode === 'd') { show = {d: true, h: false, m: false, s: false}; }
    else if (mode === 'dh') { show = {d: true, h: true, m: false, s: false}; }
    else if (mode === 'dhm') { show = {d: true, h: true, m: true, s: false}; }
    else if (mode === 'dhms') { show = {d: true, h: true, m: true, s: true}; }
    else {
      // Automatique : n'affiche que les unités pertinentes.
      show = {d: d > 0, h: d > 0 || h > 0, m: true, s: d === 0 && h === 0};
    }
    var vals = {d: d, h: h, m: m, s: s};
    el.querySelectorAll('[data-unit]').forEach(function (u) {
      var k = u.getAttribute('data-unit');
      u.hidden = !show[k];
      var num = u.querySelector('.wspo-countdown__num');
      if (num) { num.textContent = pad(vals[k]); }
    });
    return true;
  }

  Drupal.behaviors.wspoCountdown = {
    attach: function (context) {
      once('wspo-countdown', '[data-wspo-countdown]', context).forEach(function (el) {
        if (!render(el)) { return; }
        var t = setInterval(function () { if (!render(el)) { clearInterval(t); } }, 1000);
      });
    }
  };

  Drupal.behaviors.wspoTicker = {
    attach: function (context) {
      once('wspo-ticker', '[data-wspo-ticker]', context).forEach(function (el) {
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce) { return; }
        var speed = parseInt(el.getAttribute('data-speed'), 10) || 60;
        var list = el.querySelector('.wspo-ticker__list');
        var width = list ? list.getBoundingClientRect().width : 0;
        if (!width) { return; }
        el.style.setProperty('--wspo-ticker-duration', Math.max(5, width / speed) + 's');
        el.classList.add('is-animated');
      });
    }
  };

  Drupal.behaviors.wspoSubscribe = {
    attach: function (context) {
      once('wspo-subscribe', 'input.wspo-t', context).forEach(function (input) {
        input.value = String(Date.now());
      });
    }
  };
})(Drupal, once);
