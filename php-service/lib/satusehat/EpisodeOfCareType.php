<?php

/**
 * EpisodeOfCareType - Maps ICD-10 codes to FHIR Episode of Care Types.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class EpisodeOfCareType
{
    public readonly string $system;
    public readonly string $code;
    public readonly string $display;
    public readonly array  $icdFilters;

    private function __construct(string $system, string $code, string $display, array $icdFilters)
    {
        $this->system     = $system;
        $this->code       = $code;
        $this->display    = $display;
        $this->icdFilters = $icdFilters;
    }

    /**
     * Get all supported types.
     * @return EpisodeOfCareType[]
     */
    public static function values(): array
    {
        return [
            'ANC' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'ANC',
                'Antenatal Care',
                ['O'] // Contains 'O'
            ),
            'TB-SO' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'TB-SO',
                'Tuberkulosis Sensitif Obat',
                ['A15', 'A16', 'A17', 'A18', 'A19'] // Contains these prefixes
            ),
        ];
    }

    /**
     * Determine the EpisodeOfCareType based on an ICD-10 code.
     *
     * @param string|null $icdCode The ICD-10 diagnosis code
     * @return self|null Matching type, or null if no match
     */
    public static function fromIcdCode(?string $icdCode): ?self
    {
        if (empty($icdCode)) {
            return null;
        }

        $icdCode = strtoupper($icdCode);

        foreach (self::values() as $type) {
            foreach ($type->icdFilters as $filter) {
                if (str_contains($icdCode, strtoupper($filter))) {
                    return $type;
                }
            }
        }

        return null;
    }
}
