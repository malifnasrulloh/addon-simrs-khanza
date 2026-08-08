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
    public readonly bool   $matchByPrefix;

    private function __construct(string $system, string $code, string $display, array $icdFilters, bool $matchByPrefix = true)
    {
        $this->system        = $system;
        $this->code          = $code;
        $this->display       = $display;
        $this->icdFilters    = $icdFilters;
        $this->matchByPrefix = $matchByPrefix;
    }

    /**
     * Get all supported types.
     * @return EpisodeOfCareType[]
     */
    public static function values(): array
    {
        return [
            // --- Kemkes disease registry types (prefix-based ICD matching) ---
            'ANC' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'ANC',
                'Antenatal Care',
                ['O'] // ICD-10 codes starting with O
            ),
            'PNC' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'PNC',
                'Postnatal Care',
                ['O'] // Also O-prefix, but handled after ANC (first match wins)
            ),
            'TB-SO' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'TB-SO',
                'Tuberkulosis Sensitif Obat',
                ['A15', 'A16', 'A17', 'A18', 'A19']
            ),
            'TB-RO' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'TB-RO',
                'Tuberkulosis Resisten Obat',
                [] // Manual override — matched separately via referral/confirmation
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
            'cancer' => new self(
                'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                'cancer',
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
            'HIV' => new self(
                'http://terminology.kemkes.go.id',
                'HIV',
                'Human Immunodeficiency Virus',
                ['B20', 'B21', 'B22', 'B23', 'B24', 'Z21']
            ),
            'EMC' => new self(
                'http://terminology.kemkes.go.id',
                'EMC',
                'Eye Management Care',
                ['H00', 'H01', 'H02', 'H03', 'H04', 'H05', 'H10', 'H11', 'H15', 'H16',
                 'H17', 'H18', 'H20', 'H21', 'H25', 'H26', 'H27', 'H30', 'H31', 'H32',
                 'H33', 'H34', 'H35', 'H36', 'H40', 'H41', 'H42', 'H43', 'H44', 'H45',
                 'H46', 'H47', 'H48', 'H49', 'H50', 'H51', 'H52', 'H53', 'H54', 'H55',
                 'H57', 'H59']
            ),
            'ADM' => new self(
                'http://terminology.kemkes.go.id',
                'ADM',
                'Auditory Disease Management Care',
                ['H60', 'H61', 'H62', 'H65', 'H66', 'H67', 'H68', 'H69', 'H70', 'H71',
                 'H72', 'H73', 'H74', 'H75', 'H80', 'H81', 'H82', 'H83', 'H90', 'H91',
                 'H92', 'H93', 'H94', 'H95']
            ),
            // --- Standard HL7 episode-of-care types ---
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
            // --- Generic types (manual matching only, no ICD filters) ---
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
     * Uses prefix matching (str_starts_with) instead of substring matching.
     *
     * @param string|null $icdCode The ICD-10 diagnosis code
     * @return self|null Matching type, or null if no match
     */
    public static function fromIcdCode(?string $icdCode): ?self
    {
        if (empty($icdCode)) {
            return null;
        }

        $icdCode = strtoupper(trim($icdCode));

        // First pass: try types with non-empty icdFilters using prefix matching
        // Process in priority order — ANC vs PNC both use O-prefix
        $priorityOrder = ['ANC', 'PNC', 'TB-SO', 'Neonate', 'CKD', 'CNC', 'cancer',
                          'HIV', 'EMC', 'ADM', 'CAD', 'CVD', 'diab', 'da'];

        foreach ($priorityOrder as $key) {
            $types = self::values();
            $type = $types[$key] ?? null;
            if (!$type || empty($type->icdFilters)) {
                continue;
            }
            foreach ($type->icdFilters as $filter) {
                if (str_starts_with($icdCode, strtoupper($filter))) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Manually check if this type should match based on business rules.
     * Override this for types with empty icdFilters (TB-RO, hacc, pac, cacp).
     */
    public function matches(string $icdCode, array $context = []): bool
    {
        if (!empty($this->icdFilters)) {
            // Use prefix matching for types with filters
            foreach ($this->icdFilters as $filter) {
                if (str_starts_with(strtoupper($icdCode), strtoupper($filter))) {
                    return true;
                }
            }
            return false;
        }

        // Types with empty icdFilters need custom logic
        switch ($this->code) {
            case 'TB-RO':
                // TB-RO matches when the ICD code matches TB-SO filters
                // AND the context indicates drug-resistant TB
                // Without explicit context, fall back to TB-SO
                return false;
            case 'hacc':
            case 'pac':
            case 'cacp':
                // These generic HL7 types require explicit assignment
                // via configuration or manual tagging — never auto-detect
                return false;
            default:
                return false;
        }
    }
}
