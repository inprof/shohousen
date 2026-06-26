<?php
declare(strict_types=1);

function enabled_features(int $tenantId): array
{
    $sql = 'SELECT f.* FROM tenant_features tf INNER JOIN features f ON f.id = tf.feature_id
            WHERE tf.tenant_id = :tenant_id AND f.is_active = 1 ORDER BY f.sort_order, f.id';
    $stmt = Db::pdo()->prepare($sql);
    $stmt->execute([':tenant_id' => $tenantId]);
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
    $stmt = Db::pdo()->prepare('SELECT id FROM patients WHERE tenant_id = :tenant_id AND name = :name AND birth_date <=> :birth_date LIMIT 1');
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':name' => $input['patient_name'],
        ':birth_date' => $input['birth_date'] ?: null,
    ]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $gender = match ($input['gender'] ?? '') {
        '男性' => 'male',
        '女性' => 'female',
        default => 'unknown',
    };
    $stmt = Db::pdo()->prepare('INSERT INTO patients (tenant_id, name, gender, birth_date) VALUES (:tenant_id, :name, :gender, :birth_date)');
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':name' => $input['patient_name'],
        ':gender' => $gender,
        ':birth_date' => $input['birth_date'] ?: null,
    ]);
    return (int)Db::pdo()->lastInsertId();
}

function find_or_create_medical_institution(int $tenantId, array $input): ?int
{
    $name = trim((string)($input['medical_institution_name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $code = trim((string)($input['medical_institution_code'] ?? ''));
    $stmt = Db::pdo()->prepare('SELECT id FROM medical_institutions WHERE tenant_id = :tenant_id AND (institution_code = :code OR name = :name) LIMIT 1');
    $stmt->execute([':tenant_id' => $tenantId, ':code' => $code, ':name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $stmt = Db::pdo()->prepare('INSERT INTO medical_institutions (tenant_id, institution_code, name) VALUES (:tenant_id, :code, :name)');
    $stmt->execute([':tenant_id' => $tenantId, ':code' => $code ?: null, ':name' => $name]);
    return (int)Db::pdo()->lastInsertId();
}

function create_prescription_from_post(array $user, array $post): int
{
    $tenantId = (int)$user['tenant_id'];
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        $patientId = find_or_create_patient($tenantId, $post);
        $medicalId = find_or_create_medical_institution($tenantId, $post);
        $receptionNo = 'R' . date('YmdHis') . '-' . random_int(100, 999);
        $parseJobId = isset($post['parse_job_id']) && (int)$post['parse_job_id'] > 0 ? (int)$post['parse_job_id'] : null;

        $stmt = $pdo->prepare('INSERT INTO prescriptions (
            company_uid, branch_uid, tenant_id, parse_job_id, patient_id, medical_institution_id, reception_no, received_at, issued_on, status,
            insurance_no, insured_symbol_number, copay_rate, source_type, ai_confidence, qr_payload, created_by, updated_by
        ) VALUES (
            :company_uid, :branch_uid, :tenant_id, :parse_job_id, :patient_id, :medical_institution_id, :reception_no, NOW(), :issued_on, :status,
            :insurance_no, :insured_symbol_number, :copay_rate, :source_type, :ai_confidence, :qr_payload, :created_by, :updated_by
        )');
        $stmt->execute([
            ':company_uid' => current_company_uid(),
            ':branch_uid' => current_branch_uid(),
            ':tenant_id' => $tenantId,
            ':parse_job_id' => $parseJobId,
            ':patient_id' => $patientId,
            ':medical_institution_id' => $medicalId,
            ':reception_no' => $receptionNo,
            ':issued_on' => $post['issued_on'] ?: null,
            ':status' => 'completed',
            ':insurance_no' => $post['insurance_no'] ?? null,
            ':insured_symbol_number' => $post['insured_symbol_number'] ?? null,
            ':copay_rate' => $post['copay_rate'] ?? null,
            ':source_type' => $parseJobId ? 'camera' : 'demo',
            ':ai_confidence' => $post['ai_confidence'] ?? null,
            ':qr_payload' => null,
            ':created_by' => $user['id'],
            ':updated_by' => $user['id'],
        ]);
        $prescriptionId = (int)$pdo->lastInsertId();

        $drugNames = $post['drug_name'] ?? [];
        $usageTexts = $post['usage_text'] ?? [];
        $daysCounts = $post['days_count'] ?? [];
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
                ':amount_text' => $days ? $days . '日分' : null,
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
        (new PrescriptionQrService())->persistPayload($tenantId, $prescriptionId);
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
    $where = ['p.tenant_id = :tenant_id', 'p.status <> "deleted"'];
    $params = [':tenant_id' => $tenantId];
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
    $stmt = Db::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_prescription(int $tenantId, int $id): ?array
{
    $stmt = Db::pdo()->prepare('SELECT p.*, pt.name AS patient_name, pt.gender, pt.birth_date, pt.phone, mi.name AS medical_name, mi.institution_code
                                FROM prescriptions p
                                INNER JOIN patients pt ON pt.id = p.patient_id
                                LEFT JOIN medical_institutions mi ON mi.id = p.medical_institution_id
                                WHERE p.tenant_id = :tenant_id AND p.id = :id AND p.status <> "deleted" LIMIT 1');
    $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
    $prescription = $stmt->fetch();
    if (!$prescription) {
        return null;
    }
    $medStmt = Db::pdo()->prepare('SELECT * FROM prescription_medications WHERE prescription_id = :id ORDER BY sort_order');
    $medStmt->execute([':id' => $id]);
    $prescription['medications'] = $medStmt->fetchAll();
    return $prescription;
}

function delete_prescription(int $tenantId, int $userId, int $id): void
{
    $stmt = Db::pdo()->prepare('UPDATE prescriptions SET status = "deleted", updated_by = :user_id WHERE tenant_id = :tenant_id AND id = :id');
    $stmt->execute([':user_id' => $userId, ':tenant_id' => $tenantId, ':id' => $id]);
    audit_log($tenantId, $userId, 'prescription.delete', 'prescriptions', $id, []);
}

function audit_log(int $tenantId, ?int $userId, string $action, ?string $targetTable, ?int $targetId, array $detail): void
{
    $stmt = Db::pdo()->prepare('INSERT INTO audit_logs (tenant_id, user_id, action, target_table, target_id, detail_json, ip_address, user_agent)
                                VALUES (:tenant_id, :user_id, :action, :target_table, :target_id, :detail_json, :ip_address, :user_agent)');
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':action' => $action,
        ':target_table' => $targetTable,
        ':target_id' => $targetId,
        ':detail_json' => json_encode($detail, JSON_UNESCAPED_UNICODE),
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
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
