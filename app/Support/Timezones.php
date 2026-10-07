<?php

namespace App\Support;

use DateTimeZone;
use Throwable;

/**
 * Turns the zone a phone reports into one this PHP can use.
 *
 * Phones report whatever their own time zone data calls the place: older names
 * (Asia/Calcutta, Europe/Kiev, US/Pacific) on older systems, newer ones (Europe/Kyiv,
 * America/Nuuk) on newer. A PHP built against the system's zone data may know only one
 * spelling, so both directions are tried.
 */
final class Timezones
{
    /** Older or alternative name → the current one. */
    private const RENAMED = [
        'Asia/Calcutta' => 'Asia/Kolkata', 'Asia/Katmandu' => 'Asia/Kathmandu', 'Asia/Saigon' => 'Asia/Ho_Chi_Minh',
        'Asia/Rangoon' => 'Asia/Yangon', 'Asia/Dacca' => 'Asia/Dhaka', 'Asia/Thimbu' => 'Asia/Thimphu', 'Asia/Macao' => 'Asia/Macau',
        'Asia/Ujung_Pandang' => 'Asia/Makassar', 'Asia/Ulan_Bator' => 'Asia/Ulaanbaatar', 'Asia/Chongqing' => 'Asia/Shanghai',
        'Asia/Chungking' => 'Asia/Shanghai', 'Asia/Harbin' => 'Asia/Shanghai', 'Asia/Kashgar' => 'Asia/Urumqi',
        'Asia/Tel_Aviv' => 'Asia/Jerusalem', 'Asia/Istanbul' => 'Europe/Istanbul', 'Asia/Ashkhabad' => 'Asia/Ashgabat',
        'Europe/Kiev' => 'Europe/Kyiv', 'Europe/Uzhgorod' => 'Europe/Kyiv', 'Europe/Zaporozhye' => 'Europe/Kyiv',
        'Europe/Belfast' => 'Europe/London', 'Europe/Nicosia' => 'Asia/Nicosia',
        'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires', 'America/Catamarca' => 'America/Argentina/Catamarca',
        'America/Cordoba' => 'America/Argentina/Cordoba', 'America/Rosario' => 'America/Argentina/Cordoba',
        'America/Jujuy' => 'America/Argentina/Jujuy', 'America/Mendoza' => 'America/Argentina/Mendoza',
        'America/Indianapolis' => 'America/Indiana/Indianapolis', 'America/Fort_Wayne' => 'America/Indiana/Indianapolis',
        'America/Knox_IN' => 'America/Indiana/Knox', 'America/Louisville' => 'America/Kentucky/Louisville',
        'America/Godthab' => 'America/Nuuk', 'America/Montreal' => 'America/Toronto', 'America/Shiprock' => 'America/Denver',
        'America/Virgin' => 'America/St_Thomas', 'America/Santa_Isabel' => 'America/Tijuana', 'America/Ensenada' => 'America/Tijuana',
        'America/Porto_Acre' => 'America/Rio_Branco', 'America/Atka' => 'America/Adak',
        'Atlantic/Faeroe' => 'Atlantic/Faroe', 'Africa/Asmera' => 'Africa/Asmara', 'Africa/Timbuktu' => 'Africa/Bamako',
        'Pacific/Ponape' => 'Pacific/Pohnpei', 'Pacific/Truk' => 'Pacific/Chuuk', 'Pacific/Yap' => 'Pacific/Chuuk',
        'Pacific/Samoa' => 'Pacific/Pago_Pago', 'Pacific/Enderbury' => 'Pacific/Kanton',
        'Australia/ACT' => 'Australia/Sydney', 'Australia/Canberra' => 'Australia/Sydney', 'Australia/NSW' => 'Australia/Sydney',
        'Australia/North' => 'Australia/Darwin', 'Australia/Queensland' => 'Australia/Brisbane', 'Australia/South' => 'Australia/Adelaide',
        'Australia/Tasmania' => 'Australia/Hobart', 'Australia/Victoria' => 'Australia/Melbourne', 'Australia/West' => 'Australia/Perth',
        'Australia/Yancowinna' => 'Australia/Broken_Hill', 'Australia/LHI' => 'Australia/Lord_Howe',
        'US/Eastern' => 'America/New_York', 'US/Central' => 'America/Chicago', 'US/Mountain' => 'America/Denver',
        'US/Pacific' => 'America/Los_Angeles', 'US/Alaska' => 'America/Anchorage', 'US/Hawaii' => 'Pacific/Honolulu',
        'US/Arizona' => 'America/Phoenix', 'US/Michigan' => 'America/Detroit', 'US/East-Indiana' => 'America/Indiana/Indianapolis',
        'US/Indiana-Starke' => 'America/Indiana/Knox', 'US/Aleutian' => 'America/Adak', 'US/Samoa' => 'Pacific/Pago_Pago',
        'Canada/Atlantic' => 'America/Halifax', 'Canada/Central' => 'America/Winnipeg', 'Canada/Eastern' => 'America/Toronto',
        'Canada/Mountain' => 'America/Edmonton', 'Canada/Newfoundland' => 'America/St_Johns', 'Canada/Pacific' => 'America/Vancouver',
        'Canada/Saskatchewan' => 'America/Regina', 'Canada/Yukon' => 'America/Whitehorse',
        'Brazil/East' => 'America/Sao_Paulo', 'Brazil/West' => 'America/Manaus', 'Brazil/Acre' => 'America/Rio_Branco',
        'Chile/Continental' => 'America/Santiago', 'Mexico/General' => 'America/Mexico_City',
        'Mexico/BajaNorte' => 'America/Tijuana', 'Mexico/BajaSur' => 'America/Mazatlan',
        'GB' => 'Europe/London', 'GB-Eire' => 'Europe/London', 'Eire' => 'Europe/Dublin', 'NZ' => 'Pacific/Auckland',
        'Singapore' => 'Asia/Singapore', 'Hongkong' => 'Asia/Hong_Kong', 'Japan' => 'Asia/Tokyo', 'Israel' => 'Asia/Jerusalem',
        'Turkey' => 'Europe/Istanbul', 'Egypt' => 'Africa/Cairo', 'Iran' => 'Asia/Tehran', 'Jamaica' => 'America/Jamaica',
        'Poland' => 'Europe/Warsaw', 'Portugal' => 'Europe/Lisbon', 'PRC' => 'Asia/Shanghai', 'ROC' => 'Asia/Taipei',
        'ROK' => 'Asia/Seoul', 'Cuba' => 'America/Havana', 'Iceland' => 'Atlantic/Reykjavik', 'Libya' => 'Africa/Tripoli',
        'Navajo' => 'America/Denver', 'W-SU' => 'Europe/Moscow',
        'UCT' => 'UTC', 'Universal' => 'UTC', 'Zulu' => 'UTC', 'Greenwich' => 'UTC', 'GMT0' => 'UTC', 'GMT+0' => 'UTC', 'GMT-0' => 'UTC',
        'Etc/UCT' => 'UTC', 'Etc/Universal' => 'UTC', 'Etc/Zulu' => 'UTC', 'Etc/Greenwich' => 'UTC', 'Etc/GMT' => 'UTC', 'Etc/UTC' => 'UTC',
        'Etc/GMT0' => 'UTC', 'Etc/GMT+0' => 'UTC', 'Etc/GMT-0' => 'UTC', 'GMT' => 'UTC',
    ];

    /** A zone name this PHP can use for [$reported], or null when there is none. */
    public static function usable(mixed $reported): ?string
    {
        if (! is_string($reported) || $reported === '' || strlen($reported) > 64) {
            return null;
        }
        // The name as given, then its current name, then any older spelling of it.
        $candidates = [$reported];
        if (isset(self::RENAMED[$reported])) {
            $candidates[] = self::RENAMED[$reported];
        }
        array_push($candidates, ...array_keys(self::RENAMED, $reported, true));

        foreach ($candidates as $name) {
            try {
                return (new DateTimeZone($name))->getName();
            } catch (Throwable) {
                // try the next spelling
            }
        }

        return null;
    }
}
