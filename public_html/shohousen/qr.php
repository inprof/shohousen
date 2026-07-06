<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$id = (int)($_GET['id'] ?? 0);
$prescription = $id ? get_prescription((int)$user['tenant_id'], $id) : null;
if (!$prescription) { http_response_code(404); exit('データが見つかりません'); }
$ruleChecks = (array)($prescription['rule_checks'] ?? []);
$hasBlockingRules = PrescriptionRuleEngineService::hasOpenBlockingChecks($ruleChecks);
$payload = (string)($prescription['qr_payload'] ?? '');
if ($payload === '' && !$hasBlockingRules) {
    $payload = (new PrescriptionQrService())->persistPayload((int)$user['tenant_id'], $id);
}
View::header('QRコード表示');
?>
<section class="qr-wrap card">
  <h1>QRコード表示</h1>
  <?php if ($hasBlockingRules): ?>
    <p>QR作成前に確認すべき重要判定があります。下の内容を確認し、必要に応じて修正・疑義照会運用へ回してください。</p>
    <div class="alert danger"><strong>QR作成を一時停止しました。</strong><br>期限切れ候補、医薬品名読取不可、変更不可/署名不足など、QR前確認が必要な判定があります。</div>
    <div class="rule-check-list compact">
      <?php foreach ($ruleChecks as $check): if (empty($check['blocks_qr'])) continue; ?>
        <article class="rule-check-item <?= h((string)($check['severity'] ?? 'danger')) ?>">
          <strong><?= h((string)($check['title'] ?? '確認項目')) ?></strong>
          <p><?= h((string)($check['message'] ?? '')) ?></p>
          <div class="rule-check-meta"><span>対応: <?= h((string)($check['recommended_action'] ?? '内容を確認してください。')) ?></span></div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
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
  <?php endif; ?>
  <div class="button-row center"><a class="btn ghost" href="<?= h(app_url('/menu.php')) ?>">終了してメニューへ戻る</a><a class="btn ghost" href="<?= h(app_url('/prescription_io_debug.php?id=' . (string)$id)) ?>">IO診断を見る</a><a class="btn primary" href="<?= h(app_url('/reception_detail.php?id=' . (string)$id)) ?>">受付データを見る</a></div>
</section>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js" defer></script>
<script src="<?= h(app_url('/assets/js/prescription_qr.js')) ?>" defer></script>
<?php View::footer(); ?>
