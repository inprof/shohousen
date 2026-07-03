<?php
declare(strict_types=1);

final class PrescriptionCorrectionService
{
    public function __construct(private readonly PrescriptionKnowledgeService $knowledge = new PrescriptionKnowledgeService()) {}

    public function applyCandidates(array $normalized): array
    {
        $result = $normalized;
        $result['_correction_candidates'] = [];

        foreach (($result['medications'] ?? []) as $i => $med) {
            $drugName = trim((string)($med['drug_name'] ?? ''));
            if ($drugName === '') {
                continue;
            }
            $candidates = [];
            foreach ($this->knowledge->findCorrectionRules('drug_name', $drugName) as $rule) {
                $candidates[] = [
                    'field_path' => 'medications[' . $i . '].drug_name',
                    'field_type' => 'drug_name',
                    'original_value' => $drugName,
                    'candidate_value' => (string)$rule['correct_value'],
                    'candidate_source' => 'past_correction',
                    'score' => (float)($rule['precision_rate'] ?? 80),
                    'reason' => '過去の人間修正履歴に一致',
                ];
            }
            foreach ($this->knowledge->findDrugCandidates($drugName) as $candidate) {
                $name = (string)($candidate['drug_name'] ?? '');
                if ($name !== '' && $name !== $drugName) {
                    $reason = (string)($candidate['reason'] ?? '');
                    if ($reason === '') {
                        $reason = '薬品マスタ/一般名・HOT9辞書に一致';
                    }
                    $meta = [];
                    foreach (['yj_code','hot9_code','generic_code','generic_name','alias_name','alias_type','relation_confidence','brand_class'] as $key) {
                        if (isset($candidate[$key]) && (string)$candidate[$key] !== '') {
                            $meta[$key] = $candidate[$key];
                        }
                    }
                    $candidates[] = [
                        'field_path' => 'medications[' . $i . '].drug_name',
                        'field_type' => 'drug_name',
                        'original_value' => $drugName,
                        'candidate_value' => $name,
                        'candidate_source' => (string)($candidate['candidate_source'] ?? 'drug_dictionary'),
                        'score' => (float)($candidate['score'] ?? 92.0),
                        'reason' => $reason,
                        'dictionary_meta' => $meta,
                    ];
                }
            }

            // Common OCR mistake: zero + D instead of capital O + D. Candidate only, not auto-confirmed.
            if (str_contains($drugName, '0D')) {
                $candidates[] = [
                    'field_path' => 'medications[' . $i . '].drug_name',
                    'field_type' => 'drug_name',
                    'original_value' => $drugName,
                    'candidate_value' => str_replace('0D', 'OD', $drugName),
                    'candidate_source' => 'built_in_rule',
                    'score' => 82.0,
                    'reason' => '0D/ODの誤読候補',
                ];
            }

            $result['_correction_candidates']['medications'][$i]['drug_name'] = self::uniqueCandidates($candidates);
        }

        return $result;
    }



    public function persistCandidates(int $parseJobId, int $tenantId, array $normalized): void
    {
        $candidates = $normalized['_correction_candidates']['medications'] ?? [];
        if (!$candidates) {
            return;
        }
        $pdo = Db::branch();
        try {
            $pdo->prepare('DELETE FROM prescription_correction_candidates WHERE parse_job_id = :parse_job_id')
                ->execute([':parse_job_id' => $parseJobId]);
            $stmt = $pdo->prepare('INSERT INTO prescription_correction_candidates
                (parse_job_id, company_uid, branch_uid, tenant_id, field_path, field_type, original_value, candidate_value, candidate_source, score, reason, was_selected, was_rejected, created_at)
                VALUES (:parse_job_id, :company_uid, :branch_uid, :tenant_id, :field_path, :field_type, :original_value, :candidate_value, :candidate_source, :score, :reason, 0, 0, NOW())');
            foreach ($candidates as $medCandidates) {
                foreach (($medCandidates['drug_name'] ?? []) as $candidate) {
                    $stmt->execute([
                        ':parse_job_id' => $parseJobId,
                        ':company_uid' => current_company_uid(),
                        ':branch_uid' => current_branch_uid(),
                        ':tenant_id' => $tenantId,
                        ':field_path' => (string)($candidate['field_path'] ?? ''),
                        ':field_type' => (string)($candidate['field_type'] ?? ''),
                        ':original_value' => (string)($candidate['original_value'] ?? ''),
                        ':candidate_value' => (string)($candidate['candidate_value'] ?? ''),
                        ':candidate_source' => (string)($candidate['candidate_source'] ?? ''),
                        ':score' => (float)($candidate['score'] ?? 0),
                        ':reason' => mb_substr((string)($candidate['reason'] ?? '') . (!empty($candidate['dictionary_meta']) ? ' / meta=' . json_encode($candidate['dictionary_meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''), 0, 1000),
                    ]);
                }
            }
        } catch (Throwable) {
            // 候補保存に失敗しても確認画面表示は継続する。
        }
    }

    /** @param array<int,array<string,mixed>> $candidates */
    private static function uniqueCandidates(array $candidates): array
    {
        $seen = [];
        $out = [];
        foreach ($candidates as $candidate) {
            $key = (string)$candidate['candidate_value'];
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $candidate;
        }
        usort($out, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']));
        return array_slice($out, 0, 3);
    }
}
