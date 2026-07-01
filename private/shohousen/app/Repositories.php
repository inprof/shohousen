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
    $stmt = $pdo->prepare('SELECT id FROM medical_institutions WHERE (institution_code = :code AND :code <> "") OR name = :name LIMIT 1');
    $stmt->execute([':code' => $code, ':name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $stmt = $pdo->prepare('INSERT INTO medical_institutions (institution_code, name) VALUES (:code, :name)');
    $stmt->execute([':code' => $code ?: null, ':name' => $name]);
    return (int)$pdo->lastInsertId();
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
        $usageTexts = $post['usage_text'] ?? [];
        $daysCounts = $post['days_count'] ?? [];
        $amountTexts = $post['amount_text'] ?? [];
        $stockStatuses = $post['stock_status'] ?? [];
        $medStmt = $pdo->prepare('INSERT INTO prescription_medications (prescription_id, sort_order, drug_name, usage_text, days_count, amount_text, stock_status, needs_check)
                                  VALUES (:prescription_id, :sort_order, :drug_name, :usage_text, :days_count, :amount_text, :stock_status, :needs_check)');
        foreach ($drugNames as $i => $drugName) {
            $drugName = trim((string)$drugName);
            if ($drugName === '') {
                continue;
            }
            $days = (int)($daysCounts[$i] ?? 0);
            $status = $stockStatuses[$i] ?? 'unknown';
            $medStmt->execute([
                ':prescription_id' => $prescriptionId,
                ':sort_order' => $i + 1,
                ':drug_name' => $drugName,
                ':usage_text' => $usageTexts[$i] ?? null,
                ':days_count' => $days ?: null,
                ':amount_text' => trim((string)($amountTexts[$i] ?? '')) !== '' ? trim((string)$amountTexts[$i]) : ($days ? $days . '日分' : null),
                ':stock_status' => in_array($status, ['adopted','in_stock','low_stock','not_stocked','unknown'], true) ? $status : 'unknown',
                ':needs_check' => $status === 'low_stock' ? 1 : 0,
            ]);
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
