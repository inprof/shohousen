<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireLogin();
$id = (int)($_GET['id'] ?? 0);
$prescription = $id ? get_prescription((int)$user['tenant_id'], $id) : null;
if (!$prescription) { http_response_code(404); exit('データが見つかりません'); }
$seed = hash('sha256', (string)$prescription['qr_payload']);
View::header('QRコード表示');
?>
<section class="qr-wrap card">
  <h1>QRコード表示</h1>
  <p>処方箋データのQRコードが生成されました。</p>
  <div class="qr-box" role="img" aria-label="デモQRコード">
    <?php for ($i = 0; $i < 441; $i++): $bit = hexdec($seed[$i % strlen($seed)]) % 2 === 0; ?>
      <i class="<?= $bit ? 'on' : '' ?>"></i>
    <?php endfor; ?>
  </div>
  <p class="qr-caption">QR用中間データ生成済み<br><small>※MVPでは擬似QR表示です。本番ではJAHIS規格マッピングとQRライブラリを接続してください。</small></p>
  <div class="button-row center"><a class="btn ghost" href="<?= h(app_url('/menu.php')) ?>">終了してメニューへ戻る</a><a class="btn primary" href="<?= h(app_url('/reception_detail.php?id=' . (string)$id)) ?>">受付データを見る</a></div>
</section>
<?php View::footer(); ?>
