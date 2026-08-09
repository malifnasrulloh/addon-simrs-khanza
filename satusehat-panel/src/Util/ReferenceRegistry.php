<?php

declare(strict_types=1);

namespace SatusehatPanel\Util;

/**
 * ReferenceRegistry — resolve cross-resource references inside a transaction
 * Bundle by per-instance business keys instead of first-wins-per-type.
 *
 * Every bundle entry registers its `urn:uuid` together with the source-row
 * business keys it was built from (`_panel_persist_keys`, e.g.
 * {noorder, id_template, kd_jenis_prw} for the lab pipeline, or
 * {kode_brng} for Medication). When a builder emits an empty reference like
 * "Observation/" or "Medication/", resolution picks the entry having
 * MATCHING keys, not merely the first entry of that type.
 *
 * Resolution rules:
 *   - exactly one entry of the type in the bundle  -> its uuid (singleton)
 *   - otherwise                                   -> key-match against the
 *     referencing entry's own context keys
 *   - no match                                    -> null (caller surfaces a
 *     pre-send warning; the reference is never shipped silently)
 */
final class ReferenceRegistry
{
    /** @var array<string, list<array{uuid:string, keys:array, keyHash:string}>> */
    private array $entries = [];

    private array $unresolved = [];

    public function register(string $resourceType, string $uuid, array $persistKeys = []): void
    {
        $this->entries[$resourceType][] = [
            'uuid'    => $uuid,
            'keys'    => self::normalizeKeys($persistKeys),
            'keyHash' => self::hashKeys(self::normalizeKeys($persistKeys)),
        ];
    }

    public function count(string $resourceType): int
    {
        return count($this->entries[$resourceType] ?? []);
    }

    /** @return array<string, list<string>> resourceType => registered uuids */
    public function uuidsByType(): array
    {
        $out = [];
        foreach ($this->entries as $type => $entries) {
            $out[$type] = array_column($entries, 'uuid');
        }
        return $out;
    }

    /**
     * Resolve an empty "Type/" reference from the given business-key context.
     *
     * @return string|null urn:uuid of the target entry, or null when ambiguous
     */
    public function resolve(string $resourceType, array $contextKeys): ?string
    {
        if (empty($this->entries[$resourceType])) {
            return null;
        }
        if (count($this->entries[$resourceType]) === 1) {
            return $this->entries[$resourceType][0]['uuid'];
        }

        $ctx = self::normalizeKeys($contextKeys);

        // Pass 1: exact key-set equality.
        $ctxHash = self::hashKeys($ctx);
        foreach ($this->entries[$resourceType] as $entry) {
            if ($entry['keyHash'] === $ctxHash) {
                return $entry['uuid'];
            }
        }

        // Pass 2: subset equality — every key the entry shares with the
        // context must be equal AND at least one key is shared. Only a single
        // unambiguous match is accepted (e.g. Medication request context
        // {no_resep, kode_brng} resolving Medication entry {kode_brng}).
        $candidates = [];
        foreach ($this->entries[$resourceType] as $entry) {
            if (!empty($entry['keys']) && self::subsetMatches($entry['keys'], $ctx)) {
                $candidates[] = $entry['uuid'];
            }
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $this->unresolved[] = [
            'type'        => $resourceType,
            'contextKeys' => $ctx,
        ];
        return null;
    }

    /** Record an unresolvable reference during rewriting. */
    public function noteUnresolved(string $resourceType, array $contextKeys): void
    {
        $this->unresolved[] = [
            'type'        => $resourceType,
            'contextKeys' => self::normalizeKeys($contextKeys),
        ];
    }

    /** @return array<int, array{type:string, contextKeys:array}> */
    public function unresolved(): array
    {
        return $this->unresolved;
    }

    /**
     * True when every key present in BOTH sets is equal and at least one key
     * is shared (subset match — the context carries business keys a subset of
     * which identify the target entry).
     */
    private static function subsetMatches(array $entryKeys, array $contextKeys): bool
    {
        $shared = false;
        foreach ($contextKeys as $k => $v) {
            if (!array_key_exists($k, $entryKeys)) {
                continue;
            }
            $shared = true;
            if ($entryKeys[$k] !== $v) {
                return false;
            }
        }
        return $shared;
    }

    private static function normalizeKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $k => $v) {
            $out[(string) $k] = (string) ($v ?? '');
        }
        return $out;
    }

    public static function hashKeys(array $keys): string
    {
        ksort($keys);
        return hash('sha256', json_encode($keys));
    }
}