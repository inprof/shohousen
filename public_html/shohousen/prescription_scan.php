<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
View::header('処方箋読込');
?>
<section class="page-title with-back">
  <a class="back-link" href="<?= h(app_url('/menu.php')) ?>">←</a>
  <div><h1>処方箋読込</h1><p>スマホ/iPadで処方箋を撮影し、OpenAI APIで解析します。確定前に必ず人間確認を行います。</p></div>
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
      <label class="btn primary camera-picker">
        カメラで撮影する
        <input type="file" name="prescription_file" id="prescriptionFile" accept="image/jpeg,image/png,image/webp" capture="environment" required>
      </label>
      <label class="btn ghost camera-picker secondary-picker">
        ファイルから選択する
        <input type="file" id="prescriptionFilePicker" accept="image/jpeg,image/png,image/webp">
      </label>
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
