<?php

/**
 * AllergyDictionary - Manager for the alergisatusehat.iyem dictionary.
 * Maps custom string inputs to SNOMED CT and FHIR Allergy categories.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatAllergyDictionary
{
    private string $cacheFile;
    private array $dictionary = [];
    private Logger $log;

    public function __construct(string $cacheFile, Logger $log)
    {
        $this->cacheFile = $cacheFile;
        $this->log = $log;
        $this->load();
    }

    private function load(): void
    {
        if (!file_exists($this->cacheFile)) {
            $this->log->warning("[DICTIONARY] Cache file not found at {$this->cacheFile}. Will create new.");
            $this->dictionary = [];
            return;
        }

        $json = file_get_contents($this->cacheFile);
        $data = json_decode($json, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($data['alergi']) && is_array($data['alergi'])) {
            $this->dictionary = $data['alergi'];
        } else {
            $this->log->warning("[DICTIONARY] Failed to parse cache file. Starting fresh.");
            $this->dictionary = [];
        }
    }

    private function save(): void
    {
        $data = ['alergi' => $this->dictionary];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($this->cacheFile, $json) === false) {
            $this->log->error("[DICTIONARY] Failed to write cache to {$this->cacheFile}");
        }
    }

    public function lookup(string $keyword): array
    {
        // Normalize keyword (remove newlines, tabs, and lowercase)
        $normalized = trim(preg_replace('/\s+/', ' ', strtolower($keyword)));

        // Handle empty or "No Known Allergy" cases
        if ($normalized === '' || $normalized === 'tidak ada alergi') {
            return [
                'category'       => 'environment',
                'coding_system'  => 'http://snomed.info/sct',
                'coding_code'    => '716186003',
                'coding_display' => 'No known allergy',
                'text'           => 'No known allergy'
            ];
        }

        // Search the dictionary
        foreach ($this->dictionary as $entry) {
            if (isset($entry['keyword']) && strtolower($entry['keyword']) === $normalized) {
                return $entry;
            }
        }

        // If not found, add it to the dictionary and return fallback
        $this->log->info("[DICTIONARY] Keyword '{$normalized}' not found. Adding as unknown allergy mapping.");
        
        $newEntry = [
            'keyword'        => $normalized,
            'category'       => 'medication',
            'coding_system'  => 'http://snomed.info/sct',
            'coding_code'    => 'unknown', 
            'coding_display' => $normalized,
            'text'           => 'Alergi ' . $normalized
        ];

        $this->dictionary[] = $newEntry;
        $this->save();

        return $newEntry;
    }
}
