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
            'TB-RO' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'TB-RO',
                'Tuberkulosis Resisten Obat',
                [] // Handled manually / future use
            ),
            'Neonate' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'Neonate',
                'Neonate',
                ['P', 'Z38']
            ),
            'CKD' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'CKD',
                'Chronic Kidney Disease',
                ['N18']
            ),
            'CNC' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'CNC',
                'Cancer Management Care',
                ['C', 'D0', 'D1', 'D2', 'D3', 'D4', 'Z51.1', 'Z51.0']
            ),
            'CAD' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'CAD',
                'Coronary Arterial Disease',
                ['I20', 'I21', 'I22', 'I23', 'I24', 'I25']
            ),
            'CVD' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'CVD',
                'Cerebrovascular Disease',
                ['I60', 'I61', 'I62', 'I63', 'I64', 'I65', 'I66', 'I67', 'I68', 'I69']
            ),
            'hacc' => new self(
                'http://terminology.hl7.org/CodeSystem/episodeofcare-type',
                'hacc',
                'Home and Community Care',
                []
            ),
            'pac' => new self(
                'http://terminology.hl7.org/CodeSystem/episodeofcare-type',
                'pac',
                'Post Acute Care',
                []
            ),
            'diab' => new self(
                'http://terminology.hl7.org/CodeSystem/episodeofcare-type',
                'diab',
                'Post coordinated diabetes program',
                ['E10', 'E11', 'E12', 'E13', 'E14']
            ),
            'da' => new self(
                'http://terminology.hl7.org/CodeSystem/episodeofcare-type',
                'da',
                'Drug and alcohol rehabilitation',
                ['F10', 'F11', 'F12', 'F13', 'F14', 'F15', 'F16', 'F17', 'F18', 'F19', 'Z71.4', 'Z71.5']
            ),
            'cacp' => new self(
                'http://terminology.hl7.org/CodeSystem/episodeofcare-type',
                'cacp',
                'Community-based aged care',
                []
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
