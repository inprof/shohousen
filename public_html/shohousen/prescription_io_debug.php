<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$tenantId = (int)$user['tenant_id'];
$jobId = (int)($_GET['job_id'] ?? 0);
$prescriptionId = (int)($_GET['id'] ?? $_GET['prescription_id'] ?? 0);

$prescription = null;
if ($prescriptionId > 0) {
    $prescription = get_prescription($tenantId, $prescriptionId);
    if ($prescription && $jobId <= 0) {
        $jobId = (int)($prescription['parse_job_id'] ?? 0);
    }
}

$job = null;
if ($jobId > 0) {
    $job = PrescriptionOcrService::getJob($tenantId, $jobId);
    if ($job && !$prescription && (int)($job['prescription_id'] ?? 0) > 0) {
        $prescriptionId = (int)$job['prescription_id'];
        $prescription = get_prescription($tenantId, $prescriptionId);
    }
}

if (!$job && !$prescription) {
    http_response_code(404);
    exit('診断対象データが見つかりません');
}

$debug = new PrescriptionIoDebugService();
$snapshots = $debug->snapshots($tenantId, $jobId > 0 ? $jobId : null, $prescriptionId > 0 ? $prescriptionId : null);

$appendUnique = static function (array &$rows, array $newRows): void {
    $seen = [];
    foreach ($rows as $row) {
        $seen[(string)($row['stage'] ?? '') . '|' . (string)($row['source_hash'] ?? '')] = true;
    }
    foreach ($newRows as $row) {
        $key = (string)($row['stage'] ?? '') . '|' . (string)($row['source_hash'] ?? '');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $rows[] = $row;
    }
};

if ($job) {
    $appendUnique($snapshots, $debug->fallbackJobSnapshots($job));
}
if ($prescription) {
    $dbJson = json_encode($prescription, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $appendUnique($snapshots, [[
        'stage' => 'db_saved_prescription_current',
        'stage_label' => '現在DB: 処方箋保存データ',
        'model_name' => (string)($job['model_name'] ?? ''),
        'content_type' => 'json',
        'snapshot_json' => $dbJson,
        'snapshot_text' => null,
        'created_at' => (string)($prescription['updated_at'] ?? $prescription['received_at'] ?? ''),
        'source_hash' => hash('sha256', $dbJson ?: ''),
    ]]);
    $qrPayload = (string)($prescription['qr_payload'] ?? '');
    if ($qrPayload !== '') {
        $appendUnique($snapshots, [[
            'stage' => 'qr_payload_current',
            'stage_label' => '現在DB: QR中間データ',
            'model_name' => (string)($job['model_name'] ?? ''),
            'content_type' => 'text',
            'snapshot_json' => null,
            'snapshot_text' => $qrPayload,
            'created_at' => (string)($prescription['updated_at'] ?? ''),
            'source_hash' => hash('sha256', $qrPayload),
        ]]);
    }
}

usort($snapshots, static function (array $a, array $b): int {
    $order = [
        'openai_raw_response' => 10,
        'openai_normalized' => 20,
        'normalized_after_correction' => 30,
        'confirmed_post' => 40,
        'db_saved_prescription' => 50,
        'db_saved_prescription_current' => 55,
        'qr_payload' => 60,
        'qr_payload_current' => 65,
    ];
    $ao = $order[(string)($a['stage'] ?? '')] ?? 99;
    $bo = $order[(string)($b['stage'] ?? '')] ?? 99;
    if ($ao !== $bo) {
        return $ao <=> $bo;
    }
    return strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
});

function io_debug_snapshot_body(array $row): string
{
    $type = (string)($row['content_type'] ?? 'json');
    if ($type === 'text') {
        return (string)($row['snapshot_text'] ?? '');
    }
    $json = (string)($row['snapshot_json'] ?? '');
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: $json;
    }
    return $json;
}

function io_debug_stage_class(string $stage): string
{
    if (str_contains($stage, 'openai') || str_contains($stage, 'normalized')) {
        return 'read';
    }
    if (str_contains($stage, 'qr')) {
        return 'write';
    }
    return 'save';
}

View::header('処方箋IO診断');
?>
<section class="page-title">
  <h1>処方箋IO診断</h1>
  <p>読み込み直後・人間修正後・DB保存後・書き出し後を同じ画面で確認します。モデル精度の問題か、保存/QR変換側の問題かを切り分けるための画面です。</p>
</section>

<section class="card">
  <h2>対象</h2>
  <div class="summary-grid compact">
    <div><span>解析ジョブID</span><strong><?= h($jobId > 0 ? (string)$jobId : '未紐づけ') ?></strong></div>
    <div><span>保存ID</span><strong><?= h($prescriptionId > 0 ? (string)$prescriptionId : '未保存') ?></strong></div>
    <div><span>モデル</span><strong><?= h((string)($job['model_name'] ?? app_config('openai.model', ''))) ?></strong></div>
    <div><span>状態</span><strong><?= h((string)($job['status'] ?? ($prescription ? 'saved' : ''))) ?></strong></div>
  </div>
  <div class="button-row">
    <?php if ($jobId > 0): ?>
      <a class="btn ghost" href="<?= h(app_url('/prescription_job_image.php?job_id=' . (string)$jobId)) ?>" target="_blank" rel="noopener">元画像を開く</a>
    <?php endif; ?>
    <?php if ($prescriptionId > 0): ?>
      <a class="btn ghost" href="<?= h(app_url('/prescription_saved.php?id=' . (string)$prescriptionId)) ?>">保存内容へ戻る</a>
      <a class="btn ghost" href="<?= h(app_url('/qr.php?id=' . (string)$prescriptionId)) ?>">QR画面へ</a>
    <?php elseif ($jobId > 0): ?>
      <a class="btn ghost" href="<?= h(app_url('/prescription_result.php?job_id=' . (string)$jobId)) ?>">読み取り結果へ戻る</a>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <h2>確認ポイント</h2>
  <div class="rule-check-list compact">
    <article class="rule-check-item info"><strong>読み込み後JSONが間違い</strong><p>画像認識・プロンプト・モデル・前処理・テンプレート判定側の問題です。</p></article>
    <article class="rule-check-item warning"><strong>読み込み後JSONは正しいがDB保存後が違う</strong><p>確認画面POST、正規化、DB保存処理、薬品計算ロジック側の問題です。</p></article>
    <article class="rule-check-item danger"><strong>DB保存後は正しいがQR中間データが違う</strong><p>JAHIS/QRマッピング、使用項目選択、書き出し処理側の問題です。</p></article>
  </div>
</section>

<section class="card io-debug-card">
  <h2>IOスナップショット</h2>
  <?php if (!$snapshots): ?>
    <div class="alert warning">診断スナップショットがまだありません。新しく処方箋を読み込むと自動保存されます。</div>
  <?php endif; ?>

  <?php foreach ($snapshots as $i => $row): ?>
    <?php
      $stage = (string)($row['stage'] ?? 'unknown');
      $body = io_debug_snapshot_body($row);
      $class = io_debug_stage_class($stage);
    ?>
    <details class="io-debug-snapshot <?= h($class) ?>" <?= $i < 3 ? 'open' : '' ?>>
      <summary>
        <strong><?= h((string)($row['stage_label'] ?? $stage)) ?></strong>
        <span><?= h((string)($row['created_at'] ?? '')) ?></span>
        <?php if (!empty($row['model_name'])): ?><span>model: <?= h((string)$row['model_name']) ?></span><?php endif; ?>
      </summary>
      <textarea readonly rows="18" spellcheck="false"><?= h($body) ?></textarea>
      <?php if (!empty($row['source_hash'])): ?><p class="muted">hash: <?= h((string)$row['source_hash']) ?></p><?php endif; ?>
    </details>
  <?php endforeach; ?>
</section>

<style>
.io-debug-card textarea { width: 100%; box-sizing: border-box; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; line-height: 1.45; white-space: pre; }
.io-debug-snapshot { border: 1px solid rgba(0,0,0,.12); border-radius: 12px; padding: 10px 12px; margin: 12px 0; background: rgba(255,255,255,.72); }
.io-debug-snapshot summary { cursor: pointer; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.io-debug-snapshot summary span { font-size: 12px; opacity: .72; }
.io-debug-snapshot.read { border-left: 6px solid #4b7bec; }
.io-debug-snapshot.save { border-left: 6px solid #f7b731; }
.io-debug-snapshot.write { border-left: 6px solid #eb3b5a; }
.muted { opacity: .7; font-size: 12px; }
</style>
<?php View::footer(); ?>
