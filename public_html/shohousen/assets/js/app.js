/* =========================================================
   処方箋システム 共通JS
   対象: public_html/shohousen/assets/js/app.js

   目的:
   - 全画面共通で使う最小限の挙動だけを管理する。
   - 画面固有JSは prescription_scan.js など各画面専用JSに分離する。

   運用ルール:
   - 特定画面だけで使う処理は、このファイルに追加しない。
   - 共通化する場合は、対象画面と用途をコメントに残す。
   - ファイル名は既存画面との互換性維持のため app.js のままにする。
   ========================================================= */

document.addEventListener('change', (event) => {
  const input = event.target;
  if (input.matches('input[type="file"]')) {
    const card = input.closest('.file-card');
    const name = input.files && input.files[0] ? input.files[0].name : '';
    if (card && name) {
      card.querySelector('em').textContent = `選択中：${name}`;
    }
  }
});
