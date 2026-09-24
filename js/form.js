/* MosSwim — форма заявки: маска телефона, валидация, honeypot, отправка в Telegram */
(function () {
  'use strict';

  var cfg = window.SITE_CONFIG || {};
  var form = document.getElementById('signup-form');
  if (!form) return;

  var statusEl = form.querySelector('.form__status');
  var submitBtn = form.querySelector('[type="submit"]');
  var phone = form.elements.phone;
  var startedAt = Date.now();

  /* ---------- Маска телефона +7 (XXX) XXX-XX-XX ---------- */
  function digits(v) {
    var d = v.replace(/\D/g, '');
    if (d[0] === '8' || d[0] === '7') d = d.slice(1);
    return d.slice(0, 10);
  }
  function formatPhone(d) {
    if (!d) return '';
    var out = '+7 (' + d.slice(0, 3);
    if (d.length >= 3) out += ') ' + d.slice(3, 6);
    if (d.length >= 6) out += '-' + d.slice(6, 8);
    if (d.length >= 8) out += '-' + d.slice(8, 10);
    return out;
  }
  phone.addEventListener('input', function () { phone.value = formatPhone(digits(phone.value)); });
  phone.addEventListener('focus', function () { if (!phone.value) phone.value = '+7 ('; });
  phone.addEventListener('blur', function () { if (digits(phone.value).length === 0) phone.value = ''; });

  /* ---------- Валидация ---------- */
  function setError(name, msg) {
    var input = form.elements[name];
    var el = input.length && !input.tagName ? input[0] : input;
    var wrap = el.closest('.field, .consent');
    var err = name === 'consent' ? form.querySelector('.field__error--consent') : wrap.querySelector('.field__error');
    wrap.classList.toggle('is-invalid', !!msg);
    err.textContent = msg || '';
    if (el.setAttribute) {
      if (msg) el.setAttribute('aria-invalid', 'true'); else el.removeAttribute('aria-invalid');
    }
  }

  function validate() {
    var f = form.elements, errors = {};
    var name = f.parent_name.value.trim();
    if (name.length < 2) errors.parent_name = 'Укажите ваше имя';
    if (digits(f.phone.value).length !== 10) errors.phone = 'Введите номер полностью: +7 (XXX) XXX-XX-XX';
    if (f.child_name.value.trim().length < 2) errors.child_name = 'Укажите имя ребёнка';
    var age = parseInt(f.child_age.value, 10);
    if (!(age >= 8 && age <= 18)) errors.child_age = 'Возраст участников — от 8 до 18 лет';
    if (!form.querySelector('[name="level"]:checked')) errors.level = 'Выберите уровень подготовки';
    if (!f.consent.checked) errors.consent = 'Нужно согласие на обработку персональных данных';

    ['parent_name', 'phone', 'child_name', 'child_age', 'level', 'consent'].forEach(function (k) {
      setError(k, errors[k]);
    });
    return errors;
  }

  // Сбрасываем ошибку поля, как только пользователь его исправил
  form.addEventListener('input', function (e) {
    var n = e.target.name;
    if (n && form.querySelector('[name="' + n + '"]').closest('.is-invalid')) setError(n, '');
  });
  form.addEventListener('change', function (e) {
    var n = e.target.name;
    if (n === 'level' || n === 'consent') setError(n, '');
  });

  function setStatus(type, html) {
    statusEl.className = 'form__status' + (type ? ' is-' + type : '');
    statusEl.innerHTML = html || '';
  }

  /* ---------- Отправка ---------- */
  function payload() {
    var f = form.elements;
    return {
      parent_name: f.parent_name.value.trim(),
      phone: formatPhone(digits(f.phone.value)),
      child_name: f.child_name.value.trim(),
      child_age: f.child_age.value.trim(),
      level: (form.querySelector('[name="level"]:checked') || {}).value || '',
      comment: f.comment.value.trim(),
      consent: f.consent.checked,
      website: f.website.value,          // honeypot
      elapsed: Date.now() - startedAt,    // защита от мгновенной отправки ботом
      page: location.href.split('#')[0],
    };
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }

  function sendProxy(data) {
    return fetch(cfg.PROXY_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok || !j.ok) throw new Error(j.error || 'HTTP ' + r.status);
      });
    });
  }

  /* ⚠️ MVP-режим: токен виден в браузере. См. js/config.js и README. */
  function sendDirect(data) {
    if (!cfg.DIRECT_BOT_TOKEN || !cfg.DIRECT_CHAT_ID) return Promise.reject(new Error('direct mode not configured'));
    var text = [
      '<b>🏊 Заявка: зимние сборы 2–10.01.2027</b>',
      '',
      '<b>Родитель:</b> ' + escapeHtml(data.parent_name),
      '<b>Телефон:</b> ' + escapeHtml(data.phone),
      '<b>Ребёнок:</b> ' + escapeHtml(data.child_name) + ', ' + escapeHtml(data.child_age) + ' лет',
      '<b>Уровень:</b> ' + escapeHtml(data.level),
      data.comment ? '<b>Комментарий:</b> ' + escapeHtml(data.comment) : '',
      '',
      '<i>Согласие на обработку ПДн: да</i>',
    ].filter(function (l, i, a) { return l !== '' || a[i - 1] !== ''; }).join('\n');
    return fetch('https://api.telegram.org/bot' + cfg.DIRECT_BOT_TOKEN + '/sendMessage', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ chat_id: cfg.DIRECT_CHAT_ID, text: text, parse_mode: 'HTML' }),
    }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    setStatus('', '');

    var errors = validate();
    var first = Object.keys(errors)[0];
    if (first) {
      var el = form.elements[first];
      (el.length && !el.tagName ? el[0] : el).focus();
      return;
    }

    var data = payload();

    // Honeypot / слишком быстрая отправка: делаем вид, что всё хорошо, но ничего не шлём
    if (data.website || data.elapsed < 2500) {
      setStatus('success', 'Спасибо! Заявка отправлена.');
      form.reset();
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Отправляем…';

    var send = cfg.FORM_MODE === 'direct' ? sendDirect : sendProxy;
    send(data).then(function () {
      window.reachGoal && window.reachGoal('form_submit');
      setStatus('success', 'Спасибо! Заявка отправлена. Тренер свяжется с вами по номеру ' + escapeHtml(data.phone) + '.');
      form.reset();
      startedAt = Date.now();
    }).catch(function (err) {
      if (window.console) console.error('Form send error:', err);
      setStatus('error', 'Не получилось отправить заявку. Позвоните нам: <a href="tel:+79661288229">+7 966 128-82-29</a> или напишите в <a href="https://t.me/Mosswim2025" target="_blank" rel="noopener">Telegram</a>.');
    }).then(function () {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Отправить заявку';
    });
  });
})();
