<?php
declare(strict_types=1);

final class PrescriptionIoDebugService
{
    private const TABLE = 'prescription_io_debug_snapshots';

    public function isEnabled(): bool
    {
        return (bool)app_config('prescription_debug.enabled', true);
    }

    /**
     * 診断用スナップショットを保存する。
     * 患者情報を含むため public_html には出さず、拠点DB内に限定して保存する。
     *
     * @param array<string,mixed> $options
     */
    public function saveSnapshot(
        int $tenantId,
        ?int $parseJobId,
        ?int $prescriptionId,
        string $stage,
        string $stageLabel,
        mixed $payload,
        array $options = []
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $pdo = Db::branch();
        if (!$this->ensureTable($pdo)) {
            return;
        }

        $contentType = (string)($options['content_type'] ?? (is_string($payload) ? 'text' : 'json'));
        if (!in_array($contentType, ['json', 'text'], true)) {
            $contentType = 'json';
        }

        $snapshotJson = null;
        $snapshotText = null;
        if ($contentType === 'text') {
            $snapshotText = (string)$payload;
        } else {
            $snapshotJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($snapshotJson === false) {
                $snapshotJson = json_encode(['_debug_encode_error' => json_last_error_msg()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        $sourceHash = hash('sha256', (string)$snapshotJson . "\n" . (string)$snapshotText);
        $stmt = $pdo->prepare('INSERT INTO ' . self::TABLE . '
            (company_uid, branch_uid, tenant_id, parse_job_id, prescription_id, stage, stage_label, model_name, content_type, snapshot_json, snapshot_text, source_hash, created_by_user_id, created_at)
            VALUES
            (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :prescription_id, :stage, :stage_label, :model_name, :content_type, :snapshot_json, :snapshot_text, :source_hash, :created_by_user_id, NOW())');
        $stmt->execute([
            ':company_uid' => current_company_uid(),
            ':branch_uid' => current_branch_uid(),
            ':tenant_id' => $tenantId,
            ':parse_job_id' => $parseJobId ?: null,
            ':prescription_id' => $prescriptionId ?: null,
            ':stage' => mb_substr($stage, 0, 64),
            ':stage_label' => mb_substr($stageLabel, 0, 160),
            ':model_name' => isset($options['model_name']) ? mb_substr((string)$options['model_name'], 0, 120) : null,
            ':content_type' => $contentType,
            ':snapshot_json' => $snapshotJson,
            ':snapshot_text' => $snapshotText,
            ':source_hash' => $sourceHash,
            ':created_by_user_id' => isset($options['created_by_user_id']) ? (int)$options['created_by_user_id'] : null,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function snapshots(int $tenantId, ?int $parseJobId = null, ?int $prescriptionId = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        $pdo = Db::branch();
        if (!Db::tableExists($pdo, self::TABLE)) {
            return [];
        }

        $where = ['tenant_id = :tenant_id'];
        $params = [':tenant_id' => $tenantId];
        $or = [];
        if ($parseJobId !== null && $parseJobId > 0) {
            $or[] = 'parse_job_id = :parse_job_id';
            $params[':parse_job_id'] = $parseJobId;
        }
        if ($prescriptionId !== null && $prescriptionId > 0) {
            $or[] = 'prescription_id = :prescription_id';
            $params[':prescription_id'] = $prescriptionId;
        }
        if (!$or) {
            return [];
        }
        $where[] = '(' . implode(' OR ', $or) . ')';

        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at ASC, id ASC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * 解析ジョブに既に残っている読み込み結果を、診断画面用の配列へ整形する。
     *
     * @return array<int,array<string,mixed>>
     */
    public function fallbackJobSnapshots(array $job): array
    {
        $rows = [];
        $raw = json_decode((string)($job['raw_response_json'] ?? ''), true);
        if (is_array($raw)) {
            $rows[] = [
                'stage' => 'openai_raw_response',
                'stage_label' => '読み込み直後: OpenAI生レスポンス',
                'model_name' => (string)($job['model_name'] ?? ''),
                'content_type' => 'json',
                'snapshot_json' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'snapshot_text' => null,
                'created_at' => (string)($job['analyzed_at'] ?? $job['updated_at'] ?? $job['created_at'] ?? ''),
                'source_hash' => hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            ];
        }
        $normalized = json_decode((string)($job['normalized_json'] ?? ''), true);
        if (is_array($normalized)) {
            $rows[] = [
                'stage' => 'normalized_after_correction',
                'stage_label' => '読み込み後: 正規化・補正後JSON',
                'model_name' => (string)($job['model_name'] ?? ''),
                'content_type' => 'json',
                'snapshot_json' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'snapshot_text' => null,
                'created_at' => (string)($job['analyzed_at'] ?? $job['updated_at'] ?? $job['created_at'] ?? ''),
                'source_hash' => hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            ];
        }
        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public static function confirmedPostSnapshot(array $post): array
    {
        return [
            'parse_job_id' => isset($post['parse_job_id']) ? (int)$post['parse_job_id'] : null,
            'fixed_fields' => [
                'patient_name' => (string)($post['patient_name'] ?? ''),
                'gender' => (string)($post['gender'] ?? ''),
                'birth_date' => (string)($post['birth_date'] ?? ''),
                'insurance_no' => (string)($post['insurance_no'] ?? ''),
                'insured_symbol_number' => (string)($post['insured_symbol_number'] ?? ''),
                'copay_rate' => (string)($post['copay_rate'] ?? ''),
                'issued_on' => (string)($post['issued_on'] ?? ''),
                'medical_institution_code' => (string)($post['medical_institution_code'] ?? ''),
                'medical_institution_name' => (string)($post['medical_institution_name'] ?? ''),
                'medical_institution_phone' => (string)($post['medical_institution_phone'] ?? ''),
                'doctor_name' => (string)($post['doctor_name'] ?? ''),
                'ai_confidence' => (string)($post['ai_confidence'] ?? ''),
            ],
            'medications' => self::medicationsFromPost($post),
            'dynamic_fields' => self::dynamicFieldsFromPost($post),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function medicationsFromPost(array $post): array
    {
        $drugNames = (array)($post['drug_name'] ?? []);
        $rows = [];
        foreach ($drugNames as $i => $drugName) {
            $rows[] = [
                'sort_order' => $i + 1,
                'drug_name' => (string)$drugName,
                'generic_name' => (string)(($post['generic_name'] ?? [])[$i] ?? ''),
                'brand_name' => (string)(($post['brand_name'] ?? [])[$i] ?? ''),
                'raw_drug_text' => (string)(($post['raw_drug_text'] ?? [])[$i] ?? ''),
                'ai_drug_name' => (string)(($post['ai_drug_name'] ?? [])[$i] ?? ''),
                'ai_generic_name' => (string)(($post['ai_generic_name'] ?? [])[$i] ?? ''),
                'ai_brand_name' => (string)(($post['ai_brand_name'] ?? [])[$i] ?? ''),
                'dose_text' => (string)(($post['dose_text'] ?? [])[$i] ?? ''),
                'usage_text' => (string)(($post['usage_text'] ?? [])[$i] ?? ''),
                'days_count' => (string)(($post['days_count'] ?? [])[$i] ?? ''),
                'amount_text' => (string)(($post['amount_text'] ?? [])[$i] ?? ''),
                'stock_status' => (string)(($post['stock_status'] ?? [])[$i] ?? ''),
                'drug_name_relation_type' => (string)(($post['drug_name_relation_type'] ?? [])[$i] ?? ''),
            ];
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private static function dynamicFieldsFromPost(array $post): array
    {
        $keys = (array)($post['dynamic_field_key'] ?? $post['original_dynamic_key'] ?? []);
        $rows = [];
        foreach ($keys as $i => $key) {
            $rows[] = [
                'field_key' => (string)$key,
                'field_label' => (string)(($post['dynamic_field_label'] ?? $post['original_dynamic_label'] ?? [])[$i] ?? ''),
                'field_group' => (string)(($post['dynamic_field_group'] ?? $post['original_dynamic_group'] ?? [])[$i] ?? ''),
                'field_value' => (string)(($post['dynamic_field_value'] ?? $post['original_dynamic_value'] ?? [])[$i] ?? ''),
                'source_ai_value' => (string)(($post['dynamic_field_ai_value'] ?? $post['original_dynamic_ai_value'] ?? [])[$i] ?? ''),
                'source_section' => (string)(($post['dynamic_field_source_section'] ?? $post['original_dynamic_source_section'] ?? [])[$i] ?? ''),
                'confidence' => (string)(($post['dynamic_field_confidence'] ?? $post['original_dynamic_confidence'] ?? [])[$i] ?? ''),
                'selected' => isset(($post['dynamic_field_selected'] ?? [])[$i]),
                'include_for_output' => isset(($post['dynamic_field_output_candidate'] ?? [])[$i]),
            ];
        }
        return $rows;
    }

    private function ensureTable(PDO $pdo): bool
    {
        if (Db::tableExists($pdo, self::TABLE)) {
            return true;
        }
        if (!(bool)app_config('prescription_debug.auto_create_table', true)) {
            return false;
        }
        if ($pdo->inTransaction()) {
            // MySQLのDDLは暗黙COMMITになり得るため、処方箋保存中の自動作成は避ける。
            return false;
        }
        try {
            $pdo->exec(self::createTableSql());
            return Db::tableExists($pdo, self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    public static function createTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_uid VARCHAR(32) NOT NULL,
            branch_uid VARCHAR(32) NOT NULL,
            tenant_id INT NOT NULL,
            parse_job_id BIGINT UNSIGNED NULL,
            prescription_id BIGINT UNSIGNED NULL,
            stage VARCHAR(64) NOT NULL,
            stage_label VARCHAR(160) NOT NULL,
            model_name VARCHAR(120) NULL,
            content_type VARCHAR(40) NOT NULL DEFAULT "json",
            snapshot_json LONGTEXT NULL,
            snapshot_text LONGTEXT NULL,
            source_hash CHAR(64) NULL,
            created_by_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_prescription_io_debug_job (tenant_id, parse_job_id, created_at),
            KEY idx_prescription_io_debug_prescription (tenant_id, prescription_id, created_at),
            KEY idx_prescription_io_debug_stage (tenant_id, stage, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
}
