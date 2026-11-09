<?php

namespace App\Services;

/**
 * Email se ID number banata hai (frontend ke same formula se).
 *
 * Step 1 (dono versions me same): email ka 32-bit hash
 *     h = 0
 *     har character c ke liye: h = (h * 31 + UTF-16 code of c), phir signed 32-bit me wrap
 *     (Java String.hashCode() / JS (h<<5)-h+c / h|=0 jaisa)
 *     Email jaisa hai waisa use hota hai: lowercase ya trim NAHI.
 *
 * Version 1 (card preview) : "RTI" + abs(h) decimal ke pehle 6 characters
 * Version 2 (print / PDF)  : "RTI" + abs(h) base-36 UPPERCASE ke pehle 10 characters
 *
 * Examples (test@example.com): hash = -1405876145
 *     v1 => RTI140587
 *     v2 => RTIN90SZ5
 */
class IdNumberService
{
    public const PREFIX = 'RTI';

    /** Naye cards me kaun sa version save hoga. Purane IDs wale version se match rakho. */
    public const DEFAULT_VERSION = 2;

    /** Signed 32-bit hash (JS: h = (h << 5) - h + c; h |= 0). */
    public static function hash(string $email): int
    {
        $h = 0;

        // charCode = UTF-16 code unit (emoji jaise characters 2 units hote hain, JS jaisa)
        $utf16 = mb_convert_encoding($email, 'UTF-16LE', 'UTF-8');
        $units = $utf16 === '' ? [] : unpack('v*', $utf16);

        foreach ($units as $c) {
            $h = ($h * 31 + $c) & 0xFFFFFFFF;   // 32-bit wrap
            if ($h >= 0x80000000) {
                $h -= 0x100000000;               // signed
            }
        }

        return $h;
    }

    /** Email khali ho to null (frontend "N/A" dikhata hai). */
    public static function fromEmail(?string $email, int $version = self::DEFAULT_VERSION): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        $abs = abs(self::hash($email));

        if ($version === 1) {
            return self::PREFIX . substr((string) $abs, 0, 6);
        }

        return self::PREFIX . substr(strtoupper(base_convert((string) $abs, 10, 36)), 0, 10);
    }
}
