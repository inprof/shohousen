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

function pjcmp_stage_snapshot_value(array $snapshot): mixed
{
    $json = pjcmp_decode_json((string)($snapshot['snapshot_json'] ?? ''));
    if ($json) {
        return $json;
    }
    $text = trim((string)($snapshot['snapshot_text'] ?? ''));
    if ($text !== '') {
        $jsonFromText = pjcmp_extract_json_from_text($text);
        return $jsonFromText ?: $text;
    }
    return null;
}

function pjcmp_table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function pjcmp_fetch_snapshots(PDO $pdo, int $tenantId, int $jobId): array
{
    if (!pjcmp_table_exists($pdo, 'prescription_io_debug_snapshots')) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT stage, stage_label, model_name, content_type, snapshot_json, snapshot_text, created_at FROM prescription_io_debug_snapshots WHERE tenant_id = :tenant_id AND parse_job_id = :job_id ORDER BY id ASC');
        $stmt->execute([':tenant_id' => $tenantId, ':job_id' => $jobId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $stage = (string)($row['stage'] ?? '');
        if ($stage !== '') {
            $out[$stage] = $row;
        }
    }
    return $out;
}

function pjcmp_pick_snapshot(array $snapshots, array $stageNames): ?array
{
    foreach ($stageNames as $stageName) {
        if (isset($snapshots[$stageName])) {
            return $snapshots[$stageName];
        }
    }
    return null;
}

function pjcmp_extract_text_value(array $data, array $keys): string
{
    foreach ($keys as $key) {
        $v = pjcmp_value_at($data, $key);
        if (pjcmp_stringify($v) !== '') {
            return pjcmp_stringify($v);
        }
    }
    foreach (['ocr_raw_text', 'raw_text', 'recognized_text', 'visual_text', 'transcription', 'raw_transcription', 'full_text', 'text'] as $key) {
        $v = pjcmp_value_at($data, $key);
        if (pjcmp_stringify($v) !== '') {
            return pjcmp_stringify($v);
        }
    }
    return '';
}

function pjcmp_text_lines_for_label(string $text, array $labels): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $lines = preg_split('/\R/u', $text) ?: [];
    $hits = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        foreach ($labels as $label) {
            if ($label !== '' && mb_stripos($line, $label, 0, 'UTF-8') !== false) {
                $hits[] = $line;
                break;
            }
        }
        if (count($hits) >= 3) {
            break;
        }
    }
    return implode("\n", $hits);
}

function pjcmp_array_list_candidates(array $data): array
{
    $candidates = [];
    foreach (['display_fields', 'form_fields', 'fields', 'output_fields', 'items'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $candidates[] = $data[$key];
        }
    }
    foreach ($data as $value) {
        if (is_array($value)) {
            foreach (['display_fields', 'form_fields', 'fields', 'output_fields', 'items'] as $key) {
                if (isset($value[$key]) && is_array($value[$key])) {
                    $candidates[] = $value[$key];
                }
            }
        }
    }
    return $candidates;
}

function pjcmp_find_field_value(array $data, array $paths, string $label = ''): string
{
    $direct = pjcmp_pick($data, $paths);
    if (pjcmp_stringify($direct) !== '') {
        return pjcmp_stringify($direct);
    }

    $keys = [];
    foreach ($paths as $path) {
        $parts = explode('.', $path);
        $keys[] = end($parts) ?: $path;
        $keys[] = str_replace('.', '_', $path);
    }
    $keys = array_values(array_unique(array_filter($keys, static fn(string $v): bool => $v !== '')));

    foreach (pjcmp_array_list_candidates($data) as $list) {
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $fieldKey = (string)($item['field_key'] ?? $item['key'] ?? $item['output_field_key'] ?? '');
            $fieldLabel = (string)($item['field_label'] ?? $item['label'] ?? $item['output_label'] ?? '');
            $keyMatch = $fieldKey !== '' && in_array($fieldKey, $keys, true);
            $labelMatch = $label !== '' && $fieldLabel !== '' && ($fieldLabel === $label || mb_stripos($fieldLabel, $label, 0, 'UTF-8') !== false || mb_stripos($label, $fieldLabel, 0, 'UTF-8') !== false);
            if (!$keyMatch && !$labelMatch) {
                continue;
            }
            foreach (['value', 'final_value', 'ai_value', 'field_value', 'display_value', 'text'] as $valueKey) {
                if (array_key_exists($valueKey, $item) && pjcmp_stringify($item[$valueKey]) !== '') {
                    return pjcmp_stringify($item[$valueKey]);
                }
            }
        }
    }
    return '';
}

function pjcmp_med_count(array ...$datasets): int
{
    $count = 0;
    foreach ($datasets as $data) {
        if (is_array($data['medications'] ?? null)) {
            $count = max($count, count($data['medications']));
        }
    }
    return $count;
}

function pjcmp_group_definitions(array ...$datasets): array
{
    $groups = [
        '患者情報' => [
            ['患者氏名', ['氏名', '患者氏名'], ['patient.name', 'patient_name', 'name']],
            ['フリガナ', ['フリガナ', 'カナ'], ['patient.kana', 'patient_kana', 'kana']],
            ['生年月日', ['生年月日'], ['patient.birth_date', 'patient_birth_date', 'birth_date']],
            ['性別', ['性別'], ['patient.gender', 'patient_gender', 'gender']],
            ['区分', ['区分'], ['patient.category', 'patient_category']],
        ],
        '保険情報' => [
            ['保険者番号', ['保険者番号'], ['insurance.insurance_no', 'insurance_no']],
            ['記号・番号', ['記号', '番号', '被保険者'], ['insurance.insured_symbol_number', 'insured_symbol_number']],
            ['負担割合', ['負担割合'], ['insurance.copay_rate', 'copay_rate']],
        ],
        '公費情報' => [
            ['公費負担者番号', ['公費負担者番号'], ['public_expense.payer_number', 'public_expense_payer_number', 'public_expense_beneficiary_number']],
            ['公費受給者番号', ['公費負担医療の受給者番号', '受給者番号'], ['public_expense.beneficiary_number', 'public_expense_beneficiary_number', 'public_expense_medical_beneficiary_number']],
        ],
        '処方箋情報' => [
            ['交付年月日', ['交付年月日'], ['prescription.issued_on', 'issued_on']],
            ['使用期限', ['使用期間', '使用期限'], ['prescription.expires_on', 'expires_on']],
            ['変更不可', ['変更不可'], ['prescription.change_not_allowed', 'change_not_allowed', 'no_changes_needed']],
            ['備考', ['備考'], ['prescription.remarks', 'remarks']],
        ],
        '医療機関情報' => [
            ['医療機関コード', ['医療機関コード'], ['medical_institution.code', 'medical_institution_code']],
            ['医療機関名', ['医療機関名', '保険医療機関'], ['medical_institution.name', 'medical_institution_name']],
            ['所在地', ['所在地'], ['medical_institution.address', 'medical_institution_address']],
            ['医師名', ['医師名', '保険医氏名'], ['medical_institution.doctor_name', 'doctor_name']],
            ['電話番号', ['電話番号'], ['medical_institution.phone', 'medical_institution_phone', 'phone']],
            ['都道府県番号', ['都道府県番号'], ['medical_institution.prefecture_number', 'prefecture_number']],
            ['点数表番号', ['点数表番号'], ['medical_institution.medical_fee_table_number', 'medical_fee_table_number']],
        ],
    ];

    $medCount = pjcmp_med_count(...$datasets);
    for ($i = 0; $i < $medCount; $i++) {
        $base = 'medications.' . $i . '.';
        $groups['薬品' . ($i + 1)] = [
            ['薬品名', ['薬品名', '薬剤名'], [$base . 'drug_name']],
            ['一般名', ['一般名'], [$base . 'generic_name']],
            ['商品名', ['商品名'], [$base . 'brand_name']],
            ['元テキスト', ['処方', 'Rp', '薬品'], [$base . 'raw_drug_text']],
            ['用量', ['用量'], [$base . 'dose_text']],
            ['用法', ['用法'], [$base . 'usage_text']],
            ['日数', ['日数', '日分'], [$base . 'days_count']],
            ['総量', ['総量'], [$base . 'amount_text']],
        ];
    }
    return $groups;
}

function pjcmp_build_four_stage_rows(string $rawText, array $structuredJson, array $itemJson, array $outputJson): array
{
    $out = [];
    foreach (pjcmp_group_definitions($structuredJson, $itemJson, $outputJson) as $groupName => $fields) {
        $rows = [];
        foreach ($fields as [$label, $textLabels, $paths]) {
            $ocrTextValue = pjcmp_text_lines_for_label($rawText, $textLabels);
            $structuredValue = pjcmp_find_field_value($structuredJson, $paths, $label);
            $itemValue = pjcmp_find_field_value($itemJson, $paths, $label);
            $outputValue = pjcmp_find_field_value($outputJson, $paths, $label);
            $rows[] = [
                'label' => $label,
                'ocr_text' => $ocrTextValue,
                'structured' => $structuredValue,
                'item' => $itemValue,
                'output' => $outputValue,
                'result' => pjcmp_stage_result($itemValue, $outputValue),
            ];
        }
        $out[$groupName] = $rows;
    }
    return $out;
}

function pjcmp_stage_result(string $itemValue, string $outputValue): string
{
    if ($itemValue === '' && $outputValue === '') {
        return 'empty';
    }
    if ($itemValue === '') {
        return 'added_output';
    }
    if ($outputValue === '') {
        return 'lost_output';
    }
    if (pjcmp_normalize_compare_value($itemValue) === pjcmp_normalize_compare_value($outputValue)) {
        return 'match';
    }
    return 'changed_output';
}

function pjcmp_result_class(string $result): string
{
    return match ($result) {
        'match' => 'ok',
        'changed_output' => 'changed',
        'added_output' => 'added',
        'lost_output' => 'lost',
        'not_available' => 'empty',
        default => 'empty',
    };
}

function pjcmp_result_label(string $result): string
{
    return match ($result) {
        'match' => '項目→書き出し一致',
        'changed_output' => '書き出しで変更',
        'added_output' => '書き出しで追加',
        'lost_output' => '書き出しで消失',
        'empty' => '空欄',
        'not_available' => '比較不可',
        default => $result,
    };
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

function pjcmp_pretty(mixed $value): string
{
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
    }
    return (string)$value;
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
    View::header('4段階読取比較');
    echo '<section class="page-title"><h1>4段階読取比較</h1><p>比較できる読取ジョブがありません。</p></section>';
    View::footer();
    exit;
}

$raw = pjcmp_decode_json($job['raw_response_json'] ?? '');
$outputText = pjcmp_extract_output_text($raw);
$openAiJson = pjcmp_extract_json_from_text($outputText);
$normalizedJson = pjcmp_decode_json($job['normalized_json'] ?? '');
$snapshots = pjcmp_fetch_snapshots($pdo, $tenantId, $jobId);

$rawTextSnapshot = pjcmp_pick_snapshot($snapshots, ['ocr_raw_text', 'visual_ocr_text', 'image_ocr_raw_text', 'openai_ocr_raw_text']);
$structuredSnapshot = pjcmp_pick_snapshot($snapshots, ['ocr_structured_json', 'visual_ocr_json', 'image_ocr_structured_json', 'openai_ocr_structured_json']);
$itemSnapshot = pjcmp_pick_snapshot($snapshots, ['prescription_item_json', 'openai_normalized', 'openai_normalized_before_correction', 'normalized_after_correction']);
$outputSnapshot = pjcmp_pick_snapshot($snapshots, ['display_output_data', 'ai_rule_mapped_display', 'mapped_display', 'db_saved_prescription', 'qr_payload']);

$stage1Value = $rawTextSnapshot ? pjcmp_stage_snapshot_value($rawTextSnapshot) : null;
$stage1Text = is_array($stage1Value) ? pjcmp_extract_text_value($stage1Value, []) : trim((string)$stage1Value);
$stage1Source = $rawTextSnapshot ? (string)$rawTextSnapshot['stage'] : '未保存';
if ($stage1Text === '' && $outputText !== '' && !pjcmp_extract_json_from_text($outputText)) {
    $stage1Text = $outputText;
    $stage1Source = 'raw_response.output_text';
}

$stage2Value = $structuredSnapshot ? pjcmp_stage_snapshot_value($structuredSnapshot) : null;
$stage2Json = is_array($stage2Value) ? $stage2Value : [];
$stage2Source = $structuredSnapshot ? (string)$structuredSnapshot['stage'] : '未保存';

$stage3Value = $itemSnapshot ? pjcmp_stage_snapshot_value($itemSnapshot) : null;
$stage3Json = is_array($stage3Value) ? $stage3Value : ($openAiJson ?: []);
$stage3Source = $itemSnapshot ? (string)$itemSnapshot['stage'] : ($openAiJson ? 'raw_response.output_text(JSON)' : '未保存');

$stage4Value = $outputSnapshot ? pjcmp_stage_snapshot_value($outputSnapshot) : null;
$stage4Json = is_array($stage4Value) ? $stage4Value : $normalizedJson;
$stage4Source = $outputSnapshot ? (string)$outputSnapshot['stage'] : ($normalizedJson ? 'prescription_parse_jobs.normalized_json' : '未保存');

$rowsByGroup = pjcmp_build_four_stage_rows($stage1Text, $stage2Json, $stage3Json, $stage4Json);
$counts = ['match' => 0, 'changed_output' => 0, 'added_output' => 0, 'lost_output' => 0, 'empty' => 0];
foreach ($rowsByGroup as $rows) {
    foreach ($rows as $row) {
        $counts[$row['result']] = ($counts[$row['result']] ?? 0) + 1;
    }
}
$rawModel = (string)($raw['model'] ?? '');
$usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];
$flatStage3 = pjcmp_flatten($stage3Json);
$flatStage4 = pjcmp_flatten($stage4Json);

View::header('4段階読取比較');
?>
<section class="page-title with-back">
  <a class="back-link" href="<?= h(app_url('/prescription_json_viewer.php?job_id=' . (string)$jobId)) ?>">←</a>
  <div>
    <h1>4段階読取比較</h1>
    <p>処方箋画像1枚の読取結果を「OCR生テキスト」「OCR構造JSON」「処方箋項目JSON」「表示・出力用データ」に分けて確認します。</p>
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

<?php if ($stage1Source === '未保存' || $stage2Source === '未保存'): ?>
<section class="alert warning pjcmp-alert">
  現在の過去ジョブには「OCR生テキスト」または「OCR構造JSON」が未保存のものがあります。未保存の段階は空欄で表示します。今後、OCR専用ステージを保存する実装を入れると、この画面にそのまま表示されます。
</section>
<?php endif; ?>

<section class="card">
  <h2>4段階の保存元</h2>
  <div class="pjcmp-stage-grid">
    <article class="pjcmp-stage-card <?= $stage1Text !== '' ? 'available' : 'missing' ?>">
      <strong>1. OCR生テキスト</strong>
      <span><?= h($stage1Source) ?></span>
      <small>画像から見えた文字をなるべくそのまま起こしたデータ。</small>
    </article>
    <article class="pjcmp-stage-card <?= $stage2Json ? 'available' : 'missing' ?>">
      <strong>2. OCR構造JSON</strong>
      <span><?= h($stage2Source) ?></span>
      <small>行・ブロック・位置・信頼度など、文字起こしを構造化したデータ。</small>
    </article>
    <article class="pjcmp-stage-card <?= $stage3Json ? 'available' : 'missing' ?>">
      <strong>3. 処方箋項目JSON</strong>
      <span><?= h($stage3Source) ?></span>
      <small>患者・保険・医療機関・薬品などに項目分けしたデータ。</small>
    </article>
    <article class="pjcmp-stage-card <?= $stage4Json ? 'available' : 'missing' ?>">
      <strong>4. 表示・出力用データ</strong>
      <span><?= h($stage4Source) ?></span>
      <small>画面表示、修正フォーム、QR/JAHIS用に書き出すためのデータ。</small>
    </article>
  </div>
</section>

<section class="card">
  <h2>比較サマリー</h2>
  <div class="summary-grid compact pjcmp-summary">
    <div><span>項目→書き出し一致</span><strong><?= h((string)$counts['match']) ?></strong></div>
    <div><span>書き出しで変更</span><strong><?= h((string)$counts['changed_output']) ?></strong></div>
    <div><span>書き出しで追加</span><strong><?= h((string)$counts['added_output']) ?></strong></div>
    <div><span>書き出しで消失</span><strong><?= h((string)$counts['lost_output']) ?></strong></div>
    <div><span>空欄</span><strong><?= h((string)$counts['empty']) ?></strong></div>
  </div>
  <p class="muted small">判定は主に「3. 処方箋項目JSON」と「4. 表示・出力用データ」の差分で出しています。OCR生テキストが保存されている場合は、該当行の候補文字列も表示します。</p>
</section>

<section class="card">
  <h2>4段階比較</h2>
  <?php foreach ($rowsByGroup as $groupName => $groupRows): ?>
    <section class="pjcmp-group-block">
      <h3><?= h((string)$groupName) ?></h3>
      <div class="table-scroll">
        <table class="pjcmp-table pjcmp-group-table">
          <thead>
            <tr>
              <th>項目</th>
              <th>1. OCR生テキスト</th>
              <th>2. OCR構造JSON</th>
              <th>3. 処方箋項目JSON</th>
              <th>4. 表示・出力用データ</th>
              <th>判定</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groupRows as $row): ?>
              <tr class="pjcmp-<?= h((string)$row['result']) ?>">
                <th><?= h((string)$row['label']) ?></th>
                <td><?= nl2br(h((string)$row['ocr_text'])) ?></td>
                <td><?= h((string)$row['structured']) ?></td>
                <td><?= h((string)$row['item']) ?></td>
                <td><?= h((string)$row['output']) ?></td>
                <td><span class="pjcmp-badge <?= h(pjcmp_result_class((string)$row['result'])) ?>"><?= h(pjcmp_result_label((string)$row['result'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endforeach; ?>
</section>

<section class="pjcmp-grid four">
  <article class="card">
    <h2>1. OCR生テキスト</h2>
    <p class="muted small">保存元: <?= h($stage1Source) ?></p>
    <textarea class="pjcmp-json" readonly rows="22" spellcheck="false"><?= h($stage1Text) ?></textarea>
  </article>
  <article class="card">
    <h2>2. OCR構造JSON</h2>
    <p class="muted small">保存元: <?= h($stage2Source) ?></p>
    <textarea class="pjcmp-json" readonly rows="22" spellcheck="false"><?= h(pjcmp_pretty($stage2Json)) ?></textarea>
  </article>
  <article class="card">
    <h2>3. 処方箋項目JSON</h2>
    <p class="muted small">保存元: <?= h($stage3Source) ?></p>
    <textarea class="pjcmp-json" readonly rows="22" spellcheck="false"><?= h(pjcmp_pretty($stage3Json)) ?></textarea>
  </article>
  <article class="card">
    <h2>4. 表示・出力用データ</h2>
    <p class="muted small">保存元: <?= h($stage4Source) ?></p>
    <textarea class="pjcmp-json" readonly rows="22" spellcheck="false"><?= h(pjcmp_pretty($stage4Json)) ?></textarea>
  </article>
</section>

<section class="card">
  <h2>項目JSON → 表示・出力用データ 全パス比較</h2>
  <details>
    <summary>全JSONパスを表示</summary>
    <div class="table-scroll">
      <table class="pjcmp-table compact-text">
        <thead><tr><th>path</th><th>3. 処方箋項目JSON</th><th>4. 表示・出力用データ</th></tr></thead>
        <tbody>
        <?php $paths = array_values(array_unique(array_merge(array_keys($flatStage3), array_keys($flatStage4)))); sort($paths); ?>
        <?php foreach ($paths as $path): ?>
          <tr>
            <th><code><?= h($path) ?></code></th>
            <td><?= h((string)($flatStage3[$path] ?? '')) ?></td>
            <td><?= h((string)($flatStage4[$path] ?? '')) ?></td>
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
.pjcmp-alert { margin:14px 0; }
.pjcmp-stage-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
.pjcmp-stage-card { border:1px solid rgba(0,0,0,.12); border-radius:12px; padding:12px; background:#fff; display:flex; flex-direction:column; gap:6px; }
.pjcmp-stage-card strong { font-size:15px; }
.pjcmp-stage-card span { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:12px; color:#475467; word-break:break-all; }
.pjcmp-stage-card small { color:#667085; line-height:1.45; }
.pjcmp-stage-card.available { border-color:rgba(18,128,61,.35); background:#f6fef9; }
.pjcmp-stage-card.missing { border-color:rgba(180,35,24,.28); background:#fffbfa; }
.pjcmp-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:14px; }
.pjcmp-grid.four { grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); }
.pjcmp-table { width:100%; border-collapse:collapse; font-size:13px; }
.pjcmp-table th, .pjcmp-table td { border-bottom:1px solid rgba(0,0,0,.1); padding:8px 10px; text-align:left; vertical-align:top; }
.pjcmp-table th { background:rgba(0,0,0,.035); white-space:nowrap; }
.pjcmp-table td { min-width:160px; }
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
