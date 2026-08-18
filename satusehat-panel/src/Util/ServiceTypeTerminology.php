<?php

/**
 * ServiceTypeTerminology - Encounter.serviceType resolution.
 *
 * Mirrors the original Java reference (SIMRS-Khanza
 * src/bridging/SatuSehatKirimEncounter.java, getClassCode): exactly THREE
 * conditions decide the encounter — Emergency (IGDK), Rawat Jalan (Ralan)
 * and Rawat Inap (Ranap). The serviceType codes come from the SATUSEHAT
 * terminology appendix (Lampiran Terminologi Encounter.serviceType,
 * RL 3.2/3.5/3.10 - Encounter.serviceType Code FHIR Terminology):
 *
 *   IGDK  -> http://terminology.hl7.org/CodeSystem/service-type  117  Emergency Medical
 *   Ralan -> http://terminology.hl7.org/CodeSystem/service-type  124  General Practice
 *   Ranap -> http://terminology.hl7.org/CodeSystem/service-type  557  Inpatients
 * Codes are the bare integers of the HL7 THO codesystem
 * (terminology.hl7.org/CodeSystem/service-type) — the ".0" shown in the
 * SATUSEHAT Excel appendix is only cell number formatting.
 *
 * @author malifnasrulloh
 */
declare(strict_types=1);

class ServiceTypeTerminology
{
    public const SYSTEM_HL7 = 'http://terminology.hl7.org/CodeSystem/service-type';

    /**
     * Resolve the Encounter.serviceType tuple from exactly three conditions.
     *
     * @param string $kdPoli      poliklinik.kd_poli ('IGDK' = emergency)
     * @param string $statusLanjut 'Ralan' | 'Ranap'
     * @param string $nmPoli      unused — kept for API compatibility
     * @return array{system: string, code: string, display: string}
     */
    public static function resolve(string $kdPoli, string $statusLanjut, string $nmPoli = ''): array
    {
        if (strtoupper(trim($kdPoli)) === 'IGDK') {
            return ['system' => self::SYSTEM_HL7, 'code' => '117', 'display' => 'Emergency Medical'];
        }

        return $statusLanjut === 'Ranap'
            ? ['system' => self::SYSTEM_HL7, 'code' => '557', 'display' => 'Inpatients']
            : ['system' => self::SYSTEM_HL7, 'code' => '124', 'display' => 'General Practice'];
    }

    /**
     * Full CodeableConcept for Encounter.serviceType (coding wrapper required
     * by the SATUSEHAT parser — the bare system/code object was rejected as
     * unparseable_resource).
     */
    public static function coding(string $kdPoli, string $statusLanjut, string $nmPoli = ''): array
    {
        $t = self::resolve($kdPoli, $statusLanjut, $nmPoli);
        return [
            'coding' => [
                [
                    'system'  => $t['system'],
                    'code'    => $t['code'],
                    'display' => $t['display'],
                ],
            ],
        ];
    }
}