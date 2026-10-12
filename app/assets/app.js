(function () {
  'use strict';

  var STORAGE_KEY = 'jpweb_action_open';
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function readState() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) return {};
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function writeState(state) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (e) {
      /* ignore quota / private mode */
    }
  }

  function setExpanded(card, button, body, open) {
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      body.hidden = false;
      if (!reduceMotion) {
        body.style.overflow = 'hidden';
        var h = body.scrollHeight;
        body.style.height = '0px';
        requestAnimationFrame(function () {
          body.style.transition = 'height 150ms ease';
          body.style.height = h + 'px';
        });
        window.setTimeout(function () {
          body.style.height = '';
          body.style.overflow = '';
          body.style.transition = '';
        }, 160);
      }
    } else {
      if (!reduceMotion) {
        body.style.overflow = 'hidden';
        body.style.height = body.scrollHeight + 'px';
        requestAnimationFrame(function () {
          body.style.transition = 'height 150ms ease';
          body.style.height = '0px';
        });
        window.setTimeout(function () {
          body.hidden = true;
          body.style.height = '';
          body.style.overflow = '';
          body.style.transition = '';
        }, 160);
      } else {
        body.hidden = true;
      }
    }
  }

  function initActionCards() {
    var cards = document.querySelectorAll('.action-card');
    if (!cards.length) return;

    var state = readState();
    var isDesktop = window.matchMedia('(min-width: 1024px)').matches;
    var deeplink = '';
    if (location.hash.indexOf('#actie-') === 0) {
      deeplink = location.hash.slice(1);
    }

    cards.forEach(function (card, index) {
      var button = card.querySelector('[data-action-toggle]');
      var body = card.querySelector('.action-card__body');
      if (!button || !body) return;
      var id = card.getAttribute('data-action-id') || String(index);
      var open;
      if (Object.prototype.hasOwnProperty.call(state, id)) {
        open = !!state[id];
      } else if (deeplink && card.id === deeplink) {
        open = true;
      } else if (isDesktop && index === 0) {
        open = true;
      } else {
        open = false;
      }
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      body.hidden = !open;
      state[id] = open;

      button.addEventListener('click', function () {
        var next = button.getAttribute('aria-expanded') !== 'true';
        setExpanded(card, button, body, next);
        state[id] = next;
        writeState(state);
        syncToolbar();
      });
    });

    writeState(state);

    if (deeplink) {
      var target = document.getElementById(deeplink);
      if (target) {
        target.scrollIntoView({ block: 'start', behavior: reduceMotion ? 'auto' : 'smooth' });
      }
    }

    var toolbar = document.querySelector('[data-actions-toolbar]');
    if (toolbar) {
      toolbar.addEventListener('click', function () {
        var expand = toolbar.getAttribute('data-mode') !== 'collapse';
        cards.forEach(function (card) {
          var button = card.querySelector('[data-action-toggle]');
          var body = card.querySelector('.action-card__body');
          var id = card.getAttribute('data-action-id');
          if (!button || !body || !id) return;
          setExpanded(card, button, body, expand);
          state[id] = expand;
        });
        writeState(state);
        syncToolbar();
      });
    }

    function syncToolbar() {
      if (!toolbar) return;
      var buttons = document.querySelectorAll('.action-card [data-action-toggle]');
      var allOpen = true;
      buttons.forEach(function (b) {
        if (b.getAttribute('aria-expanded') !== 'true') allOpen = false;
      });
      if (allOpen) {
        toolbar.setAttribute('data-mode', 'collapse');
        toolbar.textContent = 'Alles inklappen';
      } else {
        toolbar.setAttribute('data-mode', 'expand');
        toolbar.textContent = 'Alles openklappen';
      }
    }

    syncToolbar();
  }

  function initClamp() {
    document.querySelectorAll('[data-clamp]').forEach(function (el) {
      var btn = el.parentElement && el.parentElement.querySelector('[data-clamp-toggle]');
      if (!btn) return;
      if (el.scrollHeight > el.clientHeight + 4) {
        btn.hidden = false;
        btn.addEventListener('click', function () {
          el.classList.toggle('is-open');
          btn.textContent = el.classList.contains('is-open') ? 'Toon minder' : 'Toon hele tekst';
        });
      }
    });
  }

  function initDialogs() {
    document.querySelectorAll('[data-open-dialog]').forEach(function (trigger) {
      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        var id = trigger.getAttribute('data-open-dialog');
        var dialog = id ? document.getElementById(id) : null;
        if (!dialog) return;

        var qAction = trigger.getAttribute('data-q-action');
        if (qAction) {
          var actionInput = document.getElementById('q-action');
          var idInput = document.getElementById('q-id');
          var textInput = document.getElementById('q-text');
          var placeInput = document.getElementById('q-place');
          var fields = document.getElementById('q-fields');
          var confirmBtn = document.getElementById('q-confirm');
          if (actionInput) actionInput.value = qAction;
          if (idInput) idInput.value = trigger.getAttribute('data-q-id') || '';
          if (textInput) {
            textInput.value = trigger.getAttribute('data-q-text') || '';
            textInput.required = qAction !== 'delete';
          }
          if (placeInput) placeInput.value = trigger.getAttribute('data-q-place') || '';
          if (fields) fields.hidden = qAction === 'delete';
          if (confirmBtn) {
            confirmBtn.textContent = qAction === 'delete'
              ? 'Ja, verwijder de vraag'
              : 'Ja, wijzig de vragen';
          }
        }

        dialog.hidden = false;
        var cancel = dialog.querySelector('[data-dialog-cancel]');
        if (cancel) cancel.focus();
      });
    });
    document.querySelectorAll('[data-dialog-cancel]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var dialog = btn.closest('.dialog-backdrop');
        if (dialog) dialog.hidden = true;
      });
    });
  }

  var LIVE_STEPS = {
    site: 'Ik bekijk nu je site…',
    profiel: 'Ik maak nu je bedrijfsprofiel…',
    vragen: 'Ik stel nu de vragen op die klanten aan AI stellen…',
    meten: 'Ik stel de vragen nu aan de AI-assistenten…',
    rapport: 'Ik zet de uitslag nu voor je klaar…'
  };

  function initLiveProgress() {
    var box = document.querySelector('[data-live-progress]');
    if (!box) return;
    var stepEl = box.querySelector('[data-live-step]');
    var srcEl = box.querySelector('[data-live-sources]');
    var ansEl = box.querySelector('[data-live-answers]');
    var stopped = false;

    function text(tag, value, cls) {
      var el = document.createElement(tag);
      if (cls) el.className = cls;
      el.textContent = value;
      return el;
    }

    function render(p) {
      var step = LIVE_STEPS[p.step] || LIVE_STEPS.site;
      if (p.step === 'meten' && p.active_source) step = 'Ik vraag nu ' + p.active_source + '…';
      stepEl.textContent = step;
      srcEl.replaceChildren();
      (p.sources || []).forEach(function (s) {
        srcEl.appendChild(text('li', s.label + ' ' + s.done + '/' + s.total + (s.done ? ' · ' + s.mentioned + '× genoemd' : '')));
      });
      srcEl.hidden = !(p.sources || []).length;
      ansEl.replaceChildren();
      (p.answers || []).slice(-10).reverse().forEach(function (a) {
        var li = text('li', a.source + ': ' + a.vraag + ' ');
        li.appendChild(text('strong', a.mentioned ? 'genoemd' : 'niet genoemd', a.mentioned ? 'is-yes' : 'is-no'));
        ansEl.appendChild(li);
      });
      ansEl.hidden = !(p.answers || []).length;
    }

    function poll() {
      if (stopped || document.hidden) { setTimeout(poll, 4000); return; }
      fetch('/app/progress.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.ok) return;
          if (d.status === 'klaar' || d.status === 'mislukt') { stopped = true; window.location.reload(); return; }
          if (d.progress) render(d.progress);
        })
        .catch(function () {})
        .finally(function () { if (!stopped) setTimeout(poll, 4000); });
    }
    poll();
  }

  document.addEventListener('DOMContentLoaded', function () {
    initLiveProgress();
    initActionCards();
    initClamp();
    initDialogs();
  });
})();
