<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
$user = Auth::requireBranchSelected();
$tenantId = (int)$user['tenant_id'];
$pdo = Db::branch();

function pjcmp_decode_json(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function pjcmp_extract_output_text(array $raw): string
{
    $chunks = [];
    foreach ((array)($raw['output'] ?? []) as $message) {
        if (!is_array($message)) {
            continue;
        }
        foreach ((array)($message['content'] ?? []) as $content) {
            if (is_array($content) && ($content['type'] ?? '') === 'output_text') {
                $chunks[] = (string)($content['text'] ?? '');
            }
        }
    }
    if (!$chunks && isset($raw['choices'][0]['message']['content'])) {
        $chunks[] = (string)$raw['choices'][0]['message']['content'];
    }
    return trim(implode("\n", array_filter($chunks, static fn(string $v): bool => trim($v) !== '')));
}

function pjcmp_extract_json_from_text(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/', '', $text) ?? $text;
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $maybe = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($maybe, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function pjcmp_value_at(array $data, string $path): mixed
{
    $cur = $data;
    foreach (explode('.', $path) as $part) {
        if ($part === '') {
            continue;
        }
        if (is_array($cur) && array_key_exists($part, $cur)) {
            $cur = $cur[$part];
            continue;
        }
        if (ctype_digit($part) && is_array($cur) && array_key_exists((int)$part, $cur)) {
            $cur = $cur[(int)$part];
            continue;
        }
        return null;
    }
    return $cur;
}

function pjcmp_stringify(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
    return trim((string)$value);
}

function pjcmp_normalize_compare_value(mixed $value): string
{
    $s = pjcmp_stringify($value);
    $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s) ?? $s;
    $s = str_replace(['-', '－', 'ー', '―', '‐', '‑'], '-', $s);
    return mb_strtolower($s, 'UTF-8');
}

function pjcmp_pick(array $data, array $paths): mixed
{
    foreach ($paths as $path) {
        $v = pjcmp_value_at($data, $path);
        if ($v !== null && pjcmp_stringify($v) !== '') {
            return $v;
        }
    }
    return null;
}

function pjcmp_med_count(array $a, array $b): int
{
    $ma = is_array($a['medications'] ?? null) ? count($a['medications']) : 0;
    $mb = is_array($b['medications'] ?? null) ? count($b['medications']) : 0;
    return max($ma, $mb);
}

function pjcmp_build_rows(array $imageJson, array $normalizedJson): array
{
    $fields = [
        ['患者氏名', ['patient.name', 'patient_name'], ['patient.name', 'patient_name']],
        ['患者カナ', ['patient.kana', 'patient_kana'], ['patient.kana', 'patient_kana']],
        ['生年月日', ['patient.birth_date', 'patient_birth_date'], ['patient.birth_date', 'patient_birth_date']],
        ['性別', ['patient.gender', 'patient_gender'], ['patient.gender', 'patient_gender']],
        ['保険者番号', ['insurance.insurance_no', 'insurance_no'], ['insurance.insurance_no', 'insurance_no']],
        ['記号・番号', ['insurance.insured_symbol_number', 'insured_symbol_number'], ['insurance.insured_symbol_number', 'insured_symbol_number']],
        ['負担割合', ['insurance.copay_rate', 'copay_rate'], ['insurance.copay_rate', 'copay_rate']],
        ['交付年月日', ['prescription.issued_on', 'issued_on'], ['prescription.issued_on', 'issued_on']],
        ['使用期限', ['prescription.expires_on', 'expires_on'], ['prescription.expires_on', 'expires_on']],
        ['医療機関コード', ['medical_institution.code', 'medical_institution_code'], ['medical_institution.code', 'medical_institution_code']],
        ['医療機関名', ['medical_institution.name', 'medical_institution_name'], ['medical_institution.name', 'medical_institution_name']],
        ['医師名', ['medical_institution.doctor_name', 'doctor_name'], ['medical_institution.doctor_name', 'doctor_name']],
        ['医療機関電話', ['medical_institution.phone', 'medical_institution_phone'], ['medical_institution.phone', 'medical_institution_phone']],
    ];

    $rows = [];
    foreach ($fields as [$label, $imagePaths, $normPaths]) {
        $imageValue = pjcmp_pick($imageJson, $imagePaths);
        $normValue = pjcmp_pick($normalizedJson, $normPaths);
        $rows[] = pjcmp_row($label, $imageValue, $normValue);
    }

    $medCount = pjcmp_med_count($imageJson, $normalizedJson);
    $medFields = [
        'drug_name' => '薬品名',
        'generic_name' => '一般名',
        'brand_name' => '商品名',
        'raw_drug_text' => '薬品元テキスト',
        'dose_text' => '用量',
        'usage_text' => '用法',
        'days_count' => '日数',
        'amount_text' => '総量',
    ];
    for ($i = 0; $i < $medCount; $i++) {
        foreach ($medFields as $key => $label) {
            $rows[] = pjcmp_row('薬品' . ($i + 1) . ' ' . $label, pjcmp_value_at($imageJson, 'medications.' . $i . '.' . $key), pjcmp_value_at($normalizedJson, 'medications.' . $i . '.' . $key));
        }
    }
    return $rows;
}

function pjcmp_row(string $label, mixed $imageValue, mixed $normalizedValue): array
{
    $a = pjcmp_stringify($imageValue);
    $b = pjcmp_stringify($normalizedValue);
    if ($a === '' && $b === '') {
        $result = 'empty';
    } elseif (pjcmp_normalize_compare_value($a) === pjcmp_normalize_compare_value($b)) {
        $result = 'match';
    } elseif ($a === '') {
        $result = 'added_after';
    } elseif ($b === '') {
        $result = 'lost_after';
    } else {
        $result = 'changed';
    }
    return ['label' => $label, 'image_value' => $a, 'normalized_value' => $b, 'result' => $result];
}

function pjcmp_flatten(array $data, string $prefix = ''): array
{
    $out = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
        if (is_array($value)) {
            $out += pjcmp_flatten($value, $path);
        } else {
            $out[$path] = pjcmp_stringify($value);
        }
    }
    return $out;
}


function pjcmp_group_definitions(array $imageJson, array $normalizedJson): array
{
    $groups = [
        '患者情報' => [
            ['患者氏名', ['patient.name', 'patient_name']],
            ['フリガナ', ['patient.kana', 'patient_kana']],
            ['生年月日', ['patient.birth_date', 'patient_birth_date']],
            ['性別', ['patient.gender', 'patient_gender']],
            ['区分', ['patient.category', 'patient_category']],
        ],
        '保険情報' => [
            ['保険者番号', ['insurance.insurance_no', 'insurance_no']],
            ['記号・番号', ['insurance.insured_symbol_number', 'insured_symbol_number']],
            ['負担割合', ['insurance.copay_rate', 'copay_rate']],
        ],
        '公費情報' => [
            ['公費負担者番号', ['public_expense.payer_number', 'public_expense_payer_number', 'public_expense_beneficiary_number']],
            ['公費受給者番号', ['public_expense.beneficiary_number', 'public_expense_beneficiary_number', 'public_expense_medical_beneficiary_number']],
        ],
        '処方箋情報' => [
            ['交付年月日', ['prescription.issued_on', 'issued_on']],
            ['使用期限', ['prescription.expires_on', 'expires_on']],
            ['変更不可', ['prescription.change_not_allowed', 'change_not_allowed', 'no_changes_needed']],
            ['備考', ['prescription.remarks', 'remarks']],
        ],
        '医療機関情報' => [
            ['医療機関コード', ['medical_institution.code', 'medical_institution_code']],
            ['医療機関名', ['medical_institution.name', 'medical_institution_name']],
            ['所在地', ['medical_institution.address', 'medical_institution_address']],
            ['医師名', ['medical_institution.doctor_name', 'doctor_name']],
            ['電話番号', ['medical_institution.phone', 'medical_institution_phone', 'phone']],
            ['都道府県番号', ['medical_institution.prefecture_number', 'prefecture_number']],
            ['点数表番号', ['medical_institution.medical_fee_table_number', 'medical_fee_table_number']],
        ],
    ];

    $medCount = pjcmp_med_count($imageJson, $normalizedJson);
    for ($i = 0; $i < $medCount; $i++) {
        $base = 'medications.' . $i . '.';
        $groups['薬品' . ($i + 1)] = [
            ['薬品名', [$base . 'drug_name']],
            ['一般名', [$base . 'generic_name']],
            ['商品名', [$base . 'brand_name']],
            ['元テキスト', [$base . 'raw_drug_text']],
            ['用量', [$base . 'dose_text']],
            ['用法', [$base . 'usage_text']],
            ['日数', [$base . 'days_count']],
            ['総量', [$base . 'amount_text']],
        ];
    }

    return $groups;
}

function pjcmp_build_grouped_rows(array $imageJson, array $normalizedJson): array
{
    $out = [];
    foreach (pjcmp_group_definitions($imageJson, $normalizedJson) as $groupName => $fields) {
        $rows = [];
        foreach ($fields as [$label, $paths]) {
            $rows[] = pjcmp_row($label, pjcmp_pick($imageJson, $paths), pjcmp_pick($normalizedJson, $paths));
        }
        $out[$groupName] = $rows;
    }
    return $out;
}

function pjcmp_field_lookup(array $rows, string $key): array
{
    foreach ($rows as $row) {
        if (($row['label'] ?? '') === $key) {
            return $row;
        }
    }
    return ['label' => $key, 'image_value' => '', 'normalized_value' => '', 'result' => 'empty'];
}

function pjcmp_result_class(string $result): string
{
    return match ($result) {
        'match' => 'ok',
        'changed' => 'changed',
        'added_after' => 'added',
        'lost_after' => 'lost',
        default => 'empty',
    };
}

function pjcmp_result_label(string $result): string
{
    return match ($result) {
        'match' => '一致',
        'changed' => '変更あり',
        'added_after' => '後段で追加',
        'lost_after' => '後段で消失',
        'empty' => '空欄',
        default => $result,
    };
}

$stmt = $pdo->prepare('SELECT id, prescription_id, status, model_name, overall_confidence, error_message, created_at, analyzed_at, updated_at FROM prescription_parse_jobs WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 80');
$stmt->execute([':tenant_id' => $tenantId]);
$jobs = $stmt->fetchAll();
$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId <= 0 && !empty($jobs[0]['id'])) {
    $jobId = (int)$jobs[0]['id'];
}
$stmt = $pdo->prepare('SELECT * FROM prescription_parse_jobs WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
$stmt->execute([':tenant_id' => $tenantId, ':id' => $jobId]);
$job = $stmt->fetch();

if (!$job) {
    View::header('画像読取JSON比較');
    echo '<section class="page-title"><h1>画像読取JSON比較</h1><p>比較できる読取ジョブがありません。</p></section>';
    View::footer();
    exit;
}

$raw = pjcmp_decode_json($job['raw_response_json'] ?? '');
$outputText = pjcmp_extract_output_text($raw);
$imageJson = pjcmp_extract_json_from_text($outputText);
$normalizedJson = pjcmp_decode_json($job['normalized_json'] ?? '');
$rows = pjcmp_build_rows($imageJson, $normalizedJson);
$groupedRows = pjcmp_build_grouped_rows($imageJson, $normalizedJson);
$counts = ['match' => 0, 'changed' => 0, 'added_after' => 0, 'lost_after' => 0, 'empty' => 0];
foreach ($rows as $r) {
    $counts[$r['result']] = ($counts[$r['result']] ?? 0) + 1;
}
$rawModel = (string)($raw['model'] ?? '');
$usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];
$flatImage = pjcmp_flatten($imageJson);
$flatNormalized = pjcmp_flatten($normalizedJson);

View::header('画像読取JSON比較');
?>
<section class="page-title with-back">
  <a class="back-link" href="<?= h(app_url('/prescription_json_viewer.php?job_id=' . (string)$jobId)) ?>">←</a>
  <div>
    <h1>画像読取JSON比較</h1>
    <p>処方箋画像1枚の読取ジョブごとに、画像読み込み直後のAI出力と、変換後にDBへ保存された正規化JSONを分けて比較します。</p>
  </div>
</section>

<section class="card pjcmp-toolbar">
  <form method="get" class="pjcmp-form">
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
    <a class="btn ghost" href="<?= h(app_url('/prescription_json_viewer.php?job_id=' . (string)$jobId)) ?>">JSON確認</a>
    <a class="btn ghost" href="<?= h(app_url('/prescription_io_debug.php?job_id=' . (string)$jobId)) ?>">IO診断</a>
  </div>
</section>

<section class="card">
  <h2>読取情報</h2>
  <div class="summary-grid compact pjcmp-summary">
    <div><span>ジョブID</span><strong>#<?= h((string)$jobId) ?></strong></div>
    <div><span>状態</span><strong><?= h((string)($job['status'] ?? '')) ?></strong></div>
    <div><span>job.model_name</span><strong><?= h((string)($job['model_name'] ?? '')) ?></strong></div>
    <div><span>raw.model</span><strong><?= h($rawModel !== '' ? $rawModel : '不明') ?></strong></div>
    <div><span>解析日時</span><strong><?= h((string)($job['analyzed_at'] ?? $job['created_at'] ?? '')) ?></strong></div>
    <div><span>input_tokens</span><strong><?= h((string)($usage['input_tokens'] ?? '')) ?></strong></div>
    <div><span>output_tokens</span><strong><?= h((string)($usage['output_tokens'] ?? '')) ?></strong></div>
    <div><span>total_tokens</span><strong><?= h((string)($usage['total_tokens'] ?? '')) ?></strong></div>
  </div>
</section>

<section class="card">
  <h2>比較サマリー</h2>
  <div class="summary-grid compact pjcmp-summary">
    <div><span>一致</span><strong><?= h((string)$counts['match']) ?></strong></div>
    <div><span>変更あり</span><strong><?= h((string)$counts['changed']) ?></strong></div>
    <div><span>後段で追加</span><strong><?= h((string)$counts['added_after']) ?></strong></div>
    <div><span>後段で消失</span><strong><?= h((string)$counts['lost_after']) ?></strong></div>
    <div><span>両方空欄</span><strong><?= h((string)$counts['empty']) ?></strong></div>
  </div>
  <p class="muted small">「画像読取JSON」はOpenAI生レスポンス内の output_text をJSON化したものです。「正規化JSON」は prescription_parse_jobs.normalized_json です。</p>
</section>


<section class="card">
  <h2>データ別まとめ</h2>
  <p class="muted small">左が「画像読み込み後すぐのAI出力を正規化したもの」、右が「読み取ったデータを変換してDBへ保存した後の normalized_json」です。ここで右だけ変わっていれば、AI読取後の変換・補正側で変化しています。</p>
  <?php foreach ($groupedRows as $groupName => $groupRows): ?>
    <section class="pjcmp-group-block">
      <h3><?= h((string)$groupName) ?></h3>
      <div class="table-scroll">
        <table class="pjcmp-table pjcmp-group-table">
          <thead>
            <tr>
              <th>項目</th>
              <th>画像読み込み後すぐ<br><small>AI出力を正規化</small></th>
              <th>変換後<br><small>DB normalized_json</small></th>
              <th>判定</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groupRows as $row): ?>
              <tr class="pjcmp-<?= h((string)$row['result']) ?>">
                <th><?= h((string)$row['label']) ?></th>
                <td><?= h((string)$row['image_value']) ?></td>
                <td><?= h((string)$row['normalized_value']) ?></td>
                <td><span class="pjcmp-badge <?= h(pjcmp_result_class((string)$row['result'])) ?>"><?= h(pjcmp_result_label((string)$row['result'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>主要項目比較</h2>
  <?php if (!$imageJson): ?><div class="alert warning">画像読取JSONを抽出できませんでした。失敗ジョブまたはJSON解析失敗の可能性があります。</div><?php endif; ?>
  <?php if (!$normalizedJson): ?><div class="alert warning">正規化JSONがありません。failed のジョブでは空の可能性があります。</div><?php endif; ?>
  <div class="table-scroll">
    <table class="pjcmp-table">
      <thead><tr><th>項目</th><th>画像読取JSON</th><th>正規化JSON</th><th>判定</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr class="pjcmp-<?= h((string)$row['result']) ?>">
            <th><?= h((string)$row['label']) ?></th>
            <td><?= h((string)$row['image_value']) ?></td>
            <td><?= h((string)$row['normalized_value']) ?></td>
            <td><strong><?= h(pjcmp_result_label((string)$row['result'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="pjcmp-grid">
  <article class="card">
    <h2>画像読み込み後すぐのデータ</h2>
    <p class="muted small">AIが画像を見て返した output_text をJSON化したもの。</p>
    <textarea class="pjcmp-json" readonly rows="24" spellcheck="false"><?= h(json_encode($imageJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '') ?></textarea>
  </article>
  <article class="card">
    <h2>変換後のデータ</h2>
    <p class="muted small">システム側で変換・補正してDBに保存された normalized_json。</p>
    <textarea class="pjcmp-json" readonly rows="24" spellcheck="false"><?= h(json_encode($normalizedJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '') ?></textarea>
  </article>
</section>

<section class="card">
  <h2>全パス比較</h2>
  <details>
    <summary>全JSONパスを表示</summary>
    <div class="table-scroll">
      <table class="pjcmp-table compact-text">
        <thead><tr><th>path</th><th>画像読取JSON</th><th>正規化JSON</th></tr></thead>
        <tbody>
        <?php $paths = array_values(array_unique(array_merge(array_keys($flatImage), array_keys($flatNormalized)))); sort($paths); ?>
        <?php foreach ($paths as $path): ?>
          <tr>
            <th><code><?= h($path) ?></code></th>
            <td><?= h((string)($flatImage[$path] ?? '')) ?></td>
            <td><?= h((string)($flatNormalized[$path] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
</section>

<style>
.pjcmp-toolbar { display:flex; gap:14px; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; }
.pjcmp-form { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
.pjcmp-form label { font-weight:700; display:block; width:100%; }
.pjcmp-form select { min-width:min(760px,82vw); padding:10px; border-radius:10px; border:1px solid rgba(0,0,0,.18); }
.pjcmp-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:14px; }
.pjcmp-table { width:100%; border-collapse:collapse; font-size:13px; }
.pjcmp-table th, .pjcmp-table td { border-bottom:1px solid rgba(0,0,0,.1); padding:8px 10px; text-align:left; vertical-align:top; }
.pjcmp-table th { background:rgba(0,0,0,.035); white-space:nowrap; }
.pjcmp-table td { min-width:180px; }
.pjcmp-match td:last-child { color:#147a28; }
.pjcmp-changed td:last-child, .pjcmp-lost_after td:last-child { color:#b42318; }
.pjcmp-added_after td:last-child { color:#9a6700; }
.pjcmp-empty { opacity:.62; }
.pjcmp-json { width:100%; box-sizing:border-box; font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:12px; line-height:1.45; white-space:pre; }
.table-scroll { overflow:auto; max-width:100%; }
.compact-text { font-size:12px; }
.muted.small { color:#667085; font-size:13px; }
code { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; }

.pjcmp-group-block { margin-top:18px; }
.pjcmp-group-block h3 { margin:0 0 8px; font-size:17px; }
.pjcmp-group-table th:first-child { min-width:150px; }
.pjcmp-badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; font-weight:700; background:#eef2f6; color:#344054; white-space:nowrap; }
.pjcmp-badge.ok { background:#ecfdf3; color:#027a48; }
.pjcmp-badge.changed, .pjcmp-badge.lost { background:#fef3f2; color:#b42318; }
.pjcmp-badge.added { background:#fffaeb; color:#b54708; }

@media (max-width:700px){ .pjcmp-toolbar{display:block;} .pjcmp-form select{min-width:100%;} }
</style>
<?php View::footer(); ?>
