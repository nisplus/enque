/* 企業周遊アンケート 回答画面のスクリプト
 *
 * ・入力内容を localStorage に一時保存する（リロード・タブ閉じで消えないように）
 * ・進捗（何問中何問回答したか）を表示する
 * ・送信は fetch + 自動リトライ（会場Wi-Fiが不安定な前提）。失敗しても再送できる
 *
 * JavaScript が無効でも、フォームは通常の POST として動作する。
 */
(function () {
  'use strict';

  var form = document.getElementById('survey-form');
  if (!form) {
    return;
  }

  var storageKey = form.getAttribute('data-storage-key') || '';
  var submitBtn = form.querySelector('[data-submit]');
  var errorBox = document.getElementById('form-error');
  var progressText = document.getElementById('progress-text');
  var progressBar = document.getElementById('progress-bar-fill');

  // ------------------------------------------------------------ 一時保存

  function safeStorage(fn, fallback) {
    // プライベートブラウズや設定でストレージが使えないことがある
    try {
      return fn();
    } catch (e) {
      return fallback;
    }
  }

  function collectDraft() {
    var draft = {};
    var elements = form.querySelectorAll('input, textarea, select');
    for (var i = 0; i < elements.length; i++) {
      var el = elements[i];
      if (!el.name || el.name === 'csrf_token') {
        continue;
      }
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) {
          if (!draft[el.name]) {
            draft[el.name] = [];
          }
          draft[el.name].push(el.value);
        }
      } else if (el.value !== '') {
        draft[el.name] = [el.value];
      }
    }
    return draft;
  }

  function saveDraft() {
    if (!storageKey) {
      return;
    }
    safeStorage(function () {
      window.localStorage.setItem(storageKey, JSON.stringify(collectDraft()));
    });
  }

  function clearDraft() {
    if (!storageKey) {
      return;
    }
    safeStorage(function () {
      window.localStorage.removeItem(storageKey);
    });
  }

  function restoreDraft() {
    if (!storageKey) {
      return;
    }
    var raw = safeStorage(function () {
      return window.localStorage.getItem(storageKey);
    }, null);
    if (!raw) {
      return;
    }
    var draft;
    try {
      draft = JSON.parse(raw);
    } catch (e) {
      return;
    }
    if (!draft || typeof draft !== 'object') {
      return;
    }

    var restored = false;
    Object.keys(draft).forEach(function (name) {
      var values = draft[name] || [];
      var fields = form.querySelectorAll('[name="' + CSS.escape(name) + '"]');
      for (var i = 0; i < fields.length; i++) {
        var el = fields[i];
        if (el.type === 'checkbox' || el.type === 'radio') {
          if (values.indexOf(el.value) !== -1) {
            el.checked = true;
            restored = true;
          }
        } else if (values.length > 0 && el.value === '') {
          el.value = values[0];
          restored = true;
        }
      }
    });

    if (restored) {
      var notice = document.getElementById('draft-notice');
      if (notice) {
        notice.hidden = false;
      }
    }
  }

  // ------------------------------------------------------------ 進捗表示

  function updateProgress() {
    var questions = form.querySelectorAll('[data-question]');
    if (!questions.length || !progressText) {
      return;
    }
    var answered = 0;
    for (var i = 0; i < questions.length; i++) {
      var inputs = questions[i].querySelectorAll('input, textarea, select');
      var done = false;
      for (var j = 0; j < inputs.length; j++) {
        var el = inputs[j];
        if (el.type === 'checkbox' || el.type === 'radio') {
          if (el.checked) { done = true; }
        } else if (el.value.trim() !== '') {
          done = true;
        }
      }
      if (done) {
        answered++;
      }
    }
    progressText.textContent = questions.length + '問中 ' + answered + '問に回答';
    if (progressBar) {
      progressBar.style.width = Math.round((answered / questions.length) * 100) + '%';
    }
  }

  // ------------------------------------------------------------ 送信

  var sending = false;

  function showError(message, canRetry) {
    if (!errorBox) {
      return;
    }
    errorBox.textContent = message;
    if (canRetry) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-small';
      btn.style.marginLeft = '8px';
      btn.textContent = 'もう一度送信';
      btn.addEventListener('click', function () { submit(); });
      errorBox.appendChild(btn);
    }
    errorBox.hidden = false;
    errorBox.scrollIntoView({ block: 'center' });
  }

  function clearErrors() {
    if (errorBox) {
      errorBox.hidden = true;
      errorBox.textContent = '';
    }
    var marked = form.querySelectorAll('[data-invalid="1"]');
    for (var i = 0; i < marked.length; i++) {
      marked[i].removeAttribute('data-invalid');
    }
  }

  function markInvalid(questionIds) {
    for (var i = 0; i < questionIds.length; i++) {
      var el = form.querySelector('[data-question="' + questionIds[i] + '"]');
      if (el) {
        el.setAttribute('data-invalid', '1');
      }
    }
    var first = form.querySelector('[data-invalid="1"]');
    if (first) {
      first.scrollIntoView({ block: 'center' });
    }
  }

  function setSending(on) {
    sending = on;
    if (submitBtn) {
      submitBtn.disabled = on;
      submitBtn.textContent = on ? '送信中…' : submitBtn.getAttribute('data-label') || '回答を送信する';
    }
  }

  function wait(ms) {
    return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
  }

  // 会場Wi-Fiが切れかけている状況を想定し、間隔を空けて3回まで送り直す
  function postWithRetry(body, attempt) {
    return fetch(form.action, {
      method: 'POST',
      body: body,
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    }).then(function (res) {
      if (res.status >= 500 && attempt < 3) {
        return wait(attempt * 2000 + 1000).then(function () {
          return postWithRetry(body, attempt + 1);
        });
      }
      return res.json().then(function (json) {
        return { status: res.status, json: json };
      }).catch(function () {
        return { status: res.status, json: null };
      });
    }).catch(function (err) {
      if (attempt < 3) {
        return wait(attempt * 2000 + 1000).then(function () {
          return postWithRetry(body, attempt + 1);
        });
      }
      throw err;
    });
  }

  function submit() {
    if (sending) {
      return;
    }
    clearErrors();
    setSending(true);

    postWithRetry(new FormData(form), 1).then(function (result) {
      if (result.json && result.json.ok && result.json.redirect) {
        clearDraft();
        window.location.href = result.json.redirect;
        return;
      }
      setSending(false);
      if (result.json && result.json.questions) {
        markInvalid(result.json.questions);
      }
      showError(
        (result.json && result.json.message) || '送信できませんでした。入力内容をご確認ください。',
        !(result.json && result.json.questions)
      );
    }).catch(function () {
      setSending(false);
      showError(
        navigator.onLine === false
          ? '通信が切れているようです。電波の良い場所で「もう一度送信」を押してください（入力内容は保存されています）。'
          : '送信できませんでした。通信状況をご確認のうえ、もう一度お試しください（入力内容は保存されています）。',
        true
      );
    });
  }

  // ------------------------------------------------------------ 初期化

  restoreDraft();
  updateProgress();

  form.addEventListener('input', function () {
    saveDraft();
    updateProgress();
  });
  form.addEventListener('change', function () {
    saveDraft();
    updateProgress();
  });

  form.addEventListener('submit', function (event) {
    if (!window.fetch || !window.FormData) {
      return; // 古い端末は通常のPOSTに任せる
    }
    event.preventDefault();
    submit();
  });

  window.addEventListener('online', function () {
    if (errorBox && !errorBox.hidden) {
      showError('通信が回復しました。「もう一度送信」を押してください。', true);
    }
  });
})();
