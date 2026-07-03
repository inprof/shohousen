<?php

declare(strict_types=1);

/**
 * 処方薬の用法・日数から総量を保守的に計算する補助サービス。
 *
 * 例:
 * - 1錠×朝食後 + 28日 => 28錠
 * - 1日3回 毎食後 1回5mL + 7日 => 105mL
 *
 * 注意:
 * - 薬品名中の 5mg / 0.05mg は規格量の可能性が高いため、総量計算の根拠にはしない。
 * - 頓服/疼痛時/必要時は総量を自動確定しない。
 */
final class MedicationDosageCalculator
{
    /** @return array{amount_text:string, unit:string, per_dose:?float, frequency_per_day:?float, days:?int, note:string, rule_code:string} */
    public static function calculate(string $drugName, string $doseText, string $usageText, mixed $daysValue): array
    {
        $drugName = self::normalizeText($drugName);
        $doseText = self::normalizeText($doseText);
        $usageText = self::normalizeText($usageText);
        $days = self::extractDays($daysValue, $usageText);

        $base = [
            'amount_text' => '',
            'unit' => '',
            'per_dose' => null,
            'frequency_per_day' => null,
            'days' => $days,
            'note' => '',
            'rule_code' => '',
        ];

        if ($days === null || $days <= 0) {
            $base['note'] = '日数が未入力のため総量を計算できません。';
            $base['rule_code'] = 'missing_days';
            return $base;
        }

        if (self::isAsNeededUsage($usageText)) {
            $base['note'] = '頓服・必要時の用法は総量を自動確定できません。';
            $base['rule_code'] = 'as_needed';
            return $base;
        }

        $dose = self::extractPerDose($doseText, $usageText, $drugName);
        if ($dose === null) {
            $base['note'] = '1回量が読めないため総量を計算できません。';
            $base['rule_code'] = 'missing_per_dose';
            return $base;
        }

        $frequency = self::extractFrequencyPerDay($usageText);
        if ($frequency === null || $frequency <= 0) {
            $base['note'] = '服薬回数が読めないため総量を計算できません。';
            $base['rule_code'] = 'missing_frequency';
            return $base;
        }

        $total = $dose['value'] * $frequency * $days;
        $unit = self::normalizeUnit($dose['unit']);
        return [
            'amount_text' => self::formatNumber($total) . $unit,
            'unit' => $unit,
            'per_dose' => $dose['value'],
            'frequency_per_day' => $frequency,
            'days' => $days,
            'note' => self::formatNumber($dose['value']) . $unit . ' × ' . self::formatNumber($frequency) . '回/日 × ' . $days . '日',
            'rule_code' => 'calculated_from_usage_days',
        ];
    }

    public static function calculateAmountText(string $drugName, string $doseText, string $usageText, mixed $daysValue): string
    {
        return self::calculate($drugName, $doseText, $usageText, $daysValue)['amount_text'];
    }

    public static function shouldReplaceAmountText(string $currentAmountText, string $calculatedAmountText): bool
    {
        $current = trim(self::normalizeText($currentAmountText));
        $calculated = trim(self::normalizeText($calculatedAmountText));
        if ($calculated === '') {
            return false;
        }
        if ($current === '' || $current === $calculated) {
            return true;
        }
        // 「28日分」は日数であり総量ではないため、計算できる場合は置き換える。
        if (preg_match('/^\d+(?:\.\d+)?日分$/u', $current) === 1) {
            return true;
        }
        $cur = self::splitNumericUnit($current);
        $calc = self::splitNumericUnit($calculated);
        if ($cur !== null && $calc !== null && $cur['unit'] !== $calc['unit']) {
            // 薬品名の規格量（例: 5mg, 0.05mg）が総量欄へ入ったケースを補正する。
            return true;
        }
        return false;
    }

    private static function normalizeText(string $value): string
    {
        if (function_exists('mb_convert_kana')) {
            $value = mb_convert_kana($value, 'asKV', 'UTF-8');
        }
        $value = str_replace(['ｍｌ', 'ＭＬ', '㎖', '㏄'], ['ml', 'ml', 'ml', 'cc'], $value);
        return trim($value);
    }

    private static function extractDays(mixed $daysValue, string $usageText): ?int
    {
        if (is_numeric($daysValue) && (int)$daysValue > 0) {
            return (int)$daysValue;
        }
        if (preg_match('/(\d+)\s*日\s*分?/u', $usageText, $m) === 1) {
            return (int)$m[1];
        }
        return null;
    }

    /** @return array{value:float,unit:string}|null */
    private static function extractPerDose(string $doseText, string $usageText, string $drugName): ?array
    {
        foreach ([$doseText, $usageText] as $source) {
            $source = self::normalizeText($source);
            if ($source === '') {
                continue;
            }
            if (preg_match('/(\d+(?:\.\d+)?)\s*(錠| tablet|tablets|tab|カプセル|cap|capsule|包|袋|mL|ml|cc|g|mg|滴|枚|本|個)/iu', $source, $m) === 1) {
                return ['value' => (float)$m[1], 'unit' => self::normalizeUnit((string)$m[2])];
            }
            // 「1×朝食後」のように単位が省略されている場合は、薬品名の剤形から補う。
            if (preg_match('/(?:^|[^0-9.])(\d+(?:\.\d+)?)\s*[x×]\s*(?:朝|昼|夕|毎食|食後|食前|就寝|寝る|起床)/u', $source, $m) === 1) {
                $unit = self::inferUnitFromDrugName($drugName);
                if ($unit !== '') {
                    return ['value' => (float)$m[1], 'unit' => $unit];
                }
            }
        }
        return null;
    }

    private static function inferUnitFromDrugName(string $drugName): string
    {
        if (preg_match('/錠|OD錠|口腔内崩壊錠/u', $drugName) === 1) return '錠';
        if (preg_match('/カプセル|cap/i', $drugName) === 1) return 'カプセル';
        if (preg_match('/包|顆粒|散|細粒|ドライシロップ/u', $drugName) === 1) return '包';
        if (preg_match('/シロップ|液|内用液|懸濁|mL|ml/u', $drugName) === 1) return 'mL';
        if (preg_match('/貼付|テープ|パップ|湿布/u', $drugName) === 1) return '枚';
        return '';
    }

    private static function extractFrequencyPerDay(string $usageText): ?float
    {
        $text = self::normalizeText($usageText);
        if ($text === '') {
            return null;
        }
        if (preg_match('/1\s*日\s*(\d+(?:\.\d+)?)\s*回/u', $text, $m) === 1) {
            return (float)$m[1];
        }
        if (preg_match('/分\s*(\d+(?:\.\d+)?)/u', $text, $m) === 1) {
            return (float)$m[1];
        }
        if (preg_match('/毎食/u', $text) === 1) {
            return 3.0;
        }

        $count = 0;
        foreach ([
            'morning' => '/朝|朝食後|朝食前|起床時/u',
            'noon' => '/昼|昼食後|昼食前/u',
            'evening' => '/夕|夕食後|夕食前/u',
            'bedtime' => '/就寝|寝る前/u',
        ] as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $count++;
            }
        }
        return $count > 0 ? (float)$count : null;
    }

    private static function isAsNeededUsage(string $usageText): bool
    {
        return preg_match('/頓服|屯服|疼痛時|発作時|必要時|不眠時|便秘時|嘔気時|適宜|随時/u', self::normalizeText($usageText)) === 1;
    }

    private static function normalizeUnit(string $unit): string
    {
        $u = trim(self::normalizeText($unit));
        $lower = strtolower($u);
        return match ($lower) {
            'tablet', 'tablets', 'tab' => '錠',
            'cap', 'capsule' => 'カプセル',
            'ml', 'cc' => 'mL',
            default => $u,
        };
    }

    /** @return array{number:float,unit:string}|null */
    private static function splitNumericUnit(string $text): ?array
    {
        $text = self::normalizeText($text);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(錠|カプセル|包|袋|mL|ml|cc|g|mg|滴|枚|本|個)$/iu', $text, $m) !== 1) {
            return null;
        }
        return ['number' => (float)$m[1], 'unit' => self::normalizeUnit((string)$m[2])];
    }

    private static function formatNumber(float $number): string
    {
        if (abs($number - round($number)) < 0.00001) {
            return (string)(int)round($number);
        }
        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }
}
