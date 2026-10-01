/* スマホのカメラでQRコードを読み取る（2つの画面で使う）
 *
 *   claim … 受付の照会画面。来場者の交換コードを読み取って照会画面を開く
 *   booth … 来場者の回答済み画面。次のブースのQRを読み取り、同じタブで移動する
 *           （カメラアプリを使わずに済むので、タブが増え続けない）
 *
 * どちらで動くかは、ボタン（#scan-open）の data-scan-mode で決める（既定は claim）。
 *
 * ・端末のブラウザに Barcode Detection API があればそれを使う（Android Chrome など）
 * ・無ければ同梱の jsQR で解析する（iOS Safari はこちら）
 * ・カメラが使えない端末ではボタンを出さず、手入力やカメラアプリに任せる
 *
 * カメラの利用には HTTPS（secure context）が必要。
 */
(function () {
  'use strict';

  /**
   * 読み取った文字列から交換コードを取り出す（URL形式・コード単体の両方に対応）。
   *
   * 読み取り以外の部分はカメラが必要で自動テストできないため、この判定だけ
   * window に公開し、tests/scan_test.js から確認できるようにしている。
   */
  function extractCode(text) {
    if (!text) {
      return null;
    }
    var value = String(text).trim();

    // https://example.jp/c/ABCD-2345 または ...?code=ABCD-2345
    var byPath  = value.match(/\/c\/([0-9A-Za-z-]+)/);
    var byQuery = value.match(/[?&]code=([0-9A-Za-z%-]+)/);
    if (byPath) {
      value = byPath[1];
    } else if (byQuery) {
      value = decodeURIComponent(byQuery[1]);
    }

    // 交換コードは読み間違えにくい文字だけで作られている（0/O/1/I は使わない）
    var code = value.toUpperCase().replace(/[^0-9A-Z]/g, '');
    if (!/^[2-9A-HJ-NP-Z]{8}$/.test(code)) {
      return null;
    }

    return code.slice(0, 4) + '-' + code.slice(4);
  }

  /**
   * 読み取った文字列が、このサイトのブースアンケートのURLなら、そのURLを返す。
   *
   * 別のサイトのQRコードを読んでも移動しないよう、同じオリジンで、かつ
   * /s/<イベント>/<企業> か /s.php?e=…&c=… の形のものだけを受け付ける。
   * （origin はテストから渡せるようにしてあり、画面では現在のページのものを使う）
   */
  function extractBoothUrl(text, origin) {
    if (!text) {
      return null;
    }
    var base = origin || (window.location ? window.location.origin : '');
    if (!base) {
      return null;
    }

    var url;
    try {
      url = new URL(String(text).trim(), base);
    } catch (e) {
      return null;
    }

    if (url.origin !== base) {
      return null;
    }
    if (/^\/s\/[^/]+\/[^/]+\/?$/.test(url.pathname)) {
      return url.href;
    }
    if (url.pathname === '/s.php' && url.searchParams.get('e') && url.searchParams.get('c')) {
      return url.href;
    }

    return null;
  }

  if (typeof window !== 'undefined') {
    window.enqueExtractClaimCode = extractCode;
    window.enqueExtractBoothUrl  = extractBoothUrl;
  }
  if (typeof document === 'undefined') {
    return; // テストから読み込まれた場合はここまで
  }

  var openBtn   = document.getElementById('scan-open');
  var panel     = document.getElementById('scan-panel');
  var video     = document.getElementById('scan-video');
  var canvas    = document.getElementById('scan-canvas');
  var statusEl  = document.getElementById('scan-status');
  var closeBtn  = document.getElementById('scan-close');
  var unsupport = document.getElementById('scan-unsupported');

  if (!openBtn || !panel || !video || !canvas) {
    return;
  }

  // 回答済み画面（booth）と受付の照会画面（claim）で、読み取る対象と移動先が変わる
  var booth     = openBtn.getAttribute('data-scan-mode') === 'booth';
  var hasCamera = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  var secure    = window.isSecureContext !== false;

  if (!hasCamera || !secure) {
    // 読み取りが使えない端末では、手入力やカメラアプリに任せる
    if (unsupport) {
      if (booth) {
        unsupport.textContent = 'この画面からはカメラを使えませんでした。'
          + 'お手数ですが、スマートフォンのカメラアプリでブースのQRコードを読み取ってください。';
      } else {
        unsupport.textContent = !secure
          ? 'このページはHTTPSで開いたときだけカメラを使えます。交換コードを手入力してください。'
          : 'このブラウザではカメラでの読み取りができません。交換コードを手入力してください。';
      }
      unsupport.hidden = false;
    }
    return;
  }

  openBtn.hidden = false;

  var stream    = null;
  var timer     = null;
  var detector  = null;
  var scanning  = false;
  var context   = canvas.getContext('2d', { willReadFrequently: true });

  function setStatus(message) {
    if (statusEl) {
      statusEl.textContent = message;
    }
  }

  function stop() {
    scanning = false;
    if (timer) {
      window.clearTimeout(timer);
      timer = null;
    }
    if (stream) {
      stream.getTracks().forEach(function (track) { track.stop(); });
      stream = null;
    }
    video.srcObject = null;
    panel.hidden = true;
    openBtn.hidden = false;
  }

  /** QRは読めたが、この画面で使えるものではなかったときの案内 */
  function mismatch() {
    return booth
      ? 'このイベントのブースのQRコードではないようです。もう一度かざしてください。'
      : '交換コードのQRコードではないようです。もう一度かざしてください。';
  }

  /** 読み取った文字列から、この画面で使える値（交換コード／ブースのURL）を取り出す */
  function read(text) {
    return booth ? extractBoothUrl(text) : extractCode(text);
  }

  function found(value) {
    stop();
    setStatus(booth ? '読み取りました。ブースのアンケートを開きます…' : '読み取りました：' + value);
    if (navigator.vibrate) {
      navigator.vibrate(60);
    }
    window.location.href = booth ? value : 'claim.php?code=' + encodeURIComponent(value);
  }

  /** 1フレーム解析する。約10回/秒に抑えて電池と発熱を抑える */
  function tick() {
    if (!scanning) {
      return;
    }
    if (video.readyState < 2 || !video.videoWidth) {
      timer = window.setTimeout(tick, 100);
      return;
    }

    // 解析は最大640pxに縮小して行う（画面サイズのまま解析すると重い）
    var scale  = Math.min(1, 640 / video.videoWidth);
    var width  = Math.round(video.videoWidth * scale);
    var height = Math.round(video.videoHeight * scale);
    canvas.width  = width;
    canvas.height = height;
    context.drawImage(video, 0, 0, width, height);

    if (detector) {
      detector.detect(canvas).then(function (results) {
        var code = results && results.length ? read(results[0].rawValue) : null;
        if (code) {
          found(code);
          return;
        }
        if (results && results.length) {
          setStatus(mismatch());
        }
        timer = window.setTimeout(tick, 100);
      }).catch(function () {
        // 内蔵APIが途中で使えなくなったら jsQR に切り替える
        detector = null;
        timer = window.setTimeout(tick, 100);
      });
      return;
    }

    if (window.jsQR) {
      var image  = context.getImageData(0, 0, width, height);
      var result = window.jsQR(image.data, width, height, { inversionAttempts: 'dontInvert' });
      var code   = result ? read(result.data) : null;
      if (code) {
        found(code);
        return;
      }
      if (result) {
        setStatus(mismatch());
      }
    }

    timer = window.setTimeout(tick, 100);
  }

  function start() {
    openBtn.hidden = true;
    panel.hidden = false;
    setStatus('カメラを起動しています…');

    navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' } },
      audio: false
    }).then(function (mediaStream) {
      stream = mediaStream;
      video.srcObject = mediaStream;
      video.setAttribute('playsinline', '');  // iOS で全画面にせず再生する
      video.muted = true;
      return video.play();
    }).then(function () {
      scanning = true;
      setStatus(booth
        ? 'ブースに掲示されたQRコードを枠に入れてください。'
        : '来場者の画面のQRコードを枠に入れてください。');

      // 内蔵APIがあれば優先（速く、電池にもやさしい）
      if (window.BarcodeDetector) {
        window.BarcodeDetector.getSupportedFormats().then(function (formats) {
          if (formats.indexOf('qr_code') !== -1) {
            detector = new window.BarcodeDetector({ formats: ['qr_code'] });
          }
          tick();
        }).catch(function () { tick(); });
        return;
      }
      tick();
    }).catch(function (error) {
      stop();
      var name = error && error.name ? error.name : '';
      if (name === 'NotAllowedError' || name === 'SecurityError') {
        setStatus('カメラの使用が許可されていません。ブラウザの設定でこのサイトのカメラを許可してください。');
      } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
        setStatus(booth
          ? '使えるカメラが見つかりませんでした。カメラアプリでQRコードを読み取ってください。'
          : '使えるカメラが見つかりませんでした。交換コードを手入力してください。');
      } else {
        setStatus(booth
          ? 'カメラを起動できませんでした。カメラアプリでQRコードを読み取ってください。'
          : 'カメラを起動できませんでした。交換コードを手入力してください。');
      }
      if (statusEl) {
        statusEl.hidden = false;
      }
      panel.hidden = false;
    });
  }

  openBtn.addEventListener('click', start);
  if (closeBtn) {
    closeBtn.addEventListener('click', stop);
  }

  // 画面を離れたらカメラを止める（電池と、カメラ使用中ランプの消し忘れ対策）
  document.addEventListener('visibilitychange', function () {
    if (document.hidden && scanning) {
      stop();
      setStatus('カメラを止めました。もう一度ボタンを押してください。');
      panel.hidden = false;
    }
  });
  window.addEventListener('pagehide', stop);
})();
