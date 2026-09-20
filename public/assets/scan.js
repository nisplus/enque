/* 景品交換の照会画面：スマホのカメラで交換コードのQRを読み取る
 *
 * ・端末のブラウザに Barcode Detection API があればそれを使う（Android Chrome など）
 * ・無ければ同梱の jsQR で解析する（iOS Safari はこちら）
 * ・カメラが使えない端末ではボタンを出さず、交換コードの手入力に任せる
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

  if (typeof window !== 'undefined') {
    window.enqueExtractClaimCode = extractCode;
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

  var hasCamera = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  var secure    = window.isSecureContext !== false;

  if (!hasCamera || !secure) {
    // 読み取りが使えない端末では、手入力だけを見せる
    if (unsupport) {
      unsupport.textContent = !secure
        ? 'このページはHTTPSで開いたときだけカメラを使えます。交換コードを手入力してください。'
        : 'このブラウザではカメラでの読み取りができません。交換コードを手入力してください。';
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

  function found(code) {
    stop();
    setStatus('読み取りました：' + code);
    if (navigator.vibrate) {
      navigator.vibrate(60);
    }
    window.location.href = 'claim.php?code=' + encodeURIComponent(code);
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
        var code = results && results.length ? extractCode(results[0].rawValue) : null;
        if (code) {
          found(code);
          return;
        }
        if (results && results.length) {
          setStatus('交換コードのQRコードではないようです。もう一度かざしてください。');
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
      var code   = result ? extractCode(result.data) : null;
      if (code) {
        found(code);
        return;
      }
      if (result) {
        setStatus('交換コードのQRコードではないようです。もう一度かざしてください。');
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
      setStatus('来場者の画面のQRコードを枠に入れてください。');

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
        setStatus('使えるカメラが見つかりませんでした。交換コードを手入力してください。');
      } else {
        setStatus('カメラを起動できませんでした。交換コードを手入力してください。');
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
      setStatus('カメラを止めました。もう一度「QRコードを読み取る」を押してください。');
      panel.hidden = false;
    }
  });
  window.addEventListener('pagehide', stop);
})();
