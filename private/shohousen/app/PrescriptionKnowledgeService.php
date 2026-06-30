<?php
declare(strict_types=1);

final class PrescriptionKnowledgeService
{
    public function findTemplate(?string $layoutFingerprint = null): ?array
    {
        if (!$layoutFingerprint) {
            return null;
        }

        $sql = 'SELECT * FROM prescription_templates
                WHERE is_active = 1
                  AND layout_fingerprint = :layout_fingerprint
                  AND (
                    scope_type = "global"
                    OR company_uid = :company_uid
                    OR branch_uid = :branch_uid
                  )
                ORDER BY
                  CASE scope_type WHEN "branch" THEN 1 WHEN "company" THEN 2 ELSE 3 END,
                  success_count DESC,
                  avg_correction_rate ASC,
                  id DESC
                LIMIT 1';
        try {
            $stmt = Db::knowledge()->prepare($sql);
            $stmt->execute([
                ':layout_fingerprint' => $layoutFingerprint,
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
            ]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $row['field_map'] = json_decode((string)($row['field_map_json'] ?? '{}'), true) ?: [];
            return $row;
        } catch (Throwable) {
            // レンタルSVでknowledge DB未適用の間もOCR本体を止めない。
            return null;
        }
    }

    /** @param array<string,mixed> $detected */
    public function saveTemplateMatchLog(int $parseJobId, int $tenantId, ?int $templateId, ?float $score, int $detectionMs, string $result): void
    {
        try {
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_template_match_logs
                (parse_job_id, company_uid, branch_uid, tenant_id, matched_template_id, match_score, detection_ms, result, created_at)
                VALUES (:parse_job_id, :company_uid, :branch_uid, :tenant_id, :matched_template_id, :match_score, :detection_ms, :result, NOW())');
            $stmt->execute([
                ':parse_job_id' => $parseJobId,
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':matched_template_id' => $templateId,
                ':match_score' => $score,
                ':detection_ms' => $detectionMs,
                ':result' => in_array($result, ['matched', 'unknown', 'fallback', 'failed'], true) ? $result : 'unknown',
            ]);
        } catch (Throwable) {
            // ナレッジDB未準備時はログだけ失敗扱いで処理継続する。
        }
    }

    /** @param array<string,mixed> $detected */
    public function saveTemplateCandidate(int $parseJobId, int $tenantId, string $fingerprint, array $detected): void
    {
        if ($fingerprint === '') {
            return;
        }
        try {
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_template_candidates
                (company_uid, branch_uid, tenant_id, parse_job_id, detected_fingerprint, ai_field_map_json, human_fixed_field_map_json, match_count, status, created_at, updated_at)
                VALUES (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :detected_fingerprint, :ai_field_map_json, NULL, 1, "candidate", NOW(), NOW())
                ON DUPLICATE KEY UPDATE match_count = match_count + 1, updated_at = NOW()');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':parse_job_id' => $parseJobId,
                ':detected_fingerprint' => $fingerprint,
                ':ai_field_map_json' => json_encode($detected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
        }
    }

    /** @return array<int, array<string,mixed>> */
    public function findCorrectionRules(string $fieldType, string $value): array
    {
        if ($value === '') {
            return [];
        }
        $sql = 'SELECT * FROM prescription_auto_correction_rules
                WHERE is_active = 1
                  AND field_type = :field_type
                  AND wrong_value = :wrong_value
                  AND (scope_type = "global" OR company_uid = :company_uid OR branch_uid = :branch_uid)
                ORDER BY
                  CASE scope_type WHEN "branch" THEN 1 WHEN "company" THEN 2 ELSE 3 END,
                  precision_rate DESC, support_count DESC
                LIMIT 5';
        try {
            $stmt = Db::knowledge()->prepare($sql);
            $stmt->execute([
                ':field_type' => $fieldType,
                ':wrong_value' => $value,
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
            ]);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string,mixed>> */
    public function findDrugCandidates(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        try {
            $stmt = Db::knowledge()->prepare('SELECT dm.drug_name, da.alias_name
                FROM drug_aliases da
                INNER JOIN drug_master dm ON dm.id = da.drug_master_id
                WHERE da.alias_name = :value OR dm.drug_name = :value
                LIMIT 5');
            $stmt->execute([':value' => $value]);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    public function upsertCorrectionRule(string $fieldType, string $wrongValue, string $correctValue): void
    {
        $wrongValue = trim($wrongValue);
        $correctValue = trim($correctValue);
        if ($wrongValue === '' || $correctValue === '' || $wrongValue === $correctValue) {
            return;
        }
        // 患者・保険など個人性/請求影響が強いものはナレッジDBへ昇格しない。
        if (!in_array($fieldType, ['drug_name', 'usage_text', 'medical_institution_name'], true)) {
            return;
        }
        $sql = 'INSERT INTO prescription_auto_correction_rules
                (company_uid, branch_uid, scope_type, field_type, wrong_value, correct_value, support_count, success_count, failure_count, precision_rate, is_active, created_at, updated_at)
                VALUES (:company_uid, :branch_uid, "branch", :field_type, :wrong_value, :correct_value, 1, 1, 0, 100.00, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE support_count = support_count + 1, success_count = success_count + 1, precision_rate = (success_count / GREATEST(support_count, 1)) * 100, updated_at = NOW()';
        try {
            $stmt = Db::knowledge()->prepare($sql);
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':field_type' => $fieldType,
                ':wrong_value' => $wrongValue,
                ':correct_value' => $correctValue,
            ]);
        } catch (Throwable) {
        }
    }
}
