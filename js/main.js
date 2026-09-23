/* MosSwim — интерфейс: меню, шапка, табы, галереи, анимации, Метрика */
(function () {
  'use strict';

  var cfg = window.SITE_CONFIG || {};
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- Яндекс.Метрика ---------- */
  var metrikaId = /^\d+$/.test(cfg.METRIKA_ID || '') ? Number(cfg.METRIKA_ID) : null;

  if (metrikaId) {
    (function (m, e, t, r, i, k, a) {
      m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
      m[i].l = 1 * new Date();
      k = e.createElement(t); a = e.getElementsByTagName(t)[0];
      k.async = 1; k.src = r; a.parentNode.insertBefore(k, a);
    })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');
    window.ym(metrikaId, 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: true });
  }

  /* Цели: click_signup, form_submit, click_phone, click_messenger */
  window.reachGoal = function (goal, params) {
    if (metrikaId && typeof window.ym === 'function') window.ym(metrikaId, 'reachGoal', goal, params);
  };
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-goal]');
    if (el) window.reachGoal(el.getAttribute('data-goal'));
  });

  /* ---------- Шапка и бургер ---------- */
  var header = document.querySelector('.header');
  var burger = document.querySelector('.burger');
  var nav = document.getElementById('nav');

  function setMenu(open) {
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
    nav.classList.toggle('is-open', open);
    document.body.classList.toggle('nav-open', open);
  }
  burger.addEventListener('click', function () {
    setMenu(burger.getAttribute('aria-expanded') !== 'true');
  });
  nav.addEventListener('click', function (e) {
    if (e.target.closest('a')) setMenu(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && nav.classList.contains('is-open')) { setMenu(false); burger.focus(); }
  });
  window.matchMedia('(min-width: 1024px)').addEventListener('change', function () { setMenu(false); });

  var stickyCta = document.querySelector('.sticky-cta');
  var hero = document.querySelector('.hero');
  var signup = document.getElementById('signup');
  function onScroll() {
    var y = window.scrollY;
    header.classList.toggle('is-scrolled', y > 8);
    if (stickyCta) {
      var pastHero = y > hero.offsetHeight * 0.8;
      var r = signup.getBoundingClientRect();
      var atForm = r.top < window.innerHeight && r.bottom > 0;
      stickyCta.classList.toggle('is-visible', pastHero && !atForm);
    }
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- Табы программы ---------- */
  document.querySelectorAll('[data-tabs]').forEach(function (root) {
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
    function select(tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', String(on));
        t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
      });
      if (focus) tab.focus();
      tab.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
    }
    tabs.forEach(function (tab, i) {
      tab.addEventListener('click', function () { select(tab, false); });
      tab.addEventListener('keydown', function (e) {
        var n = null;
        if (e.key === 'ArrowRight') n = tabs[(i + 1) % tabs.length];
        if (e.key === 'ArrowLeft') n = tabs[(i - 1 + tabs.length) % tabs.length];
        if (e.key === 'Home') n = tabs[0];
        if (e.key === 'End') n = tabs[tabs.length - 1];
        if (n) { e.preventDefault(); select(n, true); }
      });
    });
    // Инициализация без прокрутки страницы
    tabs.forEach(function (t, i) {
      t.tabIndex = i === 0 ? 0 : -1;
      document.getElementById(t.getAttribute('aria-controls')).hidden = i !== 0;
    });
  });

  /* ---------- Галереи: «показать все» + лайтбокс ---------- */
  document.querySelectorAll('[data-gallery-more]').forEach(function (btn) {
    var gallery = document.querySelector('[data-gallery="' + btn.getAttribute('data-gallery-more') + '"]');
    var label = btn.textContent;
    btn.addEventListener('click', function () {
      var open = gallery.classList.toggle('is-expanded');
      btn.setAttribute('aria-expanded', String(open));
      btn.textContent = open ? 'Свернуть' : label;
      if (!open) gallery.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth' });
    });
  });

  var lb = document.querySelector('.lightbox');
  if (lb && typeof lb.showModal === 'function') {
    var lbImg = lb.querySelector('img');
    var lbSrc = lb.querySelector('source');
    var lbCap = lb.querySelector('figcaption');
    var items = [], index = 0, lastFocus = null;

    function show(i) {
      index = (i + items.length) % items.length;
      var a = items[index];
      var alt = a.querySelector('img').alt;
      lbSrc.srcset = a.getAttribute('data-webp') || '';
      lbImg.src = a.href;
      lbImg.alt = alt;
      lbCap.textContent = alt + ' · ' + (index + 1) + ' из ' + items.length;
    }

    document.querySelectorAll('[data-gallery]').forEach(function (g) {
      g.addEventListener('click', function (e) {
        var a = e.target.closest('a');
        if (!a) return;
        e.preventDefault();
        items = Array.prototype.slice.call(g.querySelectorAll('a'));
        lastFocus = a;
        show(items.indexOf(a));
        lb.showModal();
      });
    });

    lb.querySelector('.lightbox__close').addEventListener('click', function () { lb.close(); });
    lb.querySelector('.lightbox__nav--prev').addEventListener('click', function () { show(index - 1); });
    lb.querySelector('.lightbox__nav--next').addEventListener('click', function () { show(index + 1); });
    lb.addEventListener('click', function (e) { if (e.target === lb) lb.close(); });
    lb.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') show(index + 1);
      if (e.key === 'ArrowLeft') show(index - 1);
    });
    lb.addEventListener('close', function () { if (lastFocus) lastFocus.focus(); });

    // свайп
    var x0 = null;
    lb.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    lb.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 50) show(index + (dx < 0 ? 1 : -1));
      x0 = null;
    });
  }

  /* ---------- Появление при скролле ---------- */
  var reveals = document.querySelectorAll('.reveal');
  if (reduceMotion || !('IntersectionObserver' in window)) {
    reveals.forEach(function (el) { el.classList.add('is-visible'); });
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add('is-visible'); io.unobserve(en.target); }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    reveals.forEach(function (el, i) {
      el.style.transitionDelay = (i % 4) * 70 + 'ms';
      io.observe(el);
    });
  }
})();
