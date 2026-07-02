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

function find_or_create_patient(int $tenantId, array $input): int
{
    $pdo = Db::branch();
    $name = trim((string)($input['patient_name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('患者名が空です。');
    }
    $birthDate = ($input['birth_date'] ?? '') ?: null;
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
    $code = trim((string)($input['medical_institution_code'] ?? ''));

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

        // 何も値がなく、かつ未選択の項目は保存しない。空欄だが選択された項目は「空欄確認済み」として保存する。
        $isSelected = isset($selected[$i]) && (string)$selected[$i] === '1';
        if (!$isSelected && $value === '' && $aiValue === '') {
            continue;
        }

        $rows[] = [
            'field_key' => mb_substr($key, 0, 120),
            'field_label' => mb_substr($label !== '' ? $label : $key, 0, 160),
            'field_group' => $group,
            'field_value' => $value,
            'source_ai_value' => $aiValue,
            'source_section' => mb_substr((string)($sections[$i] ?? ''), 0, 160),
            'confidence' => is_numeric($confidences[$i] ?? null) ? (float)$confidences[$i] : null,
            'needs_human_check' => isset($needsChecks[$i]) && (string)$needsChecks[$i] === '1',
            'is_selected' => $isSelected,
            'include_for_output' => $isSelected && (isset($outputCandidates[$i]) ? (string)$outputCandidates[$i] === '1' : true),
            'display_order' => $i + 1,
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


function create_prescription_from_post(array $user, array $post): int
{
    $tenantId = (int)$user['tenant_id'];
    $pdo = Db::branch();
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
            ':issued_on' => ($post['issued_on'] ?? '') ?: null,
            ':status' => 'completed',
            ':insurance_no' => $post['insurance_no'] ?? null,
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
        $usageTexts = $post['usage_text'] ?? [];
        $daysCounts = $post['days_count'] ?? [];
        $amountTexts = $post['amount_text'] ?? [];
        $stockStatuses = $post['stock_status'] ?? [];

        $medOptionalColumns = [];
        foreach (['generic_name','brand_name','raw_drug_text','ai_drug_name','ai_generic_name','ai_brand_name','drug_name_relation_type'] as $column) {
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
                $drugName = $brandName !== '' ? $brandName : $genericName;
            }
            $days = (int)($daysCounts[$i] ?? 0);
            $status = $stockStatuses[$i] ?? 'unknown';
            $relation = (string)($relationTypes[$i] ?? 'unknown');
            if (!in_array($relation, ['single','generic_brand_pair','multiple_candidates','unknown'], true)) {
                $relation = 'unknown';
            }

            $params = [
                ':prescription_id' => $prescriptionId,
                ':sort_order' => $i + 1,
                ':drug_name' => $drugName,
                ':usage_text' => $usageTexts[$i] ?? null,
                ':days_count' => $days ?: null,
                ':amount_text' => trim((string)($amountTexts[$i] ?? '')) !== '' ? trim((string)$amountTexts[$i]) : ($days ? $days . '日分' : null),
                ':stock_status' => in_array($status, ['adopted','in_stock','low_stock','not_stocked','unknown'], true) ? $status : 'unknown',
                ':needs_check' => $status === 'low_stock' ? 1 : 0,
            ];
            foreach ($medOptionalColumns as $column) {
                $params[':' . $column] = match ($column) {
                    'generic_name' => $genericName !== '' ? $genericName : null,
                    'brand_name' => $brandName !== '' ? $brandName : null,
                    'raw_drug_text' => $rawDrugText !== '' ? $rawDrugText : null,
                    'ai_drug_name' => trim((string)($aiDrugNames[$i] ?? '')) ?: null,
                    'ai_generic_name' => trim((string)($aiGenericNames[$i] ?? '')) ?: null,
                    'ai_brand_name' => trim((string)($aiBrandNames[$i] ?? '')) ?: null,
                    'drug_name_relation_type' => $relation,
                    default => null,
                };
            }
            $medStmt->execute($params);
        }

        $drugLearningRows = medication_name_learning_rows_from_post($post);
        if ($drugLearningRows) {
            (new PrescriptionKnowledgeService())->saveDrugNameLearningEvents($parseJobId, $tenantId, $prescriptionId, $drugLearningRows);
        }
        $selectedFields = selected_prescription_fields_from_post($post);
        save_prescription_selected_fields($pdo, $tenantId, $prescriptionId, $parseJobId, $selectedFields);

        if ($selectedFields) {
            (new PrescriptionKnowledgeService())->saveFieldObservations($parseJobId, $tenantId, $prescriptionId, $selectedFields);
        }

        if ($parseJobId) {
            $job = PrescriptionOcrService::getJob($tenantId, $parseJobId);
            if ($job && is_array($job['normalized'] ?? null)) {
                (new PrescriptionFeedbackService())->saveCorrections($parseJobId, $tenantId, $job['normalized'], $post);
                $pdo->prepare('UPDATE prescription_parse_jobs SET status = "confirmed", prescription_id = :prescription_id, confirmed_at = NOW(), updated_at = NOW() WHERE id = :id')
                    ->execute([':prescription_id' => $prescriptionId, ':id' => $parseJobId]);
            }
        }
        audit_log($tenantId, (int)$user['id'], 'prescription.create', 'prescriptions', $prescriptionId, ['reception_no' => $receptionNo, 'parse_job_id' => $parseJobId]);
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
