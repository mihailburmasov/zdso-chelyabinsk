/* Поведение сайта: меню, поиск по номеру чертежа, фильтры каталога, галерея,
   формы, фильтр таблиц, копирование номера, карта по клику, cookie, цели Метрики.
   Без зависимостей. Всё деградирует: при выключенном JS страницы остаются читаемыми. */
(function () {
  'use strict';

  var CFG = window.ZDSO || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------------- цели Яндекс Метрики ---------------- */
  function goal(name, params) {
    if (!CFG.metrika || !window.ym) return;
    try { window.ym(Number(CFG.metrika), 'reachGoal', name, params || undefined); } catch (e) {}
  }
  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-goal]') : null;
    if (el) goal(el.getAttribute('data-goal'));
  }, true);

  /* ---------------- шапка: меню и поиск ---------------- */
  var mnav = $('[data-mnav]');
  function setMenu(open) {
    if (!mnav) return;
    mnav.hidden = !open;
    mnav.classList.toggle('is-open', open);
    document.documentElement.style.overflow = open ? 'hidden' : '';
    $$('[data-toggle-menu]').forEach(function (b) { b.setAttribute('aria-expanded', String(open)); });
  }
  $$('[data-toggle-menu]').forEach(function (b) { b.addEventListener('click', function () { setMenu(mnav.hidden); }); });
  $$('[data-close-menu]').forEach(function (b) { b.addEventListener('click', function () { setMenu(false); }); });

  $$('[data-toggle-search]').forEach(function (b) {
    b.addEventListener('click', function () {
      var wrap = $('[data-search-wrap]');
      if (!wrap) return;
      var open = !wrap.classList.contains('is-open');
      wrap.classList.toggle('is-open', open);
      b.setAttribute('aria-expanded', String(open));
      if (open) { var inp = $('[data-search-input]', wrap); if (inp) inp.focus(); }
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { setMenu(false); closeRes(); }
  });

  /* ---------------- поиск по номеру чертежа ---------------- */
  var LAT = { 'А': 'A', 'В': 'B', 'С': 'C', 'Е': 'E', 'Н': 'H', 'К': 'K', 'М': 'M', 'О': 'O', 'Р': 'P', 'Т': 'T', 'Х': 'X', 'У': 'Y', 'І': 'I' };
  function normDraw(s) {
    s = String(s || '').toUpperCase().replace(/[А-ЯІ]/g, function (c) { return LAT[c] || c; });
    return s.replace(/[^0-9A-Z]/g, '');
  }
  function normWords(s) { return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim(); }

  var index = null, indexLoading = null;
  function loadIndex() {
    if (index) return Promise.resolve(index);
    if (indexLoading) return indexLoading;
    indexLoading = fetch(CFG.searchIndex, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        index = (j.rows || []).map(function (r) {
          r._n = normWords(r.n);
          r._k = (r.k || '') + ' ' + normDraw(r.d || '') + ' ' + normDraw(r.al || '');
          r._m = normWords(r.m);
          return r;
        });
        return index;
      })
      .catch(function () { index = []; return index; });
    return indexLoading;
  }

  /** Ищем сначала по номеру (точное/по началу/подстрока), затем по словам. */
  function search(q, limit) {
    if (!index) return [];
    var nd = normDraw(q), nw = normWords(q);
    var words = nw.split(' ').filter(Boolean);
    var hits = [];
    for (var i = 0; i < index.length; i++) {
      var r = index[i], score = 0;
      if (nd.length >= 3) {
        var keys = r._k;
        if (keys) {
          var exact = (' ' + keys + ' ').indexOf(' ' + nd + ' ') >= 0;
          if (exact) score = 1000;
          else if (keys.indexOf(nd) === 0) score = 800;
          else if (keys.indexOf(' ' + nd) >= 0) score = 700;
          else if (keys.indexOf(nd) > 0) score = 500;
        }
      }
      if (!score && words.length) {
        var all = true, s = 0;
        for (var w = 0; w < words.length; w++) {
          var p = r._n.indexOf(words[w]);
          if (p < 0 && r._m.indexOf(words[w]) < 0) { all = false; break; }
          s += p === 0 ? 60 : 30;
        }
        if (all) score = s;
      }
      if (score) {
        if (r.t === 'm') score += 120;        // страница модели техники — выше
        else if (r.t === 's') score += 60;    // раздел каталога
        if (r.st === 'in_stock') score += 5;
        hits.push([score, r]);
      }
    }
    hits.sort(function (a, b) { return b[0] - a[0] || a[1].n.localeCompare(b[1].n, 'ru'); });
    return hits.slice(0, limit || 10).map(function (h) { return h[1]; });
  }

  var TYPE_LABEL = { i: '', s: 'Раздел каталога', m: 'Страница модели', v: 'Услуга', a: 'Статья' };
  function hitHtml(r, q) {
    var sub = [];
    if (r.d) sub.push('чертёж ' + r.d);
    if (r.m && r.t === 'i') sub.push(r.m);
    if (TYPE_LABEL[r.t]) sub.push(TYPE_LABEL[r.t]);
    else if (r.s) sub.push(r.s);
    if (r.p) sub.push(new Intl.NumberFormat('ru-RU').format(r.p) + ' ₽');
    return '<a class="search__hit" href="' + r.u + '" role="option"><b>' + esc(r.n) + '</b><span>' + esc(sub.join(' · ')) + '</span></a>';
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  var openResBox = null;
  function closeRes() { if (openResBox) { openResBox.innerHTML = ''; openResBox = null; } }
  document.addEventListener('click', function (e) {
    if (openResBox && !e.target.closest('[data-search]')) closeRes();
  });

  $$('[data-search]').forEach(function (box) {
    var input = $('[data-search-input]', box);
    var res = $('[data-search-res]', box);
    var go = $('[data-search-go]', box);
    if (!input || !res) return;
    var t = null, lastQ = '';

    function render() {
      var q = input.value.trim();
      if (q === lastQ) return;
      lastQ = q;
      if (q.length < 2) { res.innerHTML = ''; openResBox = null; return; }
      loadIndex().then(function () {
        if (input.value.trim() !== q) return;
        var hits = search(q, 10);
        res.innerHTML = hits.length
          ? hits.map(function (r) { return hitHtml(r, q); }).join('')
          : '<p class="search__empty">Ничего не нашлось. Попробуйте другой номер или <a href="' + (CFG.base || '') + '/contacts/">напишите нам</a> — подберём вручную.</p>';
        openResBox = res;
        if (normDraw(q).length >= 4) goal('search_drawing', { q: q });
      });
    }
    input.addEventListener('input', function () { clearTimeout(t); t = setTimeout(render, 130); });
    input.addEventListener('focus', loadIndex);
    input.addEventListener('keydown', function (e) {
      var items = $$('.search__hit', res);
      var cur = items.findIndex(function (x) { return x.classList.contains('is-cur'); });
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!items.length) return;
        var nx = e.key === 'ArrowDown' ? Math.min(items.length - 1, cur + 1) : Math.max(0, cur - 1);
        items.forEach(function (x) { x.classList.remove('is-cur'); });
        items[nx].classList.add('is-cur');
        items[nx].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter') {
        if (cur >= 0) { e.preventDefault(); window.location.href = items[cur].getAttribute('href'); }
        else if (input.value.trim()) { e.preventDefault(); window.location.href = (CFG.searchPage || '/search/') + '?q=' + encodeURIComponent(input.value.trim()); }
      }
    });
    if (go) go.addEventListener('click', function () {
      if (input.value.trim()) window.location.href = (CFG.searchPage || '/search/') + '?q=' + encodeURIComponent(input.value.trim());
    });
  });

  // страница /search/ — результаты сразу из ?q=
  var searchPageBox = $('[data-search-page]');
  if (searchPageBox) {
    var q = new URLSearchParams(location.search).get('q') || '';
    var pageInput = $('#page-search');
    if (pageInput && q) pageInput.value = q;
    if (q) {
      loadIndex().then(function () {
        var hits = search(q, 60);
        searchPageBox.innerHTML = hits.length
          ? '<h2>Найдено: ' + hits.length + '</h2><div class="search__res" style="position:static;box-shadow:none;max-height:none">' + hits.map(function (r) { return hitHtml(r, q); }).join('') + '</div>'
          : '<p class="empty">По запросу «' + esc(q) + '» ничего не нашлось. Проверьте номер или напишите нам — подберём вручную.</p>';
        goal('search_drawing', { q: q });
      });
    }
  }

  /* ---------------- фильтры каталога ---------------- */
  var itemsBox = $('[data-items]');
  if (itemsBox) {
    var allItems = $$('[data-item]', itemsBox);
    var countEl = $('[data-count]');
    var emptyEl = $('[data-empty]');
    var filtersBox = $('[data-filters]');

    function activeFilters() {
      var f = { model: [], unit: [], material: [], stock: [], hasprice: false, wmin: null, wmax: null, draw: '' };
      $$('[data-filter]', filtersBox).forEach(function (el) {
        var key = el.getAttribute('data-filter');
        if (el.type === 'checkbox') {
          if (!el.checked) return;
          if (key === 'hasprice') f.hasprice = true;
          else if (f[key]) f[key].push(el.value);
        } else if (key === 'wmin' || key === 'wmax') {
          f[key] = el.value === '' ? null : parseFloat(el.value.replace(',', '.'));
        } else if (key === 'draw') {
          f.draw = normDraw(el.value);
        }
      });
      return f;
    }

    function apply() {
      var f = activeFilters(), shown = 0;
      allItems.forEach(function (el) {
        var ok = true;
        if (f.model.length && f.model.indexOf(el.getAttribute('data-model')) < 0) ok = false;
        if (ok && f.unit.length && f.unit.indexOf(el.getAttribute('data-unit')) < 0) ok = false;
        if (ok && f.material.length && f.material.indexOf(el.getAttribute('data-material')) < 0) ok = false;
        if (ok && f.stock.length && f.stock.indexOf(el.getAttribute('data-stock')) < 0) ok = false;
        if (ok && f.hasprice && !el.getAttribute('data-price')) ok = false;
        if (ok && (f.wmin !== null || f.wmax !== null)) {
          var w = parseFloat(el.getAttribute('data-weight'));
          if (isNaN(w)) ok = false;
          else {
            if (f.wmin !== null && w < f.wmin) ok = false;
            if (f.wmax !== null && w > f.wmax) ok = false;
          }
        }
        if (ok && f.draw) ok = (el.getAttribute('data-keys') || '').indexOf(f.draw) >= 0;
        el.hidden = !ok;
        if (ok) shown++;
      });
      if (countEl) countEl.textContent = String(shown);
      if (emptyEl) emptyEl.hidden = shown > 0;
    }

    if (filtersBox) {
      filtersBox.addEventListener('change', apply);
      filtersBox.addEventListener('input', function (e) {
        if (e.target.matches('[data-filter="draw"],[data-filter="wmin"],[data-filter="wmax"]')) apply();
      });
      var reset = $('[data-filters-reset]');
      if (reset) reset.addEventListener('click', function () {
        $$('[data-filter]', filtersBox).forEach(function (el) {
          if (el.type === 'checkbox') el.checked = false; else el.value = '';
        });
        apply();
      });
      $$('[data-filters-open]').forEach(function (b) { b.addEventListener('click', function () { filtersBox.classList.add('is-open'); filtersBox.scrollIntoView({ block: 'start', behavior: 'smooth' }); }); });
      $$('[data-filters-close]').forEach(function (b) { b.addEventListener('click', function () { filtersBox.classList.remove('is-open'); }); });
    }

    var sortSel = $('[data-sort]');
    if (sortSel) sortSel.addEventListener('change', function () {
      var mode = sortSel.value;
      var sorted = allItems.slice().sort(function (a, b) {
        function num(el, attr) { var v = parseFloat(el.getAttribute(attr)); return isNaN(v) ? null : v; }
        if (mode === 'name') return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'), 'ru');
        if (mode === 'draw') return (a.getAttribute('data-keys') || '').localeCompare(b.getAttribute('data-keys'));
        if (mode === 'weight-asc' || mode === 'weight-desc') {
          var wa = num(a, 'data-weight'), wb = num(b, 'data-weight');
          if (wa === null && wb === null) return 0;
          if (wa === null) return 1;
          if (wb === null) return -1;
          return mode === 'weight-asc' ? wa - wb : wb - wa;
        }
        if (mode === 'price-asc') {
          var pa = num(a, 'data-price'), pb = num(b, 'data-price');
          if (pa === null && pb === null) return 0;
          if (pa === null) return 1;
          if (pb === null) return -1;
          return pa - pb;
        }
        return 0;
      });
      sorted.forEach(function (el) { itemsBox.appendChild(el); });
    });

    var view = null;
    try { view = localStorage.getItem('zdso-view'); } catch (e) {}
    function setView(v) {
      itemsBox.classList.toggle('is-list', v === 'list');
      $$('[data-view]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-view') === v)); });
      try { localStorage.setItem('zdso-view', v); } catch (e) {}
    }
    if (view === 'list') setView('list');
    $$('[data-view]').forEach(function (b) { b.addEventListener('click', function () { setView(b.getAttribute('data-view')); }); });
  }

  /* ---------------- фильтр таблиц ---------------- */
  $$('[data-table-filter]').forEach(function (input) {
    var table = $(input.getAttribute('data-table-filter'));
    if (!table) return;
    input.addEventListener('input', function () {
      var raw = input.value.trim();
      var nd = normDraw(raw), nw = normWords(raw);
      $$('tbody tr', table).forEach(function (tr) {
        if (!raw) { tr.hidden = false; return; }
        var hay = tr.getAttribute('data-row') || tr.textContent.toLowerCase();
        tr.hidden = !(hay.indexOf(nw) >= 0 || (nd.length >= 3 && normDraw(hay).indexOf(nd) >= 0));
      });
    });
  });

  /* ---------------- галерея карточки ---------------- */
  var galMain = $('[data-gal-main]');
  if (galMain) {
    $$('[data-gal-thumb]').forEach(function (b) {
      b.addEventListener('click', function () {
        var src = b.getAttribute('data-gal-thumb');
        var img = $('img', galMain);
        if (img) { img.src = src; img.removeAttribute('srcset'); }
        $$('[data-gal-thumb]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
      });
    });
  }

  /* ---------------- копирование номера ---------------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!b) return;
    var text = b.getAttribute('data-copy');
    var done = function () {
      var span = $('span', b);
      var old = span ? span.textContent : '';
      b.classList.add('is-done');
      if (span) span.textContent = 'Скопировано';
      setTimeout(function () { b.classList.remove('is-done'); if (span) span.textContent = old; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else fallback();
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (err) {}
      document.body.removeChild(ta);
    }
  });

  /* ---------------- карта по клику ---------------- */
  $$('[data-map-load]').forEach(function (b) {
    b.addEventListener('click', function () {
      var wrap = b.closest('[data-map]');
      var f = document.createElement('iframe');
      f.src = b.getAttribute('data-src');
      f.width = '100%'; f.height = '420'; f.frameBorder = '0';
      f.loading = 'lazy'; f.title = 'Карта: офис в Челябинске';
      f.style.border = '0'; f.style.borderRadius = '8px';
      f.setAttribute('allowfullscreen', '');
      wrap.appendChild(f);
      b.remove();
    });
  });

  /* ---------------- модальные формы ---------------- */
  var dlg = $('[data-dialog="callback"]');
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-modal]') : null;
    if (!b || !dlg) return;
    var kind = b.getAttribute('data-modal');
    var form = $('form', dlg);
    var KINDS = {
      callback: { title: 'Заказать звонок', btn: 'Жду звонка', comment: false, files: false },
      kp: { title: 'Запросить КП', btn: 'Запросить КП', comment: true, files: true, label: 'Что нужно уточнить' },
      drawing: { title: 'Прислать чертёж', btn: 'Отправить чертёж', comment: true, files: true, label: 'Что изготовить (необязательно)' },
      komplekt: { title: 'Подобрать комплект', btn: 'Подобрать комплект', comment: true, files: true, label: 'Что меняете' },
      question: { title: 'Вопрос менеджеру', btn: 'Отправить вопрос', comment: true, files: true, label: 'Вопрос' }
    };
    var cfg = KINDS[kind] || KINDS.question;
    $('h2', dlg).textContent = cfg.title;
    form.querySelector('[name="kind"]').value = kind;
    form.querySelector('[name="subject"]').value = b.getAttribute('data-subject') || '';
    form.querySelector('[name="page"]').value = location.pathname;
    var ta = form.querySelector('[name="comment"]');
    if (ta) ta.value = b.getAttribute('data-comment') || '';
    var fComment = form.querySelector('[data-field="comment"]');
    var fFiles = form.querySelector('[data-field="files"]');
    if (fComment) {
      fComment.hidden = !cfg.comment;
      var lbl = fComment.querySelector('label');
      if (lbl && cfg.label) lbl.textContent = cfg.label;
    }
    if (fFiles) fFiles.hidden = !cfg.files;
    var sub = form.querySelector('button[type="submit"]');
    if (sub) sub.textContent = cfg.btn;
    // Подзаголовок под шапкой окна меняем вместе с типом заявки
    var note = dlg.querySelector('[data-modal-note]');
    if (note) note.textContent = kind === 'callback'
      ? 'Перезвоним в рабочее время.'
      : 'Ответим в рабочее время, обычно в течение часа.';
    form.dataset.opened = String(Date.now());
    resetForm(form);
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
  });
  $$('[data-close-dialog]').forEach(function (b) {
    b.addEventListener('click', function () { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); });
  });
  if (dlg) dlg.addEventListener('click', function (e) { if (e.target === dlg && dlg.close) dlg.close(); });

  /* ---------------- отправка форм ---------------- */
  function resetForm(form) {
    var msg = $('[data-msg]', form);
    if (msg) { msg.hidden = true; msg.textContent = ''; msg.className = 'form__msg'; }
    $$('[data-err]', form).forEach(function (s) { s.textContent = ''; });
    $$('[aria-invalid]', form).forEach(function (i) { i.removeAttribute('aria-invalid'); });
  }

  $$('form[data-form]').forEach(function (form) {
    // Время заполнения считаем на клиенте и отправляем именно его: сравнивать
    // Date.now() браузера с часами сервера нельзя — расхождение часов ломает проверку.
    form.dataset.opened = String(Date.now());
    var fl = $('[data-filelist]', form);
    var fi = form.querySelector('input[type="file"]');
    if (fi && fl) fi.addEventListener('change', function () {
      fl.innerHTML = Array.prototype.map.call(fi.files, function (f) {
        return '<li>' + esc(f.name) + ' — ' + (f.size / 1048576).toFixed(1) + ' МБ</li>';
      }).join('');
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var elapsed = Date.now() - Number(form.dataset.opened || Date.now());
      form.querySelector('[name="t"]').value = String(elapsed >= 0 ? elapsed : 0);
      resetForm(form);
      var btn = form.querySelector('button[type="submit"]');
      var msg = $('[data-msg]', form);

      var bad = false;
      var name = form.querySelector('[name="name"]');
      var phone = form.querySelector('[name="phone"]');
      var consent = form.querySelector('[name="consent"]');
      if (!name.value.trim()) { setErr(form, 'name', 'Укажите имя'); bad = true; }
      if (String(phone.value).replace(/\D/g, '').length < 10) { setErr(form, 'phone', 'Укажите телефон не короче 10 цифр'); bad = true; }
      if (consent && !consent.checked) {
        msg.hidden = false; msg.className = 'form__msg form__msg--err';
        msg.textContent = 'Без согласия на обработку персональных данных мы не можем принять заявку.';
        bad = true;
      }
      if (bad) return;

      btn.classList.add('is-loading');
      btn.disabled = true;
      var fd = new FormData(form);
      fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
        .then(function (j) {
          btn.classList.remove('is-loading');
          btn.disabled = false;
          msg.hidden = false;
          if (j && j.ok) {
            msg.className = 'form__msg form__msg--ok';
            msg.innerHTML = 'Заявка принята. Ответим в рабочее время, обычно в течение часа. Если нужно срочно — позвоните или напишите в мессенджер.';
            form.reset();
            if (fl) fl.innerHTML = '';
            goal('form_' + (fd.get('kind') || 'question'));
            goal('form_submit');
            ecommerce(fd);
          } else {
            msg.className = 'form__msg form__msg--err';
            msg.textContent = (j && j.error) || 'Не удалось отправить. Позвоните нам или напишите в мессенджер.';
          }
        })
        .catch(function () {
          btn.classList.remove('is-loading');
          btn.disabled = false;
          msg.hidden = false;
          msg.className = 'form__msg form__msg--err';
          msg.textContent = 'Сеть недоступна. Позвоните нам или напишите в мессенджер.';
        });
    });
  });

  function setErr(form, field, text) {
    var inp = form.querySelector('[name="' + field + '"]');
    var box = form.querySelector('[data-err="' + field + '"]');
    if (inp) inp.setAttribute('aria-invalid', 'true');
    if (box) box.textContent = text;
  }

  /**
   * Электронная коммерция Метрики: корзины нет, поэтому отправляем состав заявки
   * как покупку из одной позиции — так в отчётах видно, какие детали запрашивают.
   */
  function ecommerce(fd) {
    var subject = String(fd.get('subject') || '').trim();
    if (!subject) return;
    try {
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push({
        ecommerce: {
          currencyCode: 'RUB',
          purchase: {
            actionField: { id: 'lead-' + Date.now() },
            products: [{ name: subject, category: String(fd.get('kind') || 'question'), quantity: 1 }]
          }
        }
      });
    } catch (e) {}
  }

  /* ---------------- скачивание прайса / спецификации ---------------- */
  $$('a[download], a[href$=".csv"], a[href$=".xlsx"], a[href$=".pdf"]').forEach(function (a) {
    a.addEventListener('click', function () { goal('download_price'); });
  });

  /* ---------------- глубокий просмотр карточки ---------------- */
  if (/^\/catalog\/.+\/.+\//.test(location.pathname)) {
    setTimeout(function () { goal('product_view_60s'); }, 60000);
  }

  /* ---------------- cookie ---------------- */
  var ck = $('[data-cookie]');
  if (ck) {
    var seen = false;
    try { seen = localStorage.getItem('zdso-cookie') === '1'; } catch (e) { seen = true; }
    if (!seen) ck.hidden = false;
    var ok = $('[data-cookie-ok]', ck);
    if (ok) ok.addEventListener('click', function () {
      ck.hidden = true;
      try { localStorage.setItem('zdso-cookie', '1'); } catch (e) {}
    });
  }
})();
