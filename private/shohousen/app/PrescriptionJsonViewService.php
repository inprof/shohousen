<?php
declare(strict_types=1);

final class PrescriptionJsonViewService
{
    /** @return array<int,array<string,mixed>> */
    public function latestJobs(int $tenantId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $pdo = Db::branch();
        $stmt = $pdo->prepare('SELECT id, prescription_id, status, model_name, overall_confidence, error_message, created_at, analyzed_at, updated_at
            FROM prescription_parse_jobs
            WHERE tenant_id = :tenant_id
            ORDER BY id DESC
            LIMIT ' . $limit);
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function latestJob(int $tenantId): ?array
    {
        $pdo = Db::branch();
        $stmt = $pdo->prepare('SELECT * FROM prescription_parse_jobs WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function getJob(int $tenantId, int $jobId): ?array
    {
        $pdo = Db::branch();
        $stmt = $pdo->prepare('SELECT * FROM prescription_parse_jobs WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $jobId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function getMetrics(int $tenantId, int $jobId): ?array
    {
        $pdo = Db::branch();
        if (!Db::tableExists($pdo, 'prescription_parse_metrics')) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM prescription_parse_metrics WHERE tenant_id = :tenant_id AND parse_job_id = :job_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId, ':job_id' => $jobId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed> */
    public function normalizeForDisplay(array $job): array
    {
        $raw = $this->decodeJsonColumn((string)($job['raw_response_json'] ?? ''));
        $normalized = $this->decodeJsonColumn((string)($job['normalized_json'] ?? ''));
        $source = 'normalized_json';
        if (!$normalized) {
            $normalized = $this->extractOutputJson($raw);
            $source = $normalized ? 'raw_response_json.output_text' : 'none';
        }

        $normalized = is_array($normalized) ? $normalized : [];
        $formFields = $this->normalizeFormFields($normalized['form_fields'] ?? []);

        return [
            'source' => $source,
            'model' => $this->extractModel($job, $raw),
            'usage' => $this->extractUsage($raw),
            'patient' => $this->normalizePatient($normalized, $formFields),
            'insurance' => $this->normalizeInsurance($normalized, $formFields),
            'public_expense' => $this->normalizePublicExpense($formFields),
            'prescription' => $this->normalizePrescription($normalized, $formFields),
            'medical_institution' => $this->normalizeMedicalInstitution($normalized, $formFields),
            'medications' => $this->normalizeMedications($normalized['medications'] ?? []),
            'form_fields' => $formFields,
            'warnings' => array_values(array_filter(array_map('strval', is_array($normalized['warnings'] ?? null) ? $normalized['warnings'] : []))),
            'overall_confidence' => $this->numberOrNull($normalized['overall_confidence'] ?? ($job['overall_confidence'] ?? null)),
            'raw_output_text' => $this->extractOutputText($raw),
            'normalized_json_pretty' => $this->prettyJson($normalized),
            'raw_response_json_pretty' => $this->prettyJson($raw),
        ];
    }

    /** @return array<string,mixed> */
    private function extractModel(array $job, array $raw): array
    {
        return [
            'job_model_name' => (string)($job['model_name'] ?? ''),
            'raw_response_model' => (string)($raw['model'] ?? ''),
            'raw_response_id' => (string)($raw['id'] ?? ''),
            'created_at' => (string)($job['created_at'] ?? ''),
            'analyzed_at' => (string)($job['analyzed_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function extractUsage(array $raw): array
    {
        $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];
        return [
            'input_tokens' => (int)($usage['input_tokens'] ?? 0),
            'output_tokens' => (int)($usage['output_tokens'] ?? 0),
            'total_tokens' => (int)($usage['total_tokens'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizePatient(array $n, array $fields): array
    {
        $p = is_array($n['patient'] ?? null) ? $n['patient'] : [];
        return [
            '氏名' => $this->firstValue($p['name'] ?? null, $this->fieldValue($fields, ['patient_name', 'patient.name', 'name'])),
            'フリガナ' => $this->firstValue($p['kana'] ?? null, $this->fieldValue($fields, ['patient_kana', 'patient.kana', 'kana'])),
            '生年月日' => $this->firstValue($p['birth_date'] ?? null, $this->fieldValue($fields, ['patient_birth_date', 'patient.birth_date', 'birth_date'])),
            '性別' => $this->firstValue($p['gender'] ?? null, $this->fieldValue($fields, ['patient_gender', 'patient.gender', 'gender'])),
            '信頼度' => $this->numberOrNull($p['confidence'] ?? null),
            '要確認' => !empty($p['needs_human_check']),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeInsurance(array $n, array $fields): array
    {
        $i = is_array($n['insurance'] ?? null) ? $n['insurance'] : [];
        return [
            '保険者番号' => $this->firstValue($i['insurance_no'] ?? null, $this->fieldValue($fields, ['insurance_no', 'insurance.insurance_no'])),
            '記号・番号' => $this->firstValue($i['insured_symbol_number'] ?? null, $this->fieldValue($fields, ['insured_symbol_number', 'insurance.insured_symbol_number'])),
            '負担割合' => $this->firstValue($i['copay_rate'] ?? null, $this->fieldValue($fields, ['copay_rate', 'insurance.copay_rate'])),
            '信頼度' => $this->numberOrNull($i['confidence'] ?? null),
            '要確認' => !empty($i['needs_human_check']),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizePublicExpense(array $fields): array
    {
        return [
            '公費負担者番号' => $this->firstValue(
                $this->fieldValue($fields, ['public_expense_payer_number', 'public_expense.payer_number']),
                $this->findFieldByLabel($fields, ['公費負担者番号'])
            ),
            '公費受給者番号' => $this->firstValue(
                $this->fieldValue($fields, ['public_expense_beneficiary_number', 'public_expense.beneficiary_number', 'public_expense_medical_beneficiary_number']),
                $this->findFieldByLabel($fields, ['公費負担医療の受給者番号', '公費受給者番号'])
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizePrescription(array $n, array $fields): array
    {
        $p = is_array($n['prescription'] ?? null) ? $n['prescription'] : [];
        return [
            '交付年月日' => $this->firstValue($p['issued_on'] ?? null, $this->fieldValue($fields, ['issued_on', 'prescription.issued_on'])),
            '使用期限' => $this->firstValue($p['expires_on'] ?? null, $this->fieldValue($fields, ['expires_on', 'prescription.expires_on'])),
            '変更不可' => $this->firstValue($this->fieldValue($fields, ['change_not_allowed', 'no_changes_needed']), ''),
            '信頼度' => $this->numberOrNull($p['confidence'] ?? null),
            '要確認' => !empty($p['needs_human_check']),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeMedicalInstitution(array $n, array $fields): array
    {
        $m = is_array($n['medical_institution'] ?? null) ? $n['medical_institution'] : [];
        return [
            '医療機関コード' => $this->firstValue($m['code'] ?? null, $this->fieldValue($fields, ['medical_institution_code', 'medical_institution.code'])),
            '医療機関名' => $this->firstValue($m['name'] ?? null, $this->fieldValue($fields, ['medical_institution_name', 'medical_institution.name'])),
            '医師名' => $this->firstValue($m['doctor_name'] ?? null, $this->fieldValue($fields, ['doctor_name', 'medical_institution.doctor_name'])),
            '電話番号' => $this->firstValue($m['phone'] ?? null, $this->fieldValue($fields, ['medical_institution_phone', 'phone', 'medical_institution.phone'])),
            '信頼度' => $this->numberOrNull($m['confidence'] ?? null),
            '要確認' => !empty($m['needs_human_check']),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeMedications(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'No' => $index + 1,
                '薬品名' => (string)($row['drug_name'] ?? ''),
                '一般名' => (string)($row['generic_name'] ?? ''),
                '商品名' => (string)($row['brand_name'] ?? ''),
                '元テキスト' => (string)($row['raw_drug_text'] ?? ''),
                '用量' => (string)($row['dose_text'] ?? ''),
                '用法' => (string)($row['usage_text'] ?? ''),
                '日数' => $row['days_count'] ?? null,
                '総量' => (string)($row['amount_text'] ?? ''),
                '信頼度' => $this->numberOrNull($row['confidence'] ?? null),
                '要確認' => !empty($row['needs_human_check']),
                '理由' => (string)($row['reason'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeFormFields(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'field_key' => (string)($row['field_key'] ?? ''),
                'field_label' => (string)($row['field_label'] ?? ''),
                'field_group' => (string)($row['field_group'] ?? 'other'),
                'value' => (string)($row['value'] ?? ''),
                'value_type' => (string)($row['value_type'] ?? 'unknown'),
                'source_section' => (string)($row['source_section'] ?? ''),
                'confidence' => $this->numberOrNull($row['confidence'] ?? null),
                'needs_human_check' => !empty($row['needs_human_check']),
                'include_default' => !empty($row['include_default']),
                'output_candidate' => !empty($row['output_candidate']),
                'display_order' => (int)($row['display_order'] ?? 9999),
                'reason' => (string)($row['reason'] ?? ''),
            ];
        }
        usort($out, static fn(array $a, array $b): int => ((int)$a['display_order'] <=> (int)$b['display_order']) ?: strcmp((string)$a['field_key'], (string)$b['field_key']));
        return $out;
    }

    /** @return array<string,mixed> */
    private function decodeJsonColumn(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private function extractOutputJson(array $raw): array
    {
        $text = $this->extractOutputText($raw);
        if ($text === '') {
            return [];
        }
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function extractOutputText(array $raw): string
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
        return trim(implode("\n", array_filter($chunks, static fn(string $v): bool => $v !== '')));
    }

    private function fieldValue(array $fields, array $keys): string
    {
        $lookup = array_flip($keys);
        foreach ($fields as $field) {
            $key = (string)($field['field_key'] ?? '');
            if (isset($lookup[$key])) {
                return (string)($field['value'] ?? '');
            }
        }
        return '';
    }

    private function findFieldByLabel(array $fields, array $labels): string
    {
        foreach ($fields as $field) {
            $label = (string)($field['field_label'] ?? '');
            foreach ($labels as $needle) {
                if ($label !== '' && str_contains($label, $needle)) {
                    return (string)($field['value'] ?? '');
                }
            }
        }
        return '';
    }

    private function firstValue(mixed ...$values): string
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            $string = trim((string)$value);
            if ($string !== '') {
                return $string;
            }
        }
        return '';
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private function prettyJson(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
    }
}
