<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();

// 解析モデルは当面 gpt-4o-mini 固定。将来のモデル切替導線だけ hidden で残す。
$fixedModelTier = 'low';
$fixedModelName = 'gpt-4o-mini';
View::header('処方箋読込');
?>
<link rel="stylesheet" href="<?= h(app_url('/assets/css/prescription_scan.css')) ?>">
<section class="page-title with-back">
  <a class="back-link" href="<?= h(app_url('/menu.php')) ?>">←</a>
  <div><h1>処方箋読込</h1><p>画像の文字起こしとテンプレート構造を別々に取得し、患者・保険・公費・医療機関・薬剤を1項目1タスクで抽出して、1画像1JSONへ統合します。</p></div>
  <a class="btn ghost" href="<?= h(app_url('/prescription_json_viewer.php')) ?>">DB内JSON確認</a>
</section>

<form class="card result-card scan-upload" method="post" action="<?= h(app_url('/prescription_analyze.php')) ?>" enctype="multipart/form-data" id="prescriptionScanForm">
  <?= Csrf::field() ?>
  <input type="hidden" name="source_type" id="sourceType" value="camera">

  <div class="scan-layout">
    <section class="scan-guide">
      <h2>撮影ガイド</h2>
      <ul>
        <li>処方箋全体を枠内に入れてください。</li>
        <li>暗い場所、斜め撮影、ピンぼけは解析精度が落ちます。</li>
        <li>AI解析後、人間確認・修正してからQR化します。</li>
      </ul>
      <input type="hidden" name="model_tier" id="modelTier" value="<?= h($fixedModelTier) ?>">
      <div class="model-tier-fixed" aria-label="解析モデル">
        <strong>解析モデル</strong>
        <span><?= h($fixedModelName) ?> 固定</span>
        <p class="hint left">将来のモデル切替用導線は内部値として残し、画面上の高精度/中価格/低価格選択は非表示にしています。</p>
      </div>

      <div class="capture-actions" aria-label="処方箋画像の取り込み方法">
        <input class="native-hidden-file-input" type="file" name="prescription_file" id="prescriptionFile" accept="image/*" capture="environment" required>
        <input class="native-hidden-file-input" type="file" id="prescriptionFilePicker" accept="image/jpeg,image/png,image/webp,image/*">
        <button class="btn primary capture-action-button" type="button" id="openCameraButton">
          カメラを起動して撮影
        </button>
        <button class="btn ghost capture-action-button" type="button" id="openFilePickerButton">
          保存済み画像から選択
        </button>
      </div>
      <p class="hint left">スマホ/iPadでは「カメラを起動して撮影」から撮影してください。PCなどカメラ撮影に非対応の環境ではファイル選択になる場合があります。</p>
      <p class="hint left">対応：JPG / PNG / WEBP。PDFは画像化して取り込んでください。</p>
    </section>

    <section class="scan-preview-panel">
      <div class="scan-frame" id="scanFrame">
        <span>ここに撮影画像のプレビューが表示されます</span>
        <img id="scanPreview" alt="処方箋プレビュー" hidden>
      </div>
      <div class="scan-quality" id="scanQuality">画像選択後にサイズを確認します。</div>
    </section>
  </div>

  <div class="button-row end">
    <a class="btn ghost" href="<?= h(app_url('/menu.php')) ?>">キャンセル</a>
    <button class="btn primary" type="submit" id="analyzeButton" disabled>解析開始</button>
  </div>
</form>
<script src="<?= h(app_url('/assets/js/prescription_scan.js')) ?>"></script>
<?php View::footer(); ?>
