<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests\Support;

/**
 * Access + normalization helpers for the golden fixtures extracted from the
 * official SATUSEHAT Postman collections (tests/fixtures/).
 */
final class Fixtures
{
    /** @return array<string,array> manifest: fixtureName => provenance */
    public static function manifest(): array
    {
        $path = PANEL_BASE . '/tests/fixtures/manifest.json';
        if (!is_file($path)) {
            throw new \RuntimeException('fixture manifest missing — run: php scripts/extract-fixtures.php');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['fixtures'])) {
            throw new \RuntimeException('fixture manifest unparseable: ' . $path);
        }
        return $data['fixtures'];
    }

    /** @return string[] all fixture names (sorted, deterministic) */
    public static function names(): array
    {
        $names = array_keys(self::manifest());
        sort($names);
        return $names;
    }

    /** @return array decoded official example */
    public static function load(string $name): array
    {
        $path = PANEL_BASE . '/tests/fixtures/' . $name . '.json';
        if (!is_file($path)) {
            throw new \RuntimeException('fixture not found: ' . $name);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('fixture unparseable: ' . $name);
        }
        return $data;
    }

    /**
     * Recursively normalize volatile values so payloads can be compared
     * deterministically: urn:uuid fullUrls, ISO instants, {{template}} vars.
     */
    public static function normalize($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::normalize($v);
            }
            return $out;
        }
        if (!is_string($value)) {
            return $value;
        }
        if (preg_match('/^urn:uuid:[0-9a-f\-]{36}$/', $value)) {
            return 'urn:uuid:{{uuid}}';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $value)) {
            return '{{instant}}';
        }
        if (preg_match('/^\{\{[A-Za-z_][A-Za-z0-9_]*\}\}$/', $value)) {
            return '{{var}}';
        }
        return $value;
    }
}