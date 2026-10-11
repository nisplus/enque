/* 景品交換の照会：USBバーコードリーダーで「読み取るだけ」で終わらせるための補助
 *
 * ・読み取り欄に常にカーソルを戻す（どこを触っていても読み取りを拾えるように）
 * ・画面のどこで読み取っても、打ち込まれた文字を読み取り欄へ送る
 * ・「渡す景品」と「そのまま交換済みにする」は、この端末に記憶する
 *
 * バーコードリーダーは「QRの中身＋Enter」をキーボードとして打ち込むだけなので、
 * 特別な連携は要らない。フォーカスさえ合っていれば送信まで自動で進む。
 */
(function () {
  'use strict';

  var form  = document.getElementById('scan-form');
  var input = document.getElementById('code');
  if (!form || !input) {
    return;
  }

  /** プライベートブラウズなどで localStorage が使えないことがある */
  function safeStorage(fn, fallback) {
    try {
      return fn();
    } catch (e) {
      return fallback;
    }
  }

  // ---------------------------------------------------------- 選んだ内容を覚える
  var remembered = form.querySelectorAll('[data-remember]');
  Array.prototype.forEach.call(remembered, function (el) {
    var key   = 'enque:' + el.getAttribute('data-remember');
    var saved = safeStorage(function () { return window.localStorage.getItem(key); }, null);

    if (saved !== null) {
      if (el.type === 'checkbox') {
        el.checked = saved === '1';
      } else if (el.querySelector('option[value="' + saved + '"]')) {
        el.value = saved;
      }
    }

    el.addEventListener('change', function () {
      safeStorage(function () {
        window.localStorage.setItem(key, el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value);
      }, null);
    });
  });

  // ---------------------------------------------------------- 読み取り欄に集中させる
  function focusInput() {
    if (document.activeElement !== input) {
      input.focus();
      input.select();
    }
  }

  focusInput();

  // 画面のどこかを触ってしまっても、文字を打ち始めたら読み取り欄に入れる
  document.addEventListener('keydown', function (event) {
    var active = document.activeElement;
    var tag    = active ? active.tagName : '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (active && active.isContentEditable)) {
      return;
    }
    if (event.ctrlKey || event.altKey || event.metaKey || event.key.length !== 1) {
      return;
    }
    focusInput();
  });

  // 読み取り欄から離れても、少し経ったら戻す（次の読み取りを取りこぼさないため）
  input.addEventListener('blur', function () {
    window.setTimeout(function () {
      var active = document.activeElement;
      var tag    = active ? active.tagName : '';
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || tag === 'A') {
        return; // 操作中なら邪魔しない
      }
      focusInput();
    }, 400);
  });

  // 二重送信を防ぐ（リーダーが Enter を2回送ることがある）
  var sending = false;
  form.addEventListener('submit', function (event) {
    if (sending) {
      event.preventDefault();
      return;
    }
    sending = true;
    window.setTimeout(function () { sending = false; }, 2000);
  });
})();
