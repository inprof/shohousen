<?php
declare(strict_types=1);

function enabled_features(int $tenantId, ?int $locationId = null): array
{
    $pdo = Db::admin();
    if ($locationId !== null && Db::tableExists($pdo, 'location_features')) {
        $sql = 'SELECT f.*
                FROM location_features lf
                INNER JOIN features f ON f.id = lf.feature_id
                WHERE lf.location_id = :location_id AND lf.is_enabled = 1 AND f.is_active = 1
                ORDER BY f.sort_order, f.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':location_id' => $locationId]);
        $rows = $stmt->fetchAll();
        if ($rows) {
            return $rows;
        }
    }

    // 会社/拠点の機能割当が未設定でも、MVPでメニューが空にならないように active features を表示する。
    $stmt = $pdo->query('SELECT * FROM features WHERE is_active = 1 ORDER BY sort_order, id');
    return $stmt->fetchAll();
}

function demo_extracted_prescription(): array
{
    return [
        'patient_name' => '山田 花子',
        'gender' => '女性',
        'birth_date' => '1975-04-12',
        'insurance_no' => '12345678',
        'insured_symbol_number' => '987654321',
        'copay_rate' => '3割',
        'issued_on' => '2024-05-20',
        'medical_institution_code' => '1312345',
        'medical_institution_name' => 'さくらクリニック',
        'ai_confidence' => '94.20',
        'medications' => [
            ['drug_name' => 'アムロジピンOD錠5mg', 'usage_text' => '1日1回 朝食後', 'days_count' => '28', 'stock_status' => 'adopted'],
            ['drug_name' => 'ムコソルバン錠15mg', 'usage_text' => '1日3回 毎食後', 'days_count' => '28', 'stock_status' => 'in_stock'],
            ['drug_name' => 'ロキソニン錠60mg', 'usage_text' => '疼痛時 1回1錠', 'days_count' => '10', 'stock_status' => 'low_stock'],
        ],
    ];
}


/**
 * 処方箋上の日付は西暦・和暦が混在するため、DB保存用に YYYY-MM-DD へ正規化する。
 * 変換不能な場合は null を返し、入力値は selected_fields/補助学習側に残す。
 */
function normalize_prescription_date_value(mixed $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    if (class_exists('PrescriptionReferenceRuleService')) {
        $result = PrescriptionReferenceRuleService::normalizeDate($raw);
        return is_string($result['normalized'] ?? null) && $result['normalized'] !== '' ? (string)$result['normalized'] : null;
    }

    // Fallback only. 通常はPrescriptionReferenceRuleServiceの和暦JSONルールを使う。
    $ascii = mb_convert_kana($raw, 'as');
    $ascii = str_replace(['年', '月', '日', '.', '／', '　'], ['/', '/', '', '/', '/', ' '], $ascii);
    $ascii = preg_replace('/\s+/', '', $ascii) ?? $ascii;

    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $ascii, $m) || preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ascii, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    $eraBases = ['明治' => 1867, 'M' => 1867, 'm' => 1867, '大正' => 1911, 'T' => 1911, 't' => 1911, '昭和' => 1925, 'S' => 1925, 's' => 1925, '平成' => 1988, 'H' => 1988, 'h' => 1988, '令和' => 2018, 'R' => 2018, 'r' => 2018];
    if (preg_match('/^(明治|大正|昭和|平成|令和|[MTSHRmtshr])(?:元|(\d{1,2}))[年\/.-]?(\d{1,2})[月\/.-]?(\d{1,2})日?$/u', $ascii, $m)) {
        $era = $m[1];
        $yearInEra = ($m[2] ?? '') === '' ? 1 : (int)$m[2];
        $y = ($eraBases[$era] ?? 0) + $yearInEra;
        $mo = (int)$m[3];
        $d = (int)$m[4];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
    return null;
}

function normalize_prescription_code_value(string $type, mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    if (class_exists('PrescriptionReferenceRuleService')) {
        return PrescriptionReferenceRuleService::normalizedCodeOrRaw($type, $raw);
    }
    return $raw;
}

function find_or_create_patient(int $tenantId, array $input): int
{
    $pdo = Db::branch();
    $name = trim((string)($input['patient_name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('患者名が空です。');
    }
    $birthDate = normalize_prescription_date_value($input['birth_date'] ?? '');
    $stmt = $pdo->prepare('SELECT id FROM patients WHERE name = :name AND birth_date <=> :birth_date LIMIT 1');
    $stmt->execute([':name' => $name, ':birth_date' => $birthDate]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $gender = match ($input['gender'] ?? '') {
        '男性' => 'male',
        '女性' => 'female',
        default => 'unknown',
    };
    $stmt = $pdo->prepare('INSERT INTO patients (name, gender, birth_date) VALUES (:name, :gender, :birth_date)');
    $stmt->execute([':name' => $name, ':gender' => $gender, ':birth_date' => $birthDate]);
    return (int)$pdo->lastInsertId();
}

function find_or_create_medical_institution(int $tenantId, array $input): ?int
{
    $pdo = Db::branch();
    $name = trim((string)($input['medical_institution_name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $code = normalize_prescription_code_value('medical_institution_code', $input['medical_institution_code'] ?? '');

    if ($code !== '') {
        $stmt = $pdo->prepare('SELECT id FROM medical_institutions WHERE institution_code = :code OR name = :name LIMIT 1');
        $stmt->execute([':code' => $code, ':name' => $name]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM medical_institutions WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
    }

    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $stmt = $pdo->prepare('INSERT INTO medical_institutions (institution_code, name) VALUES (:code, :name)');
    $stmt->execute([':code' => $code ?: null, ':name' => $name]);
    return (int)$pdo->lastInsertId();
}



function is_learning_only_prescription_field(string $key, string $label): bool
{
    $text = mb_strtolower($key . ' ' . $label);
    foreach (['raw_drug_text', '薬品名元テキスト', '元テキスト', 'generic_name', '一般名候補', 'brand_name', '商品名候補', 'relation_type', '薬品名の関係', 'drug_name_relation', 'name_relation'] as $needle) {
        if (str_contains($text, mb_strtolower($needle))) {
            return true;
        }
    }
    return false;
}

function normalize_prescription_confidence_percent(mixed $value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $confidence = (float)$value;
    if ($confidence >= 0.0 && $confidence <= 1.0) {
        $confidence *= 100.0;
    }
    return round(max(0.0, min(100.0, $confidence)), 2);
}

/**
 * 解析結果確認画面で「出力・学習対象」に選択された動的項目をPOSTから復元する。
 * 固定帳票ではなく、処方箋ごとに変動する項目/セルもこの配列として保存する。
 *
 * @return array<int,array<string,mixed>>
 */
function selected_prescription_fields_from_post(array $post): array
{
    $keys = $post['dynamic_field_key'] ?? [];
    $labels = $post['dynamic_field_label'] ?? [];
    $groups = $post['dynamic_field_group'] ?? [];
    $values = $post['dynamic_field_value'] ?? [];
    $aiValues = $post['dynamic_field_ai_value'] ?? [];
    $sections = $post['dynamic_field_source_section'] ?? [];
    $confidences = $post['dynamic_field_confidence'] ?? [];
    $selected = $post['dynamic_field_selected'] ?? [];
    $outputCandidates = $post['dynamic_field_output_candidate'] ?? [];
    $needsChecks = $post['dynamic_field_needs_human_check'] ?? [];
    $displayOrders = $post['dynamic_field_display_order'] ?? [];
    $fromReviewScreen = false;

    // 解析結果確認画面では、項目名・分類・順序を拠点ひな型候補へ保存する。
    // original_dynamic_* で送られた項目は、値がある限りひな型学習対象として扱う。
    if (!$keys && !empty($post['original_dynamic_key'])) {
        $fromReviewScreen = true;
        $keys = $post['original_dynamic_key'] ?? [];
        $labels = $post['original_dynamic_label'] ?? [];
        $groups = $post['original_dynamic_group'] ?? [];
        $values = $post['original_dynamic_value'] ?? [];
        $aiValues = $post['original_dynamic_ai_value'] ?? [];
        $sections = $post['original_dynamic_source_section'] ?? [];
        $confidences = $post['original_dynamic_confidence'] ?? [];
        $needsChecks = $post['original_dynamic_needs_human_check'] ?? [];
        $displayOrders = $post['original_dynamic_display_order'] ?? [];
        $selected = [];
        $outputCandidates = [];
    }

    $rows = [];
    foreach ($keys as $i => $key) {
        $key = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim((string)$key)) ?: ('field_' . $i);
        $label = trim((string)($labels[$i] ?? $key));
        $value = trim((string)($values[$i] ?? ''));
        $aiValue = trim((string)($aiValues[$i] ?? ''));
        $group = trim((string)($groups[$i] ?? 'other'));
        if (!in_array($group, ['patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other'], true)) {
            $group = 'other';
        }

        // 薬品名・用法・日数・総量は prescription_medications へ正規保存する。
        // prescription_selected_fields にも保存すると、修正画面と表示/選択画面の値が二重管理になり不整合になる。
        if ($group === 'medication') {
            continue;
        }

        if (is_learning_only_prescription_field($key, $label)) {
            continue;
        }

        $needsHumanCheck = isset($needsChecks[$i]) && (string)$needsChecks[$i] === '1';

        // 使用項目選択画面から来た場合は選択状態を尊重する。
        // 解析結果確認画面から来た場合は、値がある項目をひな型候補として選択済みにする。
        $isSelected = $fromReviewScreen
            ? ($value !== '' || $aiValue !== '' || $needsHumanCheck)
            : (isset($selected[$i]) && (string)$selected[$i] === '1');
        if (!$isSelected && $value === '' && $aiValue === '') {
            continue;
        }
        $includeForOutput = $fromReviewScreen
            ? ($value !== '')
            : ($isSelected && (isset($outputCandidates[$i]) ? (string)$outputCandidates[$i] === '1' : true));

        $rows[] = [
            'field_key' => mb_substr($key, 0, 120),
            'field_label' => mb_substr($label !== '' ? $label : $key, 0, 160),
            'field_group' => $group,
            'field_value' => $value,
            'source_ai_value' => $aiValue,
            'source_section' => mb_substr((string)($sections[$i] ?? ''), 0, 160),
            'confidence' => normalize_prescription_confidence_percent($confidences[$i] ?? null),
            'needs_human_check' => $needsHumanCheck,
            'is_selected' => $isSelected,
            'include_for_output' => $includeForOutput,
            'display_order' => is_numeric($displayOrders[$i] ?? null) ? (int)$displayOrders[$i] : ($i + 1),
        ];
    }

    return $rows;
}

/**
 * 選択された動的項目を拠点DBへ保存する。
 */
function save_prescription_selected_fields(PDO $pdo, int $tenantId, int $prescriptionId, ?int $parseJobId, array $rows): void
{
    if (!$rows || !Db::tableExists($pdo, 'prescription_selected_fields')) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO prescription_selected_fields
        (prescription_id, parse_job_id, company_uid, branch_uid, tenant_id, field_key, field_label, field_group, field_value, source_ai_value, source_section, confidence, needs_human_check, is_selected, include_for_output, display_order, created_at, updated_at)
        VALUES
        (:prescription_id, :parse_job_id, :company_uid, :branch_uid, :tenant_id, :field_key, :field_label, :field_group, :field_value, :source_ai_value, :source_section, :confidence, :needs_human_check, :is_selected, :include_for_output, :display_order, NOW(), NOW())');

    foreach ($rows as $row) {
        $stmt->execute([
            ':prescription_id' => $prescriptionId,
            ':parse_job_id' => $parseJobId,
            ':company_uid' => current_company_uid(),
            ':branch_uid' => current_branch_uid(),
            ':tenant_id' => $tenantId,
            ':field_key' => $row['field_key'],
            ':field_label' => $row['field_label'],
            ':field_group' => $row['field_group'],
            ':field_value' => $row['field_value'],
            ':source_ai_value' => $row['source_ai_value'],
            ':source_section' => $row['source_section'],
            ':confidence' => $row['confidence'],
            ':needs_human_check' => !empty($row['needs_human_check']) ? 1 : 0,
            ':is_selected' => !empty($row['is_selected']) ? 1 : 0,
            ':include_for_output' => !empty($row['include_for_output']) ? 1 : 0,
            ':display_order' => (int)$row['display_order'],
        ]);
    }
}


/**
 * 保存済み処方箋から使用項目選択画面用の行を再構築する。
 * prescription_selected_fields が空の場合でも、患者/保険/医療機関/薬品の保存済みデータから最低限の選択行を復元する。
 *
 * @return array<int,array<string,mixed>>
 */
function default_selected_fields_from_prescription(array $prescription): array
{
    $rows = [];
    $add = static function (string $key, string $label, string $group, mixed $value, mixed $aiValue = null, int $order = 9999) use (&$rows): void {
        $value = trim((string)$value);
        $aiValue = trim((string)($aiValue ?? $value));
        if ($value === '' && $aiValue === '') {
            return;
        }
        $rows[] = [
            'field_key' => mb_substr($key, 0, 120),
            'field_label' => mb_substr($label, 0, 160),
            'field_group' => $group,
            'field_value' => $value,
            'source_ai_value' => $aiValue,
            'source_section' => '保存済みデータから復元',
            'confidence' => null,
            'needs_human_check' => $aiValue !== '' && $value !== '' && $aiValue !== $value,
            'is_selected' => true,
            'include_for_output' => true,
            'display_order' => $order,
        ];
    };

    $add('patient.name', '患者名', 'patient', $prescription['patient_name'] ?? '', null, 10);
    $add('patient.gender', '性別', 'patient', $prescription['gender'] ?? '', null, 20);
    $add('patient.birth_date', '生年月日', 'patient', $prescription['birth_date'] ?? '', null, 30);
    $add('insurance.insurance_no', '保険者番号', 'insurance', $prescription['insurance_no'] ?? '', null, 40);
    $add('insurance.insured_symbol_number', '記号番号', 'insurance', $prescription['insured_symbol_number'] ?? '', null, 50);
    $add('insurance.copay_rate', '負担割合', 'insurance', $prescription['copay_rate'] ?? '', null, 60);
    $add('prescription.issued_on', '処方箋発行日', 'prescription', $prescription['issued_on'] ?? '', null, 70);
    $add('medical_institution.code', '医療機関コード', 'medical_institution', $prescription['institution_code'] ?? '', null, 80);
    $add('medical_institution.name', '医療機関名', 'medical_institution', $prescription['medical_name'] ?? '', null, 90);

    // 処方薬は prescription_medications に保存済みのため、使用項目選択用の動的項目には複製しない。

    return $rows;
}

function ensure_prescription_selected_fields_for_prescription(int $tenantId, int $prescriptionId, array $prescription): array
{
    $existing = (array)($prescription['selected_fields'] ?? []);
    if ($existing) {
        return $existing;
    }
    $rows = default_selected_fields_from_prescription($prescription);
    if ($rows && Db::tableExists(Db::branch(), 'prescription_selected_fields')) {
        save_prescription_selected_fields(Db::branch(), $tenantId, $prescriptionId, isset($prescription['parse_job_id']) ? (int)$prescription['parse_job_id'] : null, $rows);
        $stmt = Db::branch()->prepare('SELECT * FROM prescription_selected_fields WHERE prescription_id = :id ORDER BY display_order, id');
        $stmt->execute([':id' => $prescriptionId]);
        $saved = $stmt->fetchAll();
        if ($saved) {
            return $saved;
        }
    }
    return $rows;
}


/**
 * 選択項目のAI値・人間修正値から、補助学習DBに渡す行を作る。
 * 使用/未使用の判断は拠点運用なので、ここでは学習スコアに含めない。
 *
 * @return array<int,array<string,mixed>>
 */
function correction_learning_rows_from_selected_fields(array $rows): array
{
    return array_map(static function (array $row): array {
        return [
            'field_key' => $row['field_key'] ?? '',
            'field_label' => $row['field_label'] ?? '',
            'field_group' => $row['field_group'] ?? 'other',
            'source_ai_value' => $row['source_ai_value'] ?? '',
            'field_value' => $row['field_value'] ?? '',
            'confidence' => $row['confidence'] ?? null,
            'needs_human_check' => !empty($row['needs_human_check']),
        ];
    }, $rows);
}

/**
 * 使用項目選択画面で選ばれた「使う/使わない」を、既に保存済みの読み取り項目へ反映する。
 */
function update_prescription_selected_fields_from_post(int $tenantId, int $prescriptionId, array $post): void
{
    $pdo = Db::branch();
    if (!Db::tableExists($pdo, 'prescription_selected_fields')) {
        return;
    }

    $ids = $post['dynamic_field_id'] ?? [];
    $keys = $post['dynamic_field_key'] ?? [];
    $values = $post['dynamic_field_value'] ?? [];
    $selected = $post['dynamic_field_selected'] ?? [];
    $outputCandidates = $post['dynamic_field_output_candidate'] ?? [];

    $stmtById = $pdo->prepare('UPDATE prescription_selected_fields
        SET field_value = :field_value,
            is_selected = :is_selected,
            include_for_output = :include_for_output,
            updated_at = NOW()
        WHERE id = :id AND prescription_id = :prescription_id AND tenant_id = :tenant_id');

    $stmtByKey = $pdo->prepare('UPDATE prescription_selected_fields
        SET field_value = :field_value,
            is_selected = :is_selected,
            include_for_output = :include_for_output,
            updated_at = NOW()
        WHERE prescription_id = :prescription_id AND tenant_id = :tenant_id AND field_key = :field_key');

    foreach ($keys as $i => $key) {
        $fieldValue = trim((string)($values[$i] ?? ''));
        $isSelected = isset($selected[$i]) && (string)$selected[$i] === '1';
        $includeForOutput = $isSelected && (isset($outputCandidates[$i]) ? (string)$outputCandidates[$i] === '1' : true);
        $params = [
            ':field_value' => $fieldValue,
            ':is_selected' => $isSelected ? 1 : 0,
            ':include_for_output' => $includeForOutput ? 1 : 0,
            ':prescription_id' => $prescriptionId,
            ':tenant_id' => $tenantId,
        ];
        $id = (int)($ids[$i] ?? 0);
        if ($id > 0) {
            $stmtById->execute($params + [':id' => $id]);
        } else {
            $stmtByKey->execute($params + [':field_key' => mb_substr((string)$key, 0, 120)]);
        }
    }

    // 使用項目選択はOCR補正学習ではなく、拠点ごとの出力項目の採用傾向として保存する。
    try {
        $stmt = $pdo->prepare('SELECT * FROM prescription_selected_fields WHERE prescription_id = :id ORDER BY display_order, id');
        $stmt->execute([':id' => $prescriptionId]);
        $rows = $stmt->fetchAll();
        if ($rows) {
            $parseJobId = isset($rows[0]['parse_job_id']) ? (int)$rows[0]['parse_job_id'] : null;
            (new PrescriptionKnowledgeService())->saveFieldObservations($parseJobId, $tenantId, $prescriptionId, $rows);
        }
    } catch (Throwable) {
    }
}


/**
 * POSTされた薬品名関連情報を補助学習用に整形する。
 * 一般名・商品名・AI元値・人間修正後値を同じ行に束ねる。
 *
 * @return array<int,array<string,mixed>>
 */
function medication_name_learning_rows_from_post(array $post): array
{
    $drugNames = $post['drug_name'] ?? [];
    $genericNames = $post['generic_name'] ?? [];
    $brandNames = $post['brand_name'] ?? [];
    $rawDrugTexts = $post['raw_drug_text'] ?? [];
    $relations = $post['drug_name_relation_type'] ?? [];
    $aiDrugNames = $post['ai_drug_name'] ?? [];
    $aiGenericNames = $post['ai_generic_name'] ?? [];
    $aiBrandNames = $post['ai_brand_name'] ?? [];
    $aiRawDrugTexts = $post['ai_raw_drug_text'] ?? [];

    $rows = [];
    foreach ($drugNames as $i => $drugName) {
        $finalDrug = trim((string)$drugName);
        $finalGeneric = trim((string)($genericNames[$i] ?? ''));
        $finalBrand = trim((string)($brandNames[$i] ?? ''));
        $finalRaw = trim((string)($rawDrugTexts[$i] ?? ''));
        if ($finalRaw === '') {
            $finalRaw = implode("
", array_values(array_filter([$finalDrug, $finalGeneric, $finalBrand], static fn($v) => trim((string)$v) !== '')));
        }

        if ($finalDrug === '' && $finalGeneric === '' && $finalBrand === '' && $finalRaw === '') {
            continue;
        }

        $relation = (string)($relations[$i] ?? 'unknown');
        if (!in_array($relation, ['single', 'generic_brand_pair', 'multiple_candidates', 'unknown'], true)) {
            $relation = 'unknown';
        }

        $aiDrug = trim((string)($aiDrugNames[$i] ?? ''));
        $aiGeneric = trim((string)($aiGenericNames[$i] ?? ''));
        $aiBrand = trim((string)($aiBrandNames[$i] ?? ''));
        $aiRaw = trim((string)($aiRawDrugTexts[$i] ?? ''));

        $rows[] = [
            'sort_order' => $i + 1,
            'final_drug_name' => $finalDrug,
            'final_generic_name' => $finalGeneric,
            'final_brand_name' => $finalBrand,
            'final_raw_drug_text' => $finalRaw,
            'relation_type' => $relation,
            'ai_drug_name' => $aiDrug,
            'ai_generic_name' => $aiGeneric,
            'ai_brand_name' => $aiBrand,
            'ai_raw_drug_text' => $aiRaw,
            'action_type' => ($aiDrug === '' && $aiGeneric === '' && $aiBrand === '' && $aiRaw === '') ? 'added' : ((trim($aiDrug . $aiGeneric . $aiBrand . $aiRaw) !== trim($finalDrug . $finalGeneric . $finalBrand . $finalRaw)) ? 'edited' : 'confirmed'),
        ];
    }

    return $rows;
}



/**
 * QR作成へ進む前の最終入力チェック。
 * AIが空欄を読んだ項目は「判定不能」として、入力されるまで保存/QR導線へ進ませない。
 * 判定はAI confidenceではなく、厚生労働省資料に基づく桁数・検証番号・日付成立性で行う。
 */
function assert_prescription_post_ready_for_qr(array $post): void
{
    $errors = [];
    $required = [
        'patient_name' => '氏名',
        'birth_date' => '生年月日',
        'insurance_no' => '保険者番号',
        'insured_symbol_number' => '被保険者証・被保険者手帳の記号・番号',
        'issued_on' => '交付年月日',
        'medical_institution_code' => '医療機関コード',
        'medical_institution_name' => '保険医療機関名',
        'doctor_name' => '保険医氏名',
    ];
    foreach ($required as $key => $label) {
        if (trim((string)($post[$key] ?? '')) === '') {
            $errors[] = '判定不能: ' . $label . 'が空欄です。AIが読めなかった場合も、手入力しないとQR作成へ進めません。';
        }
    }

    foreach (['birth_date' => '生年月日', 'issued_on' => '交付年月日'] as $key => $label) {
        $value = trim((string)($post[$key] ?? ''));
        if ($value !== '' && normalize_prescription_date_value($value) === null) {
            $errors[] = 'NG: ' . $label . 'が日付として判定できません。和暦または西暦で入力してください。';
        }
    }

    if (class_exists('PrescriptionReferenceRuleService')) {
        foreach ([
            'insurance_no' => ['保険者番号', 'insurance_no'],
            'medical_institution_code' => ['医療機関コード', 'medical_institution_code'],
        ] as $key => [$label, $type]) {
            $value = trim((string)($post[$key] ?? ''));
            if ($value !== '') {
                $check = PrescriptionReferenceRuleService::validateCode($type, $value);
                if (empty($check['valid'])) {
                    $errors[] = 'NG: ' . $label . ' - ' . (string)($check['message'] ?? '厚生労働省資料の番号ルールに一致しません。');
                }
            }
        }
        $publicPayer = trim((string)($post['public_payer_no'] ?? ''));
        $publicBeneficiary = trim((string)($post['public_beneficiary_no'] ?? ''));
        if ($publicPayer !== '' || $publicBeneficiary !== '') {
            if ($publicPayer === '') {
                $errors[] = '判定不能: 公費負担者番号が空欄です。公費受給者番号がある場合は入力してください。';
            } else {
                $check = PrescriptionReferenceRuleService::validateCode('public_payer_no', $publicPayer);
                if (empty($check['valid'])) {
                    $errors[] = 'NG: 公費負担者番号 - ' . (string)($check['message'] ?? '8桁/検証番号ルールに一致しません。');
                }
            }
            if ($publicBeneficiary === '') {
                $errors[] = '判定不能: 公費負担医療の受給者番号が空欄です。公費負担者番号がある場合は入力してください。';
            } else {
                $check = PrescriptionReferenceRuleService::validateCode('public_beneficiary_no', $publicBeneficiary);
                if (empty($check['valid'])) {
                    $errors[] = 'NG: 公費負担医療の受給者番号 - ' . (string)($check['message'] ?? '7桁/検証番号ルールに一致しません。');
                }
            }
        }
    }

    $drugNames = $post['drug_name'] ?? [];
    $hasDrug = false;
    foreach ($drugNames as $i => $drugName) {
        $name = trim((string)$drugName);
        $raw = trim((string)($post['raw_drug_text'][$i] ?? ''));
        $generic = trim((string)($post['generic_name'][$i] ?? ''));
        $brand = trim((string)($post['brand_name'][$i] ?? ''));
        $usage = trim((string)($post['usage_text'][$i] ?? ''));
        $dose = trim((string)($post['dose_text'][$i] ?? ''));
        $days = trim((string)($post['days_count'][$i] ?? ''));
        $amount = trim((string)($post['amount_text'][$i] ?? ''));
        if ($name === '' && $raw === '' && $generic === '' && $brand === '' && $usage === '' && $dose === '' && $days === '' && $amount === '') {
            continue;
        }
        $hasDrug = true;

        // 外用薬・頓服・用量幅ありの処方では「錠数×用法×期間」に分解できないことがある。
        // そのため、用法/日数/総量の未分解はDB保存を止めず、画面・ルール判定側で要確認にする。
        // ただし、薬品名として使える文字列も処方箋上の原文もない行は保存できないためブロックする。
        if ($name === '' && $raw === '' && $generic === '' && $brand === '') {
            $errors[] = '判定不能: 処方' . (string)($i + 1) . 'の薬品名または処方箋上の表記が空欄です。';
        }
    }
    if (!$hasDrug) {
        $errors[] = '判定不能: 薬の情報が0件です。再読み込みまたは手入力してください。';
    }

    if ($errors) {
        throw new RuntimeException(implode("\n", array_values(array_unique($errors))));
    }
}

function create_prescription_from_post(array $user, array $post): int
{
    $tenantId = (int)$user['tenant_id'];
    $pdo = Db::branch();
    assert_prescription_post_ready_for_qr($post);
    $pdo->beginTransaction();
    try {
        $patientId = find_or_create_patient($tenantId, $post);
        $medicalId = find_or_create_medical_institution($tenantId, $post);
        $receptionNo = 'R' . date('YmdHis') . '-' . random_int(100, 999);
        $parseJobId = isset($post['parse_job_id']) && (int)$post['parse_job_id'] > 0 ? (int)$post['parse_job_id'] : null;

        $stmt = $pdo->prepare('INSERT INTO prescriptions (
            company_uid, branch_uid, tenant_id, parse_job_id, patient_id, medical_institution_id, reception_no, received_at, issued_on, status,
            insurance_no, insured_symbol_number, copay_rate, source_type, ai_confidence, qr_payload, created_by_user_id, updated_by_user_id
        ) VALUES (
            :company_uid, :branch_uid, :tenant_id, :parse_job_id, :patient_id, :medical_institution_id, :reception_no, NOW(), :issued_on, :status,
            :insurance_no, :insured_symbol_number, :copay_rate, :source_type, :ai_confidence, :qr_payload, :created_by_user_id, :updated_by_user_id
        )');
        $stmt->execute([
            ':company_uid' => current_company_uid(),
            ':branch_uid' => current_branch_uid(),
            ':tenant_id' => $tenantId,
            ':parse_job_id' => $parseJobId,
            ':patient_id' => $patientId,
            ':medical_institution_id' => $medicalId,
            ':reception_no' => $receptionNo,
            ':issued_on' => normalize_prescription_date_value($post['issued_on'] ?? ''),
            ':status' => 'completed',
            ':insurance_no' => normalize_prescription_code_value('insurance_no', $post['insurance_no'] ?? '' ) ?: null,
            ':insured_symbol_number' => $post['insured_symbol_number'] ?? null,
            ':copay_rate' => $post['copay_rate'] ?? null,
            ':source_type' => $parseJobId ? 'camera' : 'demo',
            ':ai_confidence' => $post['ai_confidence'] ?? null,
            ':qr_payload' => null,
            ':created_by_user_id' => $user['id'],
            ':updated_by_user_id' => $user['id'],
        ]);
        $prescriptionId = (int)$pdo->lastInsertId();

        $drugNames = $post['drug_name'] ?? [];
        $genericNames = $post['generic_name'] ?? [];
        $brandNames = $post['brand_name'] ?? [];
        $rawDrugTexts = $post['raw_drug_text'] ?? [];
        $relationTypes = $post['drug_name_relation_type'] ?? [];
        $aiDrugNames = $post['ai_drug_name'] ?? [];
        $aiGenericNames = $post['ai_generic_name'] ?? [];
        $aiBrandNames = $post['ai_brand_name'] ?? [];
        $doseTexts = $post['dose_text'] ?? [];
        $usageTexts = $post['usage_text'] ?? [];
        $daysCounts = $post['days_count'] ?? [];
        $amountTexts = $post['amount_text'] ?? [];
        $stockStatuses = $post['stock_status'] ?? [];

        $medOptionalColumns = [];
        foreach (['dose_text','generic_name','brand_name','raw_drug_text','ai_drug_name','ai_generic_name','ai_brand_name','ai_raw_drug_text','drug_name_relation_type'] as $column) {
            if (Db::columnExists($pdo, 'prescription_medications', $column)) {
                $medOptionalColumns[] = $column;
            }
        }
        $medColumns = array_merge(['prescription_id','sort_order','drug_name','usage_text','days_count','amount_text','stock_status','needs_check'], $medOptionalColumns);
        $medSql = 'INSERT INTO prescription_medications (' . implode(', ', $medColumns) . ') VALUES (:' . implode(', :', $medColumns) . ')';
        $medStmt = $pdo->prepare($medSql);

        foreach ($drugNames as $i => $drugName) {
            $drugName = trim((string)$drugName);
            $genericName = trim((string)($genericNames[$i] ?? ''));
            $brandName = trim((string)($brandNames[$i] ?? ''));
            $rawDrugText = trim((string)($rawDrugTexts[$i] ?? ''));
            if ($rawDrugText === '') {
                $rawDrugText = implode("
", array_values(array_filter([$drugName, $genericName, $brandName], static fn($v) => trim((string)$v) !== '')));
            }
            if ($drugName === '' && $genericName === '' && $brandName === '' && $rawDrugText === '') {
                continue;
            }
            if ($drugName === '') {
                $fallbackDrugName = $brandName !== '' ? $brandName : ($genericName !== '' ? $genericName : $rawDrugText);
                $fallbackDrugName = trim((string)(preg_split('/\R/u', $fallbackDrugName)[0] ?? $fallbackDrugName));
                $drugName = mb_substr($fallbackDrugName, 0, 255);
            }
            $days = (int)($daysCounts[$i] ?? 0);
            $doseText = trim((string)($doseTexts[$i] ?? ''));
            $usageText = trim((string)($usageTexts[$i] ?? ''));
            $currentAmountText = trim((string)($amountTexts[$i] ?? ''));
            $calculatedAmountText = class_exists('MedicationDosageCalculator')
                ? MedicationDosageCalculator::calculateAmountText($drugName, $doseText, $usageText, $days)
                : '';
            if (class_exists('MedicationDosageCalculator') && MedicationDosageCalculator::shouldReplaceAmountText($currentAmountText, $calculatedAmountText)) {
                $currentAmountText = $calculatedAmountText;
            }
            $status = $stockStatuses[$i] ?? 'unknown';
            $relation = (string)($relationTypes[$i] ?? 'unknown');
            if (!in_array($relation, ['single','generic_brand_pair','multiple_candidates','unknown'], true)) {
                $relation = 'unknown';
            }

            $params = [
                ':prescription_id' => $prescriptionId,
                ':sort_order' => $i + 1,
                ':drug_name' => $drugName,
                ':usage_text' => $usageText !== '' ? $usageText : null,
                ':days_count' => $days ?: null,
                ':amount_text' => $currentAmountText !== '' ? $currentAmountText : ($days ? $days . '日分' : null),
                ':stock_status' => in_array($status, ['adopted','in_stock','low_stock','not_stocked','unknown'], true) ? $status : 'unknown',
                ':needs_check' => ($status === 'low_stock' || $usageText === '' || ($doseText === '' && $days === 0 && $currentAmountText === '')) ? 1 : 0,
            ];
            foreach ($medOptionalColumns as $column) {
                $params[':' . $column] = match ($column) {
                    'dose_text' => $doseText !== '' ? $doseText : null,
                    'generic_name' => $genericName !== '' ? $genericName : null,
                    'brand_name' => $brandName !== '' ? $brandName : null,
                    'raw_drug_text' => $rawDrugText !== '' ? $rawDrugText : null,
                    'ai_drug_name' => trim((string)($aiDrugNames[$i] ?? '')) ?: null,
                    'ai_generic_name' => trim((string)($aiGenericNames[$i] ?? '')) ?: null,
                    'ai_brand_name' => trim((string)($aiBrandNames[$i] ?? '')) ?: null,
                    'ai_raw_drug_text' => trim((string)($post['ai_raw_drug_text'][$i] ?? '')) ?: null,
                    'drug_name_relation_type' => $relation,
                    default => null,
                };
            }
            $medStmt->execute($params);
        }

        // 薬品名・一般名・商品名の補助学習は、解析結果修正後に prescription_field_select.php で保存する。
        // ここでは拠点DBへの処方箋確定保存と、使用項目選択の運用設定保存だけを行う。
        $selectedFields = selected_prescription_fields_from_post($post);
        save_prescription_selected_fields($pdo, $tenantId, $prescriptionId, $parseJobId, $selectedFields);

        $ioDebug = new PrescriptionIoDebugService();
        $confirmedSnapshot = PrescriptionIoDebugService::confirmedPostSnapshot($post);
        $ioDebug->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'confirmed_post', '人間修正後: 確定POSTデータ', $confirmedSnapshot, [
            'created_by_user_id' => (int)$user['id'],
        ]);
        if (class_exists('PrescriptionOcrDatasetService')) {
            (new PrescriptionOcrDatasetService())->saveEvent($tenantId, $parseJobId, $prescriptionId, 'human_corrected_confirmed', $confirmedSnapshot, [
                'created_by_user_id' => (int)$user['id'],
                'source' => 'prescription_confirm.php',
            ]);
        }
        if (!(bool)app_config('prescription_new_validation.disable_legacy_learning', true)) {
            (new PrescriptionKnowledgeService())->savePipelineTrace($tenantId, $parseJobId ?? 0, $prescriptionId, 'confirmed_post', 'write', $confirmedSnapshot, [
                'created_by_user_id' => (int)$user['id'],
            ]);
        }

        // 処方箋受付時に薬局が確認すべきルールを、人間修正後データで再判定して保存する。
        // 期限切れ、変更不可/署名、患者希望、一般名処方、必須項目、信頼度などはQR作成前の確認材料にする。
        $ruleChecks = [];
        try {
            $ruleEngine = new PrescriptionRuleEngineService();
            $ruleChecks = $ruleEngine->evaluatePostData($post);
            $ruleEngine->saveRuleChecks($tenantId, $prescriptionId, $parseJobId, $ruleChecks);
        } catch (Throwable) {
            // ルール判定保存失敗で処方箋自体の保存は止めない。
        }

        // 解析結果確認画面で確定した項目名・分類・順序は、旧AI判定スコアではなく拠点ひな型候補へ反映する。
        // disable_legacy_learning=true の場合も、ひな型候補だけは継続して保存する。
        try {
            $knowledge = new PrescriptionKnowledgeService();
            $layoutSaved = false;
            if ($selectedFields) {
                $knowledge->saveLayoutTemplateLearning($parseJobId, $tenantId, $selectedFields);
                $knowledge->saveFieldObservations($parseJobId, $tenantId, $prescriptionId, $selectedFields);
                $layoutSaved = true;
            }

            $legacyLearningEnabled = !(bool)app_config('prescription_new_validation.disable_legacy_learning', true);
            $correctionRows = [];
            $drugLearningRows = [];
            if ($legacyLearningEnabled) {
                $correctionRows = correction_learning_rows_from_selected_fields($selectedFields);
                if ($selectedFields) {
                    $knowledge->saveConfirmedCorrectionLearning($parseJobId, $tenantId, $correctionRows);
                }
                $drugLearningRows = medication_name_learning_rows_from_post($post);
                if ($drugLearningRows) {
                    $knowledge->saveDrugNameLearningEvents($parseJobId, $tenantId, $prescriptionId, $drugLearningRows);
                }
            }

            $maskedTemplateLearning = false;
            if (class_exists('PrescriptionTemplateMaskService')) {
                (new PrescriptionTemplateMaskService())->saveConfirmedTemplateLearningAssets($tenantId, $parseJobId, $prescriptionId, $post, $selectedFields);
                $maskedTemplateLearning = true;
            }

            $learningSummary = [
                'parse_job_id' => $parseJobId,
                'prescription_id' => $prescriptionId,
                'layout_template_rows' => count($selectedFields),
                'layout_template_saved' => $layoutSaved,
                'masked_template_learning_saved' => $maskedTemplateLearning,
                'legacy_learning_enabled' => $legacyLearningEnabled,
                'field_learning_rows' => count($correctionRows),
                'drug_learning_rows' => count($drugLearningRows),
                'db_roles' => [
                    'superuser_admin_db' => 'inprof3_prescription',
                    'company_parent_db' => 'inprof3_companyXXXX',
                    'branch_child_tenant_db' => 'inprof3_tenantsXXXX',
                    'knowledge_db' => 'inprof3_assistantdata',
                ],
                'score_basis' => $legacyLearningEnabled
                    ? '旧補助学習も有効。項目名・分類・順序は補助学習DBへ保存。テンプレート素材は枠だけ/固定ラベル/補正パターンのみ保存。'
                    : '旧AI判定スコアは無効。補助学習DBには枠だけ画像・固定ラベル・補正パターンのみ保存し、患者実値は保存しない。',
                'saved_at' => date('c'),
            ];
            $ioDebug->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'layout_template_saved_summary', 'ひな型候補保存後: 確定項目サマリー', $learningSummary, [
                'created_by_user_id' => (int)$user['id'],
            ]);
            if ($legacyLearningEnabled) {
                $knowledge->savePipelineTrace($tenantId, $parseJobId ?? 0, $prescriptionId, 'layout_template_saved_summary', 'learning', $learningSummary, [
                    'created_by_user_id' => (int)$user['id'],
                ]);
            }
        } catch (Throwable) {
            // ひな型候補/補助学習DBの一時不調で拠点DBへの確定保存を止めない。
        }

        $savedForDebug = get_prescription($tenantId, $prescriptionId);
        if ($savedForDebug) {
            $ioDebug->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'db_saved_prescription', 'DB保存後: 処方箋保存データ', $savedForDebug, [
                'created_by_user_id' => (int)$user['id'],
            ]);
            if (!(bool)app_config('prescription_new_validation.disable_legacy_learning', true)) {
                (new PrescriptionKnowledgeService())->savePipelineTrace($tenantId, $parseJobId ?? 0, $prescriptionId, 'db_saved_prescription', 'write', $savedForDebug, [
                    'created_by_user_id' => (int)$user['id'],
                ]);
            }
        }

        if ($parseJobId) {
            $job = PrescriptionOcrService::getJob($tenantId, $parseJobId);
            if ($job && is_array($job['normalized'] ?? null)) {
                if (!(bool)app_config('prescription_new_validation.disable_legacy_learning', true)) {
                    (new PrescriptionFeedbackService())->saveCorrections($parseJobId, $tenantId, $job['normalized'], $post);
                }
                $pdo->prepare('UPDATE prescription_parse_jobs SET status = "confirmed", prescription_id = :prescription_id, confirmed_at = NOW(), updated_at = NOW() WHERE id = :id')
                    ->execute([':prescription_id' => $prescriptionId, ':id' => $parseJobId]);
            }
        }
        audit_log($tenantId, (int)$user['id'], 'prescription.create', 'prescriptions', $prescriptionId, [
            'reception_no' => $receptionNo,
            'parse_job_id' => $parseJobId,
            'rule_summary' => class_exists('PrescriptionRuleEngineService') ? PrescriptionRuleEngineService::summarize($ruleChecks ?? []) : [],
        ]);
        $pdo->commit();
        return $prescriptionId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function list_prescriptions(int $tenantId, array $filters = []): array
{
    $where = ['p.status <> "deleted"'];
    $params = [];
    if (Db::columnExists(Db::branch(), 'prescriptions', 'tenant_id')) {
        $where[] = 'p.tenant_id = :tenant_id';
        $params[':tenant_id'] = $tenantId;
    }
    if (!empty($filters['patient_name'])) {
        $where[] = 'pt.name LIKE :patient_name';
        $params[':patient_name'] = '%' . $filters['patient_name'] . '%';
    }
    if (!empty($filters['institution_code'])) {
        $where[] = 'mi.institution_code LIKE :institution_code';
        $params[':institution_code'] = '%' . $filters['institution_code'] . '%';
    }
    if (!empty($filters['from'])) {
        $where[] = 'DATE(p.received_at) >= :from';
        $params[':from'] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $where[] = 'DATE(p.received_at) <= :to';
        $params[':to'] = $filters['to'];
    }
    $sql = 'SELECT p.*, pt.name AS patient_name, pt.gender, pt.birth_date, mi.name AS medical_name, mi.institution_code
            FROM prescriptions p
            INNER JOIN patients pt ON pt.id = p.patient_id
            LEFT JOIN medical_institutions mi ON mi.id = p.medical_institution_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.received_at DESC LIMIT 50';
    $stmt = Db::branch()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_prescription(int $tenantId, int $id): ?array
{
    $where = ['p.id = :id', 'p.status <> "deleted"'];
    $params = [':id' => $id];
    if (Db::columnExists(Db::branch(), 'prescriptions', 'tenant_id')) {
        $where[] = 'p.tenant_id = :tenant_id';
        $params[':tenant_id'] = $tenantId;
    }
    $stmt = Db::branch()->prepare('SELECT p.*, pt.name AS patient_name, pt.gender, pt.birth_date, pt.phone, mi.name AS medical_name, mi.institution_code
                                FROM prescriptions p
                                INNER JOIN patients pt ON pt.id = p.patient_id
                                LEFT JOIN medical_institutions mi ON mi.id = p.medical_institution_id
                                WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
    $stmt->execute($params);
    $prescription = $stmt->fetch();
    if (!$prescription) {
        return null;
    }
    $medStmt = Db::branch()->prepare('SELECT * FROM prescription_medications WHERE prescription_id = :id ORDER BY sort_order');
    $medStmt->execute([':id' => $id]);
    $prescription['medications'] = $medStmt->fetchAll();

    if (Db::tableExists(Db::branch(), 'prescription_selected_fields')) {
        $fieldStmt = Db::branch()->prepare('SELECT * FROM prescription_selected_fields WHERE prescription_id = :id ORDER BY display_order, id');
        $fieldStmt->execute([':id' => $id]);
        $prescription['selected_fields'] = $fieldStmt->fetchAll();
    } else {
        $prescription['selected_fields'] = [];
    }

    try {
        $prescription['rule_checks'] = (new PrescriptionRuleEngineService())->loadRuleChecks($tenantId, $id);
        if (!$prescription['rule_checks']) {
            $prescription['rule_checks'] = (new PrescriptionRuleEngineService())->evaluateSavedPrescription($prescription);
        }
    } catch (Throwable) {
        $prescription['rule_checks'] = [];
    }

    return $prescription;
}

function delete_prescription(int $tenantId, int $userId, int $id): void
{
    $where = 'id = :id';
    $params = [':user_id' => $userId, ':id' => $id];
    if (Db::columnExists(Db::branch(), 'prescriptions', 'tenant_id')) {
        $where .= ' AND tenant_id = :tenant_id';
        $params[':tenant_id'] = $tenantId;
    }
    $stmt = Db::branch()->prepare('UPDATE prescriptions SET status = "deleted", updated_by_user_id = :user_id WHERE ' . $where);
    $stmt->execute($params);
    audit_log($tenantId, $userId, 'prescription.delete', 'prescriptions', $id, []);
}

function audit_log(int $tenantId, ?int $userId, string $action, ?string $targetTable, ?int $targetId, array $detail): void
{
    try {
        $stmt = Db::branch()->prepare('INSERT INTO audit_logs (user_id, action, target_table, target_id, detail_json, ip_address, user_agent)
                                    VALUES (:user_id, :action, :target_table, :target_id, :detail_json, :ip_address, :user_agent)');
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':target_table' => $targetTable,
            ':target_id' => $targetId,
            ':detail_json' => json_encode(['tenant_id' => $tenantId, 'detail' => $detail], JSON_UNESCAPED_UNICODE),
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable) {
        // 監査ログ失敗で本処理を止めない。
    }
}

function stock_status_label(string $status): string
{
    return match ($status) {
        'adopted' => '採用薬',
        'in_stock' => '在庫あり',
        'low_stock' => '在庫僅少',
        'not_stocked' => '未採用',
        default => '未確認',
    };
}
