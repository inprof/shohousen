// 処方箋QR表示画面専用JS
// MVPではブラウザ内でQRを描画する。payloadは外部送信せず、canvasへ描画するだけ。
window.addEventListener('DOMContentLoaded', () => {
  const payload = document.getElementById('qrPayload');
  const canvas = document.getElementById('qrCanvas');
  const fallback = document.getElementById('qrFallback');
  if (!payload || !canvas) return;
  const value = payload.value || '';
  if (!value || !window.QRCode || typeof window.QRCode.toCanvas !== 'function') {
    if (fallback) fallback.hidden = false;
    return;
  }
  window.QRCode.toCanvas(canvas, value, {
    width: 256,
    margin: 2,
    errorCorrectionLevel: 'M'
  }, (err) => {
    if (err && fallback) fallback.hidden = false;
  });
});
