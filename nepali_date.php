<?php
// nepali_date.php - Converts Gregorian (AD) dates to Bikram Sambat (BS)
class NepaliDateConverter {
    private static $bs_ref_year = 2081;
    private static $ad_ref_date = '2024-04-13';

    private static $bs_calendar = [
        2081 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2082 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 30],
        2083 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2084 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2085 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 30],
    ];

    public static function convertAdToBs($ad_date_str) {
        $ad_date = new DateTime($ad_date_str);
        $ref_date = new DateTime(self::$ad_ref_date);
        
        $diff_days = $ref_date->diff($ad_date)->days;

        if ($ad_date < $ref_date) {
            return "2080-12-30";
        }

        $bs_year = self::$bs_ref_year;
        $bs_month = 1;
        $bs_day = 1;

        while ($diff_days > 0) {
            $days_in_current_month = self::$bs_calendar[$bs_year][$bs_month - 1] ?? 30;
            if ($diff_days >= $days_in_current_month) {
                $diff_days -= $days_in_current_month;
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
}
?>