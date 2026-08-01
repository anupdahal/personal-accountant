<?php
/**
 * nepali_date.php — Bikram Sambat (BS) ↔ Gregorian (AD) date converter
 * AI Accountant
 *
 * Coverage: BS 2070 – 2090 (approx. AD 2013 – 2034)
 * Based on the official Nepali Panchang calendar month-day counts.
 */

class NepaliDateConverter
{
    /** AD reference date that maps to BS 2081-01-01 */
    private static string $ad_ref_date = '2024-04-13';
    private static int    $bs_ref_year = 2081;

    /**
     * BS calendar: month lengths for each year.
     * Index 0 = Baishakh (month 1), … Index 11 = Chaitra (month 12)
     */
    private static array $bs_calendar = [
        2070 => [31, 31, 32, 31, 32, 30, 30, 30, 29, 29, 30, 30],
        2071 => [31, 31, 32, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2072 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 30],
        2073 => [31, 32, 32, 31, 32, 30, 30, 30, 29, 30, 29, 30],
        2074 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 30],
        2075 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2076 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2077 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2078 => [31, 32, 31, 32, 31, 31, 29, 30, 29, 29, 30, 30],
        2079 => [31, 32, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2080 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 30],
        2081 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2082 => [31, 31, 32, 31, 32, 31, 30, 29, 30, 29, 30, 30],
        2083 => [31, 31, 32, 31, 32, 31, 30, 29, 30, 29, 30, 30],
        2084 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2085 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 30],
        2086 => [31, 32, 32, 31, 32, 30, 30, 30, 29, 30, 29, 30],
        2087 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 30],
        2088 => [31, 32, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2089 => [31, 32, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2090 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 30],
    ];

    /** Nepali month names (Devanagari + transliteration) */
    private static array $month_names = [
        1  => 'Baishakh',
        2  => 'Jestha',
        3  => 'Ashadh',
        4  => 'Shrawan',
        5  => 'Bhadra',
        6  => 'Ashwin',
        7  => 'Kartik',
        8  => 'Mangsir',
        9  => 'Poush',
        10 => 'Magh',
        11 => 'Falgun',
        12 => 'Chaitra',
    ];

    /**
     * Convert an AD date string (Y-m-d) to BS date string (Y-m-d)
     */
    public static function convertAdToBs(string $ad_date_str): string
    {
        try {
            $ad_date = new DateTime($ad_date_str);
        } catch (Exception $e) {
            return '2083-01-01';
        }

        $ref_date = new DateTime(self::$ad_ref_date);

        if ($ad_date < $ref_date) {
            // Before reference — compute backwards from 2081-01-01
            return self::convertAdToBsBeforeRef($ad_date);
        }

        $diff_days = (int)$ref_date->diff($ad_date)->days;

        $bs_year  = self::$bs_ref_year;
        $bs_month = 1;
        $bs_day   = 1;

        while ($diff_days > 0) {
            $days_in_month = self::$bs_calendar[$bs_year][$bs_month - 1] ?? 30;
            if ($diff_days >= $days_in_month) {
                $diff_days -= $days_in_month;
                $bs_month++;
                if ($bs_month > 12) {
                    $bs_month = 1;
                    $bs_year++;
                }
            } else {
                $bs_day += $diff_days;
                $diff_days = 0;
            }
        }

        return sprintf("%04d-%02d-%02d", $bs_year, $bs_month, $bs_day);
    }

    /**
     * Handle dates before the reference point (AD < 2024-04-13)
     */
    private static function convertAdToBsBeforeRef(DateTime $ad_date): string
    {
        $ref_date = new DateTime(self::$ad_ref_date);
        $diff_days = (int)$ad_date->diff($ref_date)->days;

        $bs_year  = self::$bs_ref_year;
        $bs_month = 1;
        $bs_day   = 1;

        while ($diff_days > 0) {
            $bs_month--;
            if ($bs_month < 1) {
                $bs_month = 12;
                $bs_year--;
            }
            $days_in_month = self::$bs_calendar[$bs_year][$bs_month - 1] ?? 30;
            if ($diff_days >= $bs_day) {
                $diff_days -= $bs_day;
                $bs_day = $days_in_month;
            } else {
                $bs_day -= $diff_days;
                $diff_days = 0;
            }
        }

        if ($bs_year < 2070) return '2070-01-01';

        return sprintf("%04d-%02d-%02d", $bs_year, $bs_month, $bs_day);
    }

    /**
     * Convert a BS date string (Y-m-d) back to AD date string (Y-m-d)
     */
    public static function convertBsToAd(string $bs_date_str): string
    {
        $parts = explode('-', $bs_date_str);
        if (count($parts) !== 3) return date('Y-m-d');

        $bs_year  = (int)$parts[0];
        $bs_month = (int)$parts[1];
        $bs_day   = (int)$parts[2];

        if (!isset(self::$bs_calendar[$bs_year])) return date('Y-m-d');

        // Count total days from reference (2081-01-01 = 2024-04-13)
        $ref_year  = self::$bs_ref_year;
        $ref_month = 1;
        $total_days = 0;

        if ($bs_year > $ref_year || ($bs_year === $ref_year && $bs_month > $ref_month)) {
            // Forward: count from ref to target
            $cy = $ref_year;
            $cm = $ref_month;
            while ($cy < $bs_year || ($cy === $bs_year && $cm < $bs_month)) {
                $dim = self::$bs_calendar[$cy][$cm - 1] ?? 30;
                $total_days += $dim;
                $cm++;
                if ($cm > 12) { $cm = 1; $cy++; }
            }
            $total_days += ($bs_day - 1);
        } else {
            // Backward
            $cy = $ref_year;
            $cm = $ref_month;
            while ($cy > $bs_year || ($cy === $bs_year && $cm > $bs_month)) {
                $cm--;
                if ($cm < 1) { $cm = 12; $cy--; }
                $dim = self::$bs_calendar[$cy][$cm - 1] ?? 30;
                $total_days -= $dim;
            }
            $total_days += ($bs_day - 1);
        }

        $ref_date = new DateTime(self::$ad_ref_date);
        $interval = new DateInterval('P' . abs($total_days) . 'D');
        if ($total_days >= 0) {
            $ref_date->add($interval);
        } else {
            $ref_date->sub($interval);
        }

        return $ref_date->format('Y-m-d');
    }

    /**
     * Get the Nepali month name for a BS month number
     */
    public static function monthName(int $month): string
    {
        return self::$month_names[$month] ?? '';
    }

    /**
     * Format a BS date string into a readable label
     * e.g. "2083-04-16" → "16 Shrawan, 2083 BS"
     */
    public static function formatBs(string $bs_date_str): string
    {
        $parts = explode('-', $bs_date_str);
        if (count($parts) !== 3) return $bs_date_str;
        $day   = (int)$parts[2];
        $month = (int)$parts[1];
        $year  = (int)$parts[0];
        $mname = self::monthName($month);
        return $mname ? "{$day} {$mname}, {$year} BS" : $bs_date_str;
    }

    /**
     * Get today's BS date
     */
    public static function todayBs(): string
    {
        return self::convertAdToBs(date('Y-m-d'));
    }
}
