<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$tenantId = (int)$user['tenant_id'];
$service = new PrescriptionJsonViewService();

$jobId = (int)($_GET['job_id'] ?? 0);
$jobs = $service->latestJobs($tenantId, 40);
$job = $jobId > 0 ? $service->getJob($tenantId, $jobId) : $service->latestJob($tenantId);
if (!$job) {
    View::header('処方箋JSON確認');
    echo '<section class="page-title"><h1>処方箋JSON確認</h1><p>DBに保存済みの読取JSONを正規化して表示します。</p></section>';
    echo '<section class="card"><div class="alert warning">表示できる処方箋読取ジョブがありません。</div></section>';
    View::footer();
    exit;
}
$jobId = (int)$job['id'];
$metrics = $service->getMetrics($tenantId, $jobId);
$view = $service->normalizeForDisplay($job);

function json_view_value(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    if (is_bool($value)) {
        return $value ? 'はい' : 'いいえ';
    }
    if (is_float($value) || is_int($value)) {
        return rtrim(rtrim((string)$value, '0'), '.');
    }
    return (string)$value;
}

function json_view_table(array $rows): void
{
    echo '<table class="json-view-table">';
    foreach ($rows as $label => $value) {
        echo '<tr><th>' . h((string)$label) . '</th><td>' . h(json_view_value($value)) . '</td></tr>';
    }
    echo '</table>';
}

function json_view_model_label(array $view, ?array $metrics): string
{
    $m = $view['model'] ?? [];
    $parts = [];
    foreach (['job_model_name', 'raw_response_model'] as $key) {
        if (!empty($m[$key])) {
            $parts[] = (string)$m[$key];
        }
    }
    if (!empty($metrics['openai_model'])) {
        $parts[] = (string)$metrics['openai_model'];
    }
    $parts = array_values(array_unique(array_filter($parts)));
    return $parts ? implode(' / ', $parts) : '不明';
}

View::header('処方箋JSON確認');
?>
<section class="page-title with-back">
  <a class="back-link" href="<?= h(app_url('/prescription_scan.php')) ?>">←</a>
  <div>
    <h1>処方箋JSON確認</h1>
    <p>DB内の <code>prescription_parse_jobs.normalized_json</code> を正規化して表示します。どのAIモデルで読んだかも同じ画面で確認できます。</p>
  </div>
</section>

<section class="card json-view-toolbar">
  <form method="get" class="json-view-form">
    <label>読取ジョブ</label>
    <select name="job_id" onchange="this.form.submit()">
      <?php foreach ($jobs as $row): ?>
        <option value="<?= h((string)$row['id']) ?>" <?= (int)$row['id'] === $jobId ? 'selected' : '' ?>>
          #<?= h((string)$row['id']) ?> / <?= h((string)($row['status'] ?? '')) ?> / <?= h((string)($row['model_name'] ?? 'model不明')) ?> / <?= h((string)($row['analyzed_at'] ?? $row['created_at'] ?? '')) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn ghost" type="submit">表示</button>
  </form>
  <div class="button-row">
    <a class="btn ghost" href="<?= h(app_url('/prescription_io_debug.php?job_id=' . (string)$jobId)) ?>">IO診断</a>
    <a class="btn ghost" href="<?= h(app_url('/prescription_job_image.php?job_id=' . (string)$jobId)) ?>" target="_blank" rel="noopener">元画像</a>
    <?php if ((int)($job['prescription_id'] ?? 0) > 0): ?>
      <a class="btn ghost" href="<?= h(app_url('/prescription_saved.php?id=' . (string)(int)$job['prescription_id'])) ?>">保存内容</a>
    <?php else: ?>
      <a class="btn ghost" href="<?= h(app_url('/prescription_result.php?job_id=' . (string)$jobId)) ?>">読取結果</a>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <h2>読取情報</h2>
  <div class="summary-grid compact json-view-summary">
    <div><span>ジョブID</span><strong><?= h((string)$jobId) ?></strong></div>
    <div><span>状態</span><strong><?= h((string)($job['status'] ?? '')) ?></strong></div>
    <div><span>使用モデル</span><strong><?= h(json_view_model_label($view, $metrics)) ?></strong></div>
    <div><span>JSON元</span><strong><?= h((string)($view['source'] ?? '')) ?></strong></div>
    <div><span>信頼度</span><strong><?= h(json_view_value($view['overall_confidence'] ?? null)) ?></strong></div>
    <div><span>解析日時</span><strong><?= h((string)($job['analyzed_at'] ?? $job['created_at'] ?? '')) ?></strong></div>
  </div>
  <div class="json-model-grid">
    <?php json_view_table([
        'job.model_name' => $view['model']['job_model_name'] ?? '',
        'raw.model' => $view['model']['raw_response_model'] ?? '',
        'metrics.openai_model' => $metrics['openai_model'] ?? '',
        'response_id' => $view['model']['raw_response_id'] ?? '',
        'input_tokens' => $view['usage']['input_tokens'] ?? 0,
        'output_tokens' => $view['usage']['output_tokens'] ?? 0,
        'total_tokens' => $view['usage']['total_tokens'] ?? 0,
        'openai_ms' => $metrics['openai_ms'] ?? '',
        'total_ms' => $metrics['total_ms'] ?? '',
    ]); ?>
  </div>
</section>

<section class="json-view-grid">
  <article class="card"><h2>患者情報</h2><?php json_view_table($view['patient']); ?></article>
  <article class="card"><h2>保険情報</h2><?php json_view_table($view['insurance']); ?></article>
  <article class="card"><h2>公費情報</h2><?php json_view_table($view['public_expense']); ?></article>
  <article class="card"><h2>処方箋情報</h2><?php json_view_table($view['prescription']); ?></article>
  <article class="card"><h2>医療機関</h2><?php json_view_table($view['medical_institution']); ?></article>
</section>

<section class="card">
  <h2>薬品情報</h2>
  <?php if (empty($view['medications'])): ?>
    <div class="alert warning">薬品情報はありません。</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="json-view-table wide">
        <thead><tr>
          <?php foreach (array_keys($view['medications'][0]) as $head): ?><th><?= h((string)$head) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($view['medications'] as $med): ?>
            <tr><?php foreach ($med as $value): ?><td><?= h(json_view_value($value)) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>フォーム項目候補</h2>
  <?php if (empty($view['form_fields'])): ?>
    <div class="alert warning">form_fields はありません。</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="json-view-table wide compact-text">
        <thead><tr><th>順</th><th>group</th><th>key</th><th>label</th><th>value</th><th>type</th><th>section</th><th>conf</th><th>要確認</th><th>出力候補</th></tr></thead>
        <tbody>
          <?php foreach ($view['form_fields'] as $field): ?>
            <tr>
              <td><?= h((string)$field['display_order']) ?></td>
              <td><?= h((string)$field['field_group']) ?></td>
              <td><code><?= h((string)$field['field_key']) ?></code></td>
              <td><?= h((string)$field['field_label']) ?></td>
              <td><?= h(json_view_value($field['value'])) ?></td>
              <td><?= h((string)$field['value_type']) ?></td>
              <td><?= h((string)$field['source_section']) ?></td>
              <td><?= h(json_view_value($field['confidence'])) ?></td>
              <td><?= h(json_view_value($field['needs_human_check'])) ?></td>
              <td><?= h(json_view_value($field['output_candidate'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if (!empty($view['warnings'])): ?>
<section class="card">
  <h2>警告</h2>
  <ul class="json-warning-list">
    <?php foreach ($view['warnings'] as $warning): ?><li><?= h((string)$warning) ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card">
  <h2>元JSON</h2>
  <details open>
    <summary>正規化JSON</summary>
    <textarea class="json-raw-text" readonly rows="18" spellcheck="false"><?= h((string)$view['normalized_json_pretty']) ?></textarea>
  </details>
  <details>
    <summary>AI output_text</summary>
    <textarea class="json-raw-text" readonly rows="14" spellcheck="false"><?= h((string)$view['raw_output_text']) ?></textarea>
  </details>
  <details>
    <summary>OpenAI生レスポンス</summary>
    <textarea class="json-raw-text" readonly rows="18" spellcheck="false"><?= h((string)$view['raw_response_json_pretty']) ?></textarea>
  </details>
</section>

<style>
.json-view-toolbar { display:flex; gap:14px; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; }
.json-view-form { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
.json-view-form label { font-weight:700; display:block; width:100%; }
.json-view-form select { min-width: min(760px, 82vw); padding:10px; border-radius:10px; border:1px solid rgba(0,0,0,.18); }
.json-view-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:14px; }
.json-view-table { width:100%; border-collapse:collapse; font-size:14px; }
.json-view-table th, .json-view-table td { border-bottom:1px solid rgba(0,0,0,.1); padding:8px 10px; text-align:left; vertical-align:top; }
.json-view-table th { width:38%; background:rgba(0,0,0,.035); font-weight:700; }
.json-view-table.wide th { width:auto; white-space:nowrap; }
.json-view-table.wide td { min-width:80px; }
.compact-text { font-size:12px; }
.table-scroll { overflow:auto; max-width:100%; }
.json-model-grid { margin-top:12px; max-width:900px; }
.json-warning-list { margin:0; padding-left:1.4em; }
.json-warning-list li { margin:.35em 0; }
.json-raw-text { width:100%; box-sizing:border-box; margin-top:8px; font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:12px; line-height:1.45; white-space:pre; }
details { margin:12px 0; }
details summary { cursor:pointer; font-weight:700; }
code { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:.95em; }
@media (max-width: 700px) { .json-view-form select { min-width:100%; } .json-view-toolbar { display:block; } }
</style>
<?php View::footer(); ?>
