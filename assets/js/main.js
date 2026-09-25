/* =========================================================
   main.js — поведение лендинга (общий для вариантов A, C и G)
   1. Мобильное меню
   2. Кнопки «Записаться на модуль N» подставляют модуль в форму
   3. Сбор UTM-меток, yclid и ClientID Метрики → скрытые поля формы
   4. Отправка формы на /api/lead.php + цели Метрики
   ========================================================= */
(function () {
  'use strict';

  var CFG = window.LANDING_CONFIG || {};
  var YM_ID = Number(CFG.metrikaId) || 0;

  /* ---------- Метрика: безопасный вызов цели ---------- */
  function goal(name, params) {
    try {
      if (YM_ID && typeof window.ym === 'function') window.ym(YM_ID, 'reachGoal', name, params || {});
    } catch (e) { /* метрика не должна ломать сайт */ }
  }

  /* ---------- 1. Мобильное меню ---------- */
  var menuBtn = document.querySelector('[data-menu-toggle]');
  var mobileNav = document.getElementById('mobile-nav');
  if (menuBtn && mobileNav) {
    menuBtn.addEventListener('click', function () {
      var open = mobileNav.classList.toggle('is-open');
      menuBtn.setAttribute('aria-expanded', String(open));
    });
    mobileNav.addEventListener('click', function (e) {
      if (e.target.closest('a')) {
        mobileNav.classList.remove('is-open');
        menuBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ---------- 2. Предзаполнение модуля ---------- */
  var form = document.getElementById('lead-form');
  var moduleSelect = form ? form.querySelector('[name="module"]') : null;

  document.addEventListener('click', function (e) {
    var cta = e.target.closest('[data-cta]');
    if (!cta) return;
    var mod = cta.getAttribute('data-module');
    if (mod && moduleSelect) moduleSelect.value = mod;
    var payer = cta.getAttribute('data-payer');
    if (payer && form) {
      var radio = form.querySelector('[name="payer"][value="' + payer + '"]');
      if (radio) radio.checked = true;
    }
    goal('cta_click', { place: cta.getAttribute('data-cta'), module: mod || '' });
  });

  document.querySelectorAll('[data-goal-open]').forEach(function (el) {
    el.addEventListener('toggle', function () {
      if (el.open) goal('program_open', { module: el.getAttribute('data-goal-open') });
    });
  });
  document.querySelectorAll('a[href^="tel:"]').forEach(function (a) {
    a.addEventListener('click', function () { goal('phone_click'); });
  });
  document.querySelectorAll('[data-messenger]').forEach(function (a) {
    a.addEventListener('click', function () { goal('messenger_click', { to: a.getAttribute('data-messenger') }); });
  });

  /* ---------- 3. UTM-метки ----------
     Сохраняем метки первого визита (first touch) на 30 дней,
     чтобы заявка была привязана к объявлению, даже если человек
     вернулся на сайт позже напрямую. */
  var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid'];
  var STORE_KEY = 'landing_utm_v1';
  var TTL = 30 * 24 * 60 * 60 * 1000;

  function readStored() {
    try {
      var raw = localStorage.getItem(STORE_KEY);
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data.ts || Date.now() - data.ts > TTL) return null;
      return data.values;
    } catch (e) { return null; }
  }
  function store(values) {
    try { localStorage.setItem(STORE_KEY, JSON.stringify({ ts: Date.now(), values: values })); } catch (e) {}
  }

  var params = new URLSearchParams(window.location.search);
  var fromUrl = {};
  var hasUtm = false;
  UTM_KEYS.forEach(function (k) {
    var v = params.get(k);
    if (v) { fromUrl[k] = v.slice(0, 200); hasUtm = true; }
  });
  var utm = hasUtm ? fromUrl : (readStored() || {});
  if (hasUtm) store(fromUrl);

  function fillHidden() {
    if (!form) return;
    UTM_KEYS.forEach(function (k) {
      var input = form.querySelector('[name="' + k + '"]');
      if (input) input.value = utm[k] || '';
    });
    var set = function (name, value) {
      var el = form.querySelector('[name="' + name + '"]');
      if (el) el.value = value;
    };
    set('page_url', window.location.href.slice(0, 500));
    set('referrer', (document.referrer || '').slice(0, 500));
    if (YM_ID && typeof window.ym === 'function') {
      try { window.ym(YM_ID, 'getClientID', function (id) { set('ym_client_id', id || ''); }); } catch (e) {}
    }
  }
  fillHidden();

  /* ---------- 4. Отправка формы ---------- */
  if (!form) return;
  var statusBox = form.querySelector('.form__status');
  var submitBtn = form.querySelector('[type="submit"]');

  function setError(name, message) {
    var input = form.querySelector('[name="' + name + '"]');
    var box = form.querySelector('[data-error-for="' + name + '"]');
    if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
    if (box) box.textContent = message || '';
  }

  function normalizePhone(v) {
    var d = (v || '').replace(/\D/g, '');
    if (d.length === 11 && (d[0] === '8' || d[0] === '7')) d = '7' + d.slice(1);
    else if (d.length === 10) d = '7' + d;
    return d;
  }

  function validate() {
    var ok = true;
    var name = form.elements['name'].value.trim();
    var phone = normalizePhone(form.elements['phone'].value);
    setError('name', ''); setError('phone', ''); setError('consent', '');
    if (name.length < 2) { setError('name', 'Укажите фамилию и имя — они нужны для пропуска в здание.'); ok = false; }
    if (phone.length !== 11) { setError('phone', 'Проверьте номер: нужно 10 цифр после +7.'); ok = false; }
    if (!form.elements['consent'].checked) { setError('consent', 'Отметьте согласие, иначе мы не сможем обработать заявку.'); ok = false; }
    return ok;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    statusBox.textContent = '';
    statusBox.className = 'form__status';
    if (!validate()) {
      var firstBad = form.querySelector('[aria-invalid="true"]');
      if (firstBad) firstBad.focus();
      return;
    }

    var payload = {};
    new FormData(form).forEach(function (v, k) { payload[k] = typeof v === 'string' ? v.trim() : v; });
    payload.phone = '+' + normalizePhone(payload.phone);
    payload.consent = form.elements['consent'].checked ? 'yes' : 'no';

    submitBtn.disabled = true;
    var label = submitBtn.textContent;
    submitBtn.textContent = 'Отправляем…';

    fetch(form.getAttribute('action'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (res) {
        if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'send_failed');
        statusBox.className = 'form__status form__status--ok';
        statusBox.textContent = 'Заявка отправлена. Преподаватель перезвонит вам ' + (CFG.callbackText || 'в ближайшее рабочее время') + '.';
        goal('lead', { module: payload.module, payer: payload.payer });
        form.reset();
        fillHidden();
      })
      .catch(function () {
        statusBox.className = 'form__status form__status--error';
        statusBox.textContent = 'Заявка не отправилась. Попробуйте ещё раз или напишите на ' + (CFG.fallbackEmail || 'почту') + '.';
        goal('lead_error');
      })
      .finally(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = label;
      });
  });
})();
