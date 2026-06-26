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
                $name = (string)$candidate['drug_name'];
                if ($name !== $drugName) {
                    $candidates[] = [
                        'field_path' => 'medications[' . $i . '].drug_name',
                        'field_type' => 'drug_name',
                        'original_value' => $drugName,
                        'candidate_value' => $name,
                        'candidate_source' => 'drug_master',
                        'score' => 95.0,
                        'reason' => '薬品マスタ/別名に一致',
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
