/* テキスト欄の中身をまとめてコピーする（アンケートURLの一覧をSlackなどに貼る用）
 *
 *   #url-list   … コピー元の textarea
 *   #url-copy   … コピーするボタン
 *   #url-copied … コピー後に出す案内（任意）
 *
 * クリップボードAPIはHTTPS（secure context）でしか使えないため、
 * 使えない環境では全選択だけして手動コピーに任せる。
 */
(function () {
  'use strict';

  var box    = document.getElementById('url-list');
  var button = document.getElementById('url-copy');
  var done   = document.getElementById('url-copied');

  if (!box || !button) {
    return;
  }

  function notify() {
    if (!done) {
      return;
    }
    done.hidden = false;
    window.setTimeout(function () { done.hidden = true; }, 3000);
  }

  button.addEventListener('click', function () {
    box.focus();
    box.select();

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(box.value).then(notify).catch(function () {});
      return;
    }

    try {
      if (document.execCommand('copy')) {
        notify();
      }
    } catch (e) {
      // コピーできない環境では、全選択した状態のまま手動で操作してもらう
    }
  });
})();
