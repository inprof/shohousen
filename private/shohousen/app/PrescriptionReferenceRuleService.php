<?php
declare(strict_types=1);

/**
 * 処方箋OCRで使う制度系の参照ルール。
 * PDF資料から抽出したJSONを読み、日付・保険者番号・公費番号・医療機関コードの
 * 正規化/妥当性チェックに使う。モデルを変えても使い回す基準データとして扱う。
 */
final class PrescriptionReferenceRuleService
{
    private const RULE_DIR = __DIR__ . '/../data/prescription_rules';

    /** @var array<string,mixed>|null */
    private static ?array $eraRules = null;
    /** @var array<string,mixed>|null */
    private static ?array $codeRules = null;

    /** @return array<string,mixed> */
    public static function eraRules(): array
    {
        if (self::$eraRules !== null) {
            return self::$eraRules;
        }
        self::$eraRules = self::readJson('japanese_era_rules.json');
        return self::$eraRules;
    }

    /** @return array<string,mixed> */
    public static function codeRules(): array
    {
        if (self::$codeRules !== null) {
            return self::$codeRules;
        }
        self::$codeRules = self::readJson('insurance_public_medical_code_rules.json');
        return self::$codeRules;
    }

    /** @return array<string,mixed> */
    private static function readJson(string $file): array
    {
        $path = self::RULE_DIR . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $json = json_decode((string)file_get_contents($path), true);
        return is_array($json) ? $json : [];
    }

    /** @return array<string,mixed> */
    public static function normalizeDate(string $value): array
    {
        $raw = trim($value);
        if ($raw === '') {
            return ['raw' => $raw, 'normalized' => null, 'status' => 'empty', 'needs_human_check' => false, 'message' => ''];
        }

        $ascii = mb_convert_kana($raw, 'asKV');
        $ascii = str_replace(['　', '／', '．', '－', 'ー'], [' ', '/', '.', '-', '-'], $ascii);
        $compact = preg_replace('/\s+/', '', $ascii) ?? $ascii;
        $normalizedSeparators = str_replace(['年', '月', '日', '.'], ['/', '/', '', '/'], $compact);

        // 西暦: 2024/01/20, 2024-01-20, 20240120
        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $normalizedSeparators, $m)
            || preg_match('/^(\d{4})(\d{2})(\d{2})$/', $normalizedSeparators, $m)) {
            return self::dateResult($raw, (int)$m[1], (int)$m[2], (int)$m[3], 'western');
        }

        // 2桁年は西暦/和暦を確定できないため要確認。例: 16-03-27
        if (preg_match('/^\d{1,2}[-\/]\d{1,2}[-\/]\d{1,2}$/', $normalizedSeparators)
            || preg_match('/^\d{6}$/', $normalizedSeparators)) {
            return ['raw' => $raw, 'normalized' => null, 'status' => 'ambiguous_two_digit_year', 'needs_human_check' => true, 'message' => '2桁年は西暦・和暦を確定できません。元号または西暦4桁を確認してください。'];
        }

        $eras = self::eraRules()['eras'] ?? [];
        $eraAlternatives = [];
        foreach ((array)$eras as $era) {
            $names = array_merge([(string)($era['kanji'] ?? '')], (array)($era['symbols'] ?? []));
            foreach ($names as $name) {
                if ($name !== '') {
                    $eraAlternatives[preg_quote($name, '/')] = $era;
                }
            }
        }
        if ($eraAlternatives) {
            $pattern = '/^(' . implode('|', array_keys($eraAlternatives)) . ')(?:元|(\d{1,2}))[年\/.\-]?(\d{1,2})[月\/.\-]?(\d{1,2})日?$/u';
            if (preg_match($pattern, $compact, $m)) {
                $eraToken = $m[1];
                $era = $eraAlternatives[preg_quote($eraToken, '/')] ?? null;
                if (is_array($era)) {
                    $yearInEra = (($m[2] ?? '') === '') ? 1 : (int)$m[2];
                    $year = (int)($era['base_year'] ?? 0) + $yearInEra;
                    return self::dateResult($raw, $year, (int)$m[3], (int)$m[4], (string)($era['key'] ?? 'era'), $era);
                }
            }
        }

        return ['raw' => $raw, 'normalized' => null, 'status' => 'unrecognized', 'needs_human_check' => true, 'message' => '日付形式を判定できません。原画像を確認してください。'];
    }

    /** @param array<string,mixed>|null $era @return array<string,mixed> */
    private static function dateResult(string $raw, int $year, int $month, int $day, string $source, ?array $era = null): array
    {
        if (!checkdate($month, $day, $year)) {
            return ['raw' => $raw, 'normalized' => null, 'status' => 'invalid_date', 'needs_human_check' => true, 'message' => '存在しない日付です。'];
        }
        $normalized = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if ($era) {
            $start = (string)($era['start_date'] ?? '');
            $end = (string)($era['end_date'] ?? '');
            if ($start !== '' && strcmp($normalized, $start) < 0) {
                return ['raw' => $raw, 'normalized' => $normalized, 'status' => 'outside_era_range', 'needs_human_check' => true, 'message' => '元号の開始日前の日付です。読取値を確認してください。'];
            }
            if ($end !== '' && strcmp($normalized, $end) > 0) {
                return ['raw' => $raw, 'normalized' => $normalized, 'status' => 'outside_era_range', 'needs_human_check' => true, 'message' => '元号の終了日後の日付です。読取値を確認してください。'];
            }
        }
        return ['raw' => $raw, 'normalized' => $normalized, 'status' => 'valid', 'needs_human_check' => false, 'message' => '', 'source' => $source];
    }

    public static function digitsOnly(string $value): string
    {
        $value = mb_convert_kana($value, 'n');
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /** @return array<string,mixed> */
    public static function validateCode(string $type, string $value): array
    {
        $digits = self::digitsOnly($value);
        $raw = trim($value);
        if ($raw === '' && $digits === '') {
            return ['type' => $type, 'raw' => $raw, 'digits' => '', 'status' => 'empty', 'valid' => false, 'needs_human_check' => false, 'message' => ''];
        }

        return match ($type) {
            'insurance_no' => self::validateByLengths($type, $raw, $digits, [6, 8], '保険者番号は6桁または8桁です。別欄の番号を混ぜて読んでいる可能性があります。'),
            'public_payer_no' => self::validateByLengths($type, $raw, $digits, [8], '公費負担者番号は8桁です。'),
            'public_beneficiary_no' => self::validateByLengths($type, $raw, $digits, [7], '公費負担医療の受給者番号は7桁です。'),
            'medical_institution_code' => self::validateByLengths($type, $raw, $digits, [7, 10], '医療機関等コードは通常7桁です。10桁の場合は都道府県番号2桁+点数表番号1桁+医療機関等コード7桁の可能性があります。'),
            'pharmacy_code' => self::validateByLengths($type, $raw, $digits, [7, 10], '薬局コードは通常7桁です。10桁の場合は都道府県番号2桁+点数表番号1桁+薬局コード7桁の可能性があります。'),
            default => ['type' => $type, 'raw' => $raw, 'digits' => $digits, 'status' => 'unknown_type', 'valid' => false, 'needs_human_check' => true, 'message' => '未定義のコード種別です。'],
        };
    }

    /** @param array<int,int> $validLengths @return array<string,mixed> */
    private static function validateByLengths(string $type, string $raw, string $digits, array $validLengths, string $invalidMessage): array
    {
        $len = strlen($digits);
        if (in_array($len, $validLengths, true)) {
            $checksumApplicable = !in_array($type, ['medical_institution_code', 'pharmacy_code'], true) || $len === 10;
            $checksumValid = null;
            $expectedCheckDigit = null;
            if ($checksumApplicable && $len >= 2) {
                $expectedCheckDigit = self::expectedMod10CheckDigit(substr($digits, 0, -1));
                $checksumValid = $expectedCheckDigit === (int)substr($digits, -1);
            }
            if ($checksumValid === false) {
                return [
                    'type' => $type,
                    'raw' => $raw,
                    'digits' => $digits,
                    'length' => $len,
                    'status' => 'invalid_check_digit',
                    'valid' => false,
                    'needs_human_check' => true,
                    'message' => '検証番号が厚生労働省資料の算出ルールと一致しません。読取値または別欄混在を確認してください。',
                    'classification' => self::classifyCode($type, $digits),
                    'expected_check_digit' => $expectedCheckDigit,
                    'actual_check_digit' => (int)substr($digits, -1),
                    'checksum_applicable' => true,
                ];
            }
            return [
                'type' => $type,
                'raw' => $raw,
                'digits' => $digits,
                'length' => $len,
                'status' => $checksumApplicable ? 'valid_length_and_check_digit' : 'valid_length_check_digit_unverifiable_without_prefecture_score',
                'valid' => true,
                'needs_human_check' => !$checksumApplicable,
                'message' => $checksumApplicable ? '' : '7桁の医療機関等コード単体では検証番号の完全照合に都道府県番号・点数表番号が必要です。',
                'classification' => self::classifyCode($type, $digits),
                'expected_check_digit' => $expectedCheckDigit,
                'actual_check_digit' => $checksumApplicable ? (int)substr($digits, -1) : null,
                'checksum_applicable' => $checksumApplicable,
            ];
        }
        $candidate = self::bestLengthCorrectionCandidate($type, $digits, $validLengths);
        if ($candidate !== null) {
            return [
                'type' => $type,
                'raw' => $raw,
                'digits' => (string)$candidate['digits'],
                'length' => strlen((string)$candidate['digits']),
                'original_digits' => $digits,
                'original_length' => $len,
                'status' => 'corrected_length_candidate',
                'valid' => true,
                'needs_human_check' => true,
                'message' => '桁数不一致のため、OCRの余分な数字混入候補として「' . (string)$candidate['digits'] . '」へ補正しました。確定前に原画像を確認してください。',
                'classification' => self::classifyCode($type, (string)$candidate['digits']),
                'expected_check_digit' => self::expectedMod10CheckDigit(substr((string)$candidate['digits'], 0, -1)),
                'actual_check_digit' => (int)substr((string)$candidate['digits'], -1),
                'checksum_applicable' => true,
                'auto_corrected' => true,
                'correction_candidates' => $candidate['all_candidates'],
            ];
        }

        return [
            'type' => $type,
            'raw' => $raw,
            'digits' => $digits,
            'length' => $len,
            'status' => 'invalid_length',
            'valid' => false,
            'needs_human_check' => true,
            'message' => $invalidMessage,
            'classification' => '',
        ];
    }

    /** @param array<int,int> $validLengths @return array<string,mixed>|null */
    private static function bestLengthCorrectionCandidate(string $type, string $digits, array $validLengths): ?array
    {
        $len = strlen($digits);
        if ($len < 2) {
            return null;
        }
        // 自動補正は「1桁だけ多く読んだ」ケースに限定する。足りない桁は推測で足さない。
        $candidateLengths = [];
        foreach ($validLengths as $validLength) {
            if ($len === $validLength + 1) {
                $candidateLengths[] = $validLength;
            }
        }
        if (!$candidateLengths) {
            return null;
        }

        $scored = [];
        for ($i = 0; $i < $len; $i++) {
            $candidate = substr($digits, 0, $i) . substr($digits, $i + 1);
            if (!in_array(strlen($candidate), $candidateLengths, true)) {
                continue;
            }
            if (!self::candidateHasVerifiableChecksum($type, $candidate)) {
                continue;
            }
            $score = 0;
            $removed = $digits[$i];
            $leftSame = $i > 0 && $digits[$i - 1] === $removed;
            $rightSame = $i < $len - 1 && $digits[$i + 1] === $removed;
            if ($leftSame || $rightSame) {
                $score += 60;
            }
            if ($removed === '0') {
                $score += 25;
            }
            if (self::classifyCode($type, $candidate) !== '') {
                $score += 10;
            }
            $scored[$candidate] = max($scored[$candidate] ?? 0, $score);
        }
        if (!$scored) {
            return null;
        }
        arsort($scored);
        $candidates = array_keys($scored);
        $best = $candidates[0];
        $bestScore = (int)$scored[$best];
        $secondScore = isset($candidates[1]) ? (int)$scored[$candidates[1]] : -1;
        if ($secondScore >= $bestScore) {
            return null;
        }
        return [
            'digits' => $best,
            'score' => $bestScore,
            'all_candidates' => array_map(static fn(string $digits): array => ['digits' => $digits, 'score' => (int)$scored[$digits]], $candidates),
        ];
    }

    private static function candidateHasVerifiableChecksum(string $type, string $digits): bool
    {
        $len = strlen($digits);
        if ($len < 2) {
            return false;
        }
        // 7桁の医療機関コード単体は都道府県番号・点数表番号が無いと完全照合できないため、自動補正対象外。
        if (in_array($type, ['medical_institution_code', 'pharmacy_code'], true) && $len !== 10) {
            return false;
        }
        $expected = self::expectedMod10CheckDigit(substr($digits, 0, -1));
        return $expected === (int)substr($digits, -1);
    }


    private static function expectedMod10CheckDigit(string $prefixDigits): int
    {
        $digits = self::digitsOnly($prefixDigits);
        if ($digits === '') {
            return 0;
        }
        $sum = 0;
        $weight = 2;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $product = ((int)$digits[$i]) * $weight;
            $sum += $product >= 10 ? (int)floor($product / 10) + ($product % 10) : $product;
            $weight = $weight === 2 ? 1 : 2;
        }
        $last = $sum % 10;
        return $last === 0 ? 0 : 10 - $last;
    }

    private static function classifyCode(string $type, string $digits): string
    {
        $rules = self::codeRules();
        if ($type === 'insurance_no') {
            if (strlen($digits) === 6) {
                return '国民健康保険（退職者医療を除く）形式';
            }
            $law = substr($digits, 0, 2);
            $map = (array)($rules['insurance_law_codes'] ?? []);
            return isset($map[$law]) ? (string)$map[$law] : '8桁保険者番号形式';
        }
        if ($type === 'public_payer_no') {
            $law = substr($digits, 0, 2);
            $map = (array)($rules['public_expense_law_codes'] ?? []);
            return isset($map[$law]) ? (string)$map[$law] : '公費負担者番号形式';
        }
        if (in_array($type, ['medical_institution_code','pharmacy_code'], true) && strlen($digits) === 10) {
            $score = substr($digits, 2, 1);
            $kind = ['1' => '医科', '3' => '歯科', '4' => '薬局'][$score] ?? '点数表不明';
            return '10桁拡張形式（' . $kind . '）';
        }
        return '';
    }

    public static function normalizedCodeOrRaw(string $type, string $value): string
    {
        $result = self::validateCode($type, $value);
        return !empty($result['valid']) ? (string)$result['digits'] : trim($value);
    }
}
