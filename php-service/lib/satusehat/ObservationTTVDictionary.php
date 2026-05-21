<?php

/**
 * ObservationTTVDictionary - Definitions for all 10 vital signs.
 *
 * Maps SIMRS database columns to FHIR LOINC/SNOMED codes and structures.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class ObservationTTVDictionary
{
    /**
     * Get all supported observation definitions.
     */
    public static function getDefinitions(): array
    {
        return [
            'suhu' => [
                'db_column'    => 'suhu_tubuh',
                'state_table'  => 'satu_sehat_observationttvsuhu',
                'state_id_col' => 'id_observation',
                'type'         => 'quantity',
                'system'       => 'http://loinc.org',
                'code'         => '8310-5',
                'display'      => 'Body temperature',
                'unit'         => 'Cel',
                'unit_display' => 'C'
            ],
            'respirasi' => [
                'db_column'    => 'respirasi',
                'state_table'  => 'satu_sehat_observationttvrespirasi',
                'state_id_col' => 'id_observation',
                'type'         => 'quantity',
                'system'       => 'http://loinc.org',
                'code'         => '9279-1',
                'display'      => 'Respiratory rate',
                'unit'         => '/min',
                'unit_display' => 'beats/minute'
            ],
            'nadi' => [
                'db_column'    => 'nadi',
                'state_table'  => 'satu_sehat_observationttvnadi',
                'state_id_col' => 'id_observation',
                'type'         => 'quantity',
                'system'       => 'http://loinc.org',
                'code'         => '8867-4',
                'display'      => 'Heart rate',
                'unit'         => '/min',
                'unit_display' => 'beats/minute'
            ],
            'spo2' => [
                'db_column'    => 'spo2',
                'state_table'  => 'satu_sehat_observationttvspo2',
                'state_id_col' => 'id_observation',
                'type'         => 'quantity',
                'system'       => 'http://loinc.org',
                'code'         => '2708-6',
                'display'      => 'Oxygen saturation in Arterial blood',
                'unit'         => '%',
                'unit_display' => '%'
            ],
            'tb' => [
                'db_column'    => 'tinggi',
                'state_table'  => 'satu_sehat_observationttvtb',
                'state_id_col' => 'id_observation',
                'type'         => 'quantity',
                'system'       => 'http://loinc.org',
                'code'         => '8302-2',
                'display'      => 'Body height',
                'unit'         => 'cm',
                'unit_display' => 'cm'
            ],
            'bb' => [
                'db_column'    => 'berat',
                'state_table'  => 'satu_sehat_observationttvbb',
                'state_id_col' => 'id_observation',
                'type'         => 'quantity',
                'system'       => 'http://loinc.org',
                'code'         => '2946-3',
                'display'      => 'Body weight',
                'unit'         => 'kg',
                'unit_display' => 'kg'
            ],
            'lp' => [
                'db_column'    => 'lingkar_perut',
                'state_table'  => 'satu_sehat_observationttvlp',
                'state_id_col' => 'id_observation',
                'type'         => 'quantity',
                'system'       => 'http://loinc.org',
                'code'         => '8280-0',
                'display'      => 'Waist Circumference at umbilicus by Tape measure',
                'unit'         => 'cm',
                'unit_display' => 'cm'
            ],
            'tensi' => [
                'db_column'    => 'tensi', // Contains format "120/80"
                'state_table'  => 'satu_sehat_observationttvtensi',
                'state_id_col' => 'id_observation',
                'type'         => 'blood_pressure', // Special component structure
                'system'       => 'http://loinc.org',
                'code'         => '85354-9',
                'display'      => 'Blood pressure panel with all children optional',
            ],
            'gcs' => [
                'db_column'    => 'gcs', // "E4,V5,M6" or "15" -> we handle string representation
                'state_table'  => 'satu_sehat_observationttvgcs',
                'state_id_col' => 'id_observation',
                'type'         => 'string', // Plain string value per Java app
                'system'       => 'http://loinc.org',
                'code'         => '9269-2',
                'display'      => 'Glasgow coma score total',
            ],
            'kesadaran' => [
                'db_column'    => 'kesadaran', // Enum in DB
                'state_table'  => 'satu_sehat_observationttvkesadaran',
                'state_id_col' => 'id_observation',
                'type'         => 'codeable_concept', // Requires mapping to snomed
                'system'       => 'http://snomed.info/sct',
                'code'         => '130987000', // Hardcoded base code from Java
                'display'      => 'Acute confusion', // We dynamically alter based on mapping
            ],
        ];
    }

    /**
     * Maps SIMRS "kesadaran" string to SNOMED codes.
     */
    public static function mapKesadaran(string $kesadaran): array
    {
        $map = [
            'Compos Mentis' => ['code' => '448268000', 'display' => 'Alert and oriented'],
            'Somnolence'    => ['code' => '271598007', 'display' => 'Somnolence'],
            'Sopor'         => ['code' => '3006004',   'display' => 'Stupor'],
            'Coma'          => ['code' => '405786008', 'display' => 'Coma'],
            'Alert'         => ['code' => '130987000', 'display' => 'Acute confusion'], // Fallback mapped to Java logic
            'Confusion'     => ['code' => '40916000',  'display' => 'Confusion'],
            'Voice'         => ['code' => '130987000', 'display' => 'Acute confusion'],
            'Pain'          => ['code' => '130987000', 'display' => 'Acute confusion'],
            'Unresponsive'  => ['code' => '130987000', 'display' => 'Acute confusion'],
            'Apatis'        => ['code' => '20602000',  'display' => 'Apathy'],
            'Delirium'      => ['code' => '2776000',   'display' => 'Delirium'],
            'Meninggal'     => ['code' => '419099009', 'display' => 'Dead'],
        ];

        return $map[$kesadaran] ?? ['code' => '130987000', 'display' => 'Acute confusion'];
    }
}
