<?php
declare(strict_types=1);

namespace App\Services;

class CountryDataSeed
{
    /** @return list<array{code:string,nameZh:string,nameEn:string,sortOrder:int,continent?:string}> */
    public static function rows(): array
    {
        $file = dirname(__DIR__, 2) . '/data/countries.json';
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'code' => strtoupper(trim((string)($row['code'] ?? ''))),
                'nameZh' => trim((string)($row['nameZh'] ?? '')),
                'nameEn' => trim((string)($row['nameEn'] ?? '')),
                'sortOrder' => (int)($row['sortOrder'] ?? 0),
                'continent' => trim((string)($row['continent'] ?? '')),
            ];
        }
        return $out;
    }
}
