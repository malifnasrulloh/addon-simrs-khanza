<?php

/**
 * RadiologyModalityMapper - Resolves DICOM modalities and AE Titles for procedure codes.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class RadiologyModalityMapper
{
    private static ?RadiologyModalityMapper $instance = null;
    private array $modalityMap = [];
    private array $procedureAetMap = [];
    private array $defaultAetMap = [];

    private function __construct()
    {
        $this->loadMapping();
    }

    public static function getInstance(): RadiologyModalityMapper
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Looks up the DICOM modality for a given radiology procedure code.
     */
    public function getModality(string $kdJenisPrw): ?string
    {
        $kd = trim($kdJenisPrw);
        return $this->modalityMap[$kd] ?? null;
    }

    /**
     * Resolves the AE Title for a given procedure code and modality.
     */
    public function getAeTitle(string $kdJenisPrw, string $modality, string $defaultFallback): string
    {
        $kd = trim($kdJenisPrw);
        if (isset($this->procedureAetMap[$kd])) {
            return $this->procedureAetMap[$kd];
        }
        
        $mod = strtoupper(trim($modality));
        if (isset($this->defaultAetMap[$mod])) {
            return $this->defaultAetMap[$mod];
        }

        return $defaultFallback;
    }

    private function loadMapping(): void
    {
        $paths = [
            './cache/mapping_tindakan_radiologi.iyem',
        ];

        $content = null;
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    break;
                }
            }
        }

        if ($content === null) {
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return;
        }

        if (isset($data['default_aet']) && is_array($data['default_aet'])) {
            foreach ($data['default_aet'] as $modality => $aet) {
                $this->defaultAetMap[strtoupper(trim($modality))] = trim($aet);
            }
        }

        if (isset($data['mapping']) && is_array($data['mapping'])) {
            foreach ($data['mapping'] as $entry) {
                $kd = trim($entry['kd_jenis_prw'] ?? '');
                $modality = strtoupper(trim($entry['modality'] ?? ''));
                $aet = trim($entry['aet'] ?? '');

                if ($kd === '' || $modality === '' || $kd === 'XXXXXX') {
                    continue;
                }

                $this->modalityMap[$kd] = $modality;
                if ($aet !== '') {
                    $this->procedureAetMap[$kd] = $aet;
                }
            }
        }
    }
}
