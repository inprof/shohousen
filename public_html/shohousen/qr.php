<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$id = (int)($_GET['id'] ?? 0);
$prescription = $id ? get_prescription((int)$user['tenant_id'], $id) : null;
if (!$prescription) { http_response_code(404); exit('データが見つかりません'); }
$payload = (string)($prescription['qr_payload'] ?? '');
if ($payload === '') {
    $payload = (new PrescriptionQrService())->persistPayload((int)$user['tenant_id'], $id);
}
View::header('QRコード表示');
?>
<section class="qr-wrap card">
  <h1>QRコード表示</h1>
  <p>人間確認後に確定保存した処方箋データから、QR用中間データを生成しました。</p>
  <div class="qr-box real-qr-box" role="img" aria-label="処方箋QRコード">
    <canvas id="qrCanvas" width="256" height="256"></canvas>
    <div id="qrFallback" class="alert warning" hidden>QR描画ライブラリを読み込めませんでした。下の中間データを確認してください。</div>
  </div>
  <details class="qr-payload-details">
    <summary>QR用中間データを確認</summary>
    <textarea id="qrPayload" readonly rows="12" style="width:100%;box-sizing:border-box;"><?= h($payload) ?></textarea>
  </details>
  <p class="qr-caption">※MVPではJAHIS正式規格ではなく中間データQRです。本実装ではJAHIS項目マッピング確定後に差し替えてください。</p>
  <div class="button-row center"><a class="btn ghost" href="<?= h(app_url('/menu.php')) ?>">終了してメニューへ戻る</a><a class="btn primary" href="<?= h(app_url('/reception_detail.php?id=' . (string)$id)) ?>">受付データを見る</a></div>
</section>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js" defer></script>
<script src="<?= h(app_url('/assets/js/prescription_qr.js')) ?>" defer></script>
<?php View::footer(); ?>
