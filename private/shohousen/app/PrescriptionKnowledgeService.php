<?php
declare(strict_types=1);

final class PrescriptionKnowledgeService
{
    public function findTemplate(?string $layoutFingerprint = null): ?array
    {
        // MVPではテンプレート自動判定は未実装。DB枠だけ先に用意し、未知テンプレートとして全体解析する。
        return null;
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
        $stmt = Db::knowledge()->prepare($sql);
        $stmt->execute([
            ':field_type' => $fieldType,
            ':wrong_value' => $value,
            ':company_uid' => current_company_uid(),
            ':branch_uid' => current_branch_uid(),
        ]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public function findDrugCandidates(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $stmt = Db::knowledge()->prepare('SELECT dm.drug_name, da.alias_name
            FROM drug_aliases da
            INNER JOIN drug_master dm ON dm.id = da.drug_master_id
            WHERE da.alias_name = :value OR dm.drug_name = :value
            LIMIT 5');
        $stmt->execute([':value' => $value]);
        return $stmt->fetchAll();
    }

    public function upsertCorrectionRule(string $fieldType, string $wrongValue, string $correctValue): void
    {
        $wrongValue = trim($wrongValue);
        $correctValue = trim($correctValue);
        if ($wrongValue === '' || $correctValue === '' || $wrongValue === $correctValue) {
            return;
        }
        $sql = 'INSERT INTO prescription_auto_correction_rules
                (company_uid, branch_uid, scope_type, field_type, wrong_value, correct_value, support_count, success_count, failure_count, precision_rate, is_active, created_at, updated_at)
                VALUES (:company_uid, :branch_uid, "branch", :field_type, :wrong_value, :correct_value, 1, 1, 0, 100.00, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE support_count = support_count + 1, success_count = success_count + 1, precision_rate = (success_count / GREATEST(support_count, 1)) * 100, updated_at = NOW()';
        $stmt = Db::knowledge()->prepare($sql);
        $stmt->execute([
            ':company_uid' => current_company_uid(),
            ':branch_uid' => current_branch_uid(),
            ':field_type' => $fieldType,
            ':wrong_value' => $wrongValue,
            ':correct_value' => $correctValue,
        ]);
    }
}
