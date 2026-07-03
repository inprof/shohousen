<?php
declare(strict_types=1);

final class PrescriptionFeedbackService
{
    public function __construct(private readonly PrescriptionKnowledgeService $knowledge = new PrescriptionKnowledgeService()) {}

    public function saveCorrections(int $parseJobId, int $tenantId, array $aiNormalized, array $finalPost): void
    {
        $rows = [];
        $map = [
            'patient.name' => ['field_type' => 'patient_name', 'ai' => $aiNormalized['patient']['name'] ?? '', 'final' => $finalPost['patient_name'] ?? ''],
            'patient.birth_date' => ['field_type' => 'birth_date', 'ai' => $aiNormalized['patient']['birth_date'] ?? '', 'final' => $finalPost['birth_date'] ?? ''],
            'insurance.insurance_no' => ['field_type' => 'insurance_no', 'ai' => $aiNormalized['insurance']['insurance_no'] ?? '', 'final' => $finalPost['insurance_no'] ?? ''],
            'insurance.insured_symbol_number' => ['field_type' => 'insured_symbol_number', 'ai' => $aiNormalized['insurance']['insured_symbol_number'] ?? '', 'final' => $finalPost['insured_symbol_number'] ?? ''],
            'prescription.issued_on' => ['field_type' => 'issued_on', 'ai' => $aiNormalized['prescription']['issued_on'] ?? '', 'final' => $finalPost['issued_on'] ?? ''],
            'medical_institution.name' => ['field_type' => 'medical_institution_name', 'ai' => $aiNormalized['medical_institution']['name'] ?? '', 'final' => $finalPost['medical_institution_name'] ?? ''],
        ];
        foreach ($map as $path => $v) {
            $this->appendIfChanged($rows, $path, $v['field_type'], (string)$v['ai'], (string)$v['final'], null);
        }

        $aiMeds = $aiNormalized['medications'] ?? [];
        $drugNames = $finalPost['drug_name'] ?? [];
        $genericNames = $finalPost['generic_name'] ?? [];
        $brandNames = $finalPost['brand_name'] ?? [];
        $rawDrugTexts = $finalPost['raw_drug_text'] ?? [];
        $usageTexts = $finalPost['usage_text'] ?? [];
        $daysCounts = $finalPost['days_count'] ?? [];
        $amountTexts = $finalPost['amount_text'] ?? [];
        foreach ($drugNames as $i => $finalDrug) {
            $aiMed = $aiMeds[$i] ?? [];
            $this->appendIfChanged($rows, 'medications[' . $i . '].drug_name', 'drug_name', (string)($aiMed['drug_name'] ?? ''), (string)$finalDrug, $aiMed['confidence'] ?? null);
            $this->appendIfChanged($rows, 'medications[' . $i . '].generic_name', 'drug_generic_name', (string)($aiMed['generic_name'] ?? ''), (string)($genericNames[$i] ?? ''), $aiMed['confidence'] ?? null);
            $this->appendIfChanged($rows, 'medications[' . $i . '].brand_name', 'drug_brand_name', (string)($aiMed['brand_name'] ?? ''), (string)($brandNames[$i] ?? ''), $aiMed['confidence'] ?? null);
            $this->appendIfChanged($rows, 'medications[' . $i . '].raw_drug_text', 'drug_raw_text', (string)($aiMed['raw_drug_text'] ?? ''), (string)($rawDrugTexts[$i] ?? ''), $aiMed['confidence'] ?? null);
            $this->appendIfChanged($rows, 'medications[' . $i . '].usage_text', 'usage_text', (string)($aiMed['usage_text'] ?? ''), (string)($usageTexts[$i] ?? ''), $aiMed['confidence'] ?? null);
            $this->appendIfChanged($rows, 'medications[' . $i . '].days_count', 'days_count', (string)($aiMed['days_count'] ?? ''), (string)($daysCounts[$i] ?? ''), $aiMed['confidence'] ?? null);
            $this->appendIfChanged($rows, 'medications[' . $i . '].amount_text', 'amount_text', (string)($aiMed['amount_text'] ?? ''), (string)($amountTexts[$i] ?? ''), $aiMed['confidence'] ?? null);
        }

        if (!$rows) {
            return;
        }
        $stmt = Db::branch()->prepare('INSERT INTO prescription_field_corrections
            (parse_job_id, company_uid, branch_uid, tenant_id, field_path, field_type, ai_value, final_value, confidence_before, edit_distance, correction_type, created_at)
            VALUES (:parse_job_id, :company_uid, :branch_uid, :tenant_id, :field_path, :field_type, :ai_value, :final_value, :confidence_before, :edit_distance, :correction_type, NOW())');
        foreach ($rows as $row) {
            $stmt->execute([
                ':parse_job_id' => $parseJobId,
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':field_path' => $row['field_path'],
                ':field_type' => $row['field_type'],
                ':ai_value' => $row['ai_value'],
                ':final_value' => $row['final_value'],
                ':confidence_before' => $row['confidence'],
                ':edit_distance' => levenshtein(mb_substr($row['ai_value'], 0, 255), mb_substr($row['final_value'], 0, 255)),
                ':correction_type' => 'human_edit',
            ]);

            // 個人特定性が低く、補正効果が高い項目だけ補助学習型DBへ反映する。
            if (in_array($row['field_type'], ['drug_name', 'drug_generic_name', 'drug_brand_name', 'drug_raw_text', 'usage_text', 'medical_institution_name'], true)) {
                $this->knowledge->upsertCorrectionRule($row['field_type'], $row['ai_value'], $row['final_value'], $row['confidence']);
            }
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function appendIfChanged(array &$rows, string $path, string $fieldType, string $ai, string $final, mixed $confidence): void
    {
        $ai = trim($ai);
        $final = trim($final);
        if ($ai === $final || $final === '') {
            return;
        }
        $rows[] = [
            'field_path' => $path,
            'field_type' => $fieldType,
            'ai_value' => $ai,
            'final_value' => $final,
            'confidence' => is_numeric($confidence) ? (float)$confidence : null,
        ];
    }
}
