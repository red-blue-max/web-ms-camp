/* MosSwim — анимации-акценты: снег, пловец в программе, счётчики, пауза вне экрана.
   Всё выключается, если в системе включено «уменьшить движение». */
(function () {
  'use strict';

  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasIO = 'IntersectionObserver' in window;

  /* ---------- Пауза CSS-анимаций вне экрана ---------- */
  if (hasIO) {
    var pauseIO = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { e.target.classList.toggle('is-offscreen', !e.isIntersecting); });
    }, { rootMargin: '100px 0px' });
    document.querySelectorAll('.lane, .hero, .days, .price__card, .signup, .btn--shine').forEach(function (el) {
      pauseIO.observe(el);
    });
  }

  /* ---------- Лыжня: прорисовка при появлении ---------- */
  document.querySelectorAll('.ski-track').forEach(function (el) {
    if (reduce || !hasIO) { el.classList.add('is-visible'); return; }
    var io = new IntersectionObserver(function (entries) {
      if (entries[0].isIntersecting) { el.classList.add('is-visible'); io.disconnect(); }
    }, { threshold: 0.2 });
    io.observe(el);
  });

  /* ---------- Счётчики 0 → N ---------- */
  var counters = document.querySelectorAll('[data-count]');
  if (!reduce && hasIO) {
    var countIO = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        countIO.unobserve(e.target);
        var el = e.target, target = +el.getAttribute('data-count'), t0 = null, dur = 900 + target * 60;
        function step(t) {
          if (!t0) t0 = t;
          var p = Math.min(1, (t - t0) / dur);
          el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3)));
          if (p < 1) requestAnimationFrame(step);
        }
        el.textContent = '0';
        requestAnimationFrame(step);
      });
    }, { threshold: 0.6 });
    counters.forEach(function (el) { countIO.observe(el); });
  }

  /* ---------- Пловец под табами программы ---------- */
  var tabs = document.querySelector('.days__tabs');
  var swimmer = tabs && tabs.querySelector('.days__swimmer');
  var laneEl = tabs && tabs.querySelector('.days__lane');
  if (swimmer) {
    var lastX = null;
    var moveSwimmer = function () {
      var active = tabs.querySelector('[aria-selected="true"]');
      if (!active) return;
      laneEl.style.width = tabs.scrollWidth + 'px';
      var x = active.offsetLeft + active.offsetWidth / 2 - swimmer.offsetWidth / 2;
      if (lastX !== null && x !== lastX) swimmer.classList.toggle('is-left', x < lastX);
      swimmer.style.transform = 'translateX(' + x + 'px)';
      lastX = x;
    };
    // Табы переключает main.js — следим за aria-selected
    new MutationObserver(moveSwimmer).observe(tabs, { attributes: true, subtree: true, attributeFilter: ['aria-selected'] });
    window.addEventListener('resize', moveSwimmer);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(moveSwimmer);
    moveSwimmer();
  }

  /* ---------- Снег в hero ---------- */
  var canvas = document.querySelector('.hero__snow');
  if (canvas && !reduce && canvas.getContext) {
    var ctx = canvas.getContext('2d');
    var flakes = [], w = 0, h = 0, running = false, raf = 0, last = 0;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);

    var resize = function () {
      w = canvas.clientWidth; h = canvas.clientHeight;
      canvas.width = Math.round(w * dpr); canvas.height = Math.round(h * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      var n = Math.round(Math.min(90, w / 12));
      while (flakes.length < n) flakes.push(make(true));
      flakes.length = n;
    };
    var make = function (anyY) {
      var r = Math.random() * 2.4 + 0.8;
      return {
        x: Math.random() * w, y: anyY ? Math.random() * h : -5,
        r: r, vy: 14 + r * 12 + Math.random() * 10,   // px/сек
        sway: Math.random() * 2 * Math.PI, sw: 0.6 + Math.random() * 0.8,
        a: 0.35 + Math.random() * 0.45,
      };
    };
    var frame = function (t) {
      var dt = Math.min(0.05, (t - (last || t)) / 1000); last = t;
      ctx.clearRect(0, 0, w, h);
      for (var i = 0; i < flakes.length; i++) {
        var f = flakes[i];
        f.y += f.vy * dt;
        f.sway += f.sw * dt;
        f.x += Math.sin(f.sway) * 12 * dt;
        if (f.y > h + 5) { flakes[i] = make(false); continue; }
        ctx.globalAlpha = f.a;
        ctx.beginPath(); ctx.arc(f.x, f.y, f.r, 0, 6.2832); ctx.fillStyle = '#fff'; ctx.fill();
      }
      raf = requestAnimationFrame(frame);
    };
    var start = function () { if (!running) { running = true; last = 0; raf = requestAnimationFrame(frame); } };
    var stop = function () { running = false; cancelAnimationFrame(raf); };

    resize();
    window.addEventListener('resize', resize);
    if (hasIO) {
      new IntersectionObserver(function (e) { e[0].isIntersecting && !document.hidden ? start() : stop(); }).observe(canvas);
    } else { start(); }
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop();
      else if (canvas.getBoundingClientRect().bottom > 0) start();
    });
  }
})();
