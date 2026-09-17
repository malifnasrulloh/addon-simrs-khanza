# EpisodeOfCare PATCH Payload /patient Inclusion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Include the `/patient` element as the first operation in SATUSEHAT `EpisodeOfCare` HTTP PATCH payloads across both the admin panel controller and the backend CLI service.

**Architecture:** Add a JSON Patch `replace` operation for `/patient` containing `{"reference": "Patient/<id>", "display": "<name>"}` at index 0 of the operations array in `satusehat-panel/src/Core/BaseModuleController.php` and `php-service/lib/satusehat/EpisodeOfCareProcessor.php` before `/status`, ensuring full compliance with updated SATUSEHAT validation.

**Tech Stack:** PHP 8.1+, HL7 FHIR R4, JSON Patch (RFC 6902), PHPUnit.

**Spec:** User directive: "patch from episode of care need to include patient key's on it's payload... add json structure with ops replace for patients and using value patients (display and reference) like usually", placed at index 0 before `/status`.

## Global Constraints
- Every JSON Patch operation for `/patient` must follow standard RFC 6902 structure: `{"op": "replace", "path": "/patient", "value": {"reference": "Patient/...", "display": "..."}}`.
- The `/patient` operation must be positioned at index 0 in the `$ops` array, directly preceding `/status`.
- All automated test suites (`bash satusehat-panel/scripts/ci.sh` and `php php-service/tests/payload_shape_test.php`) must pass with zero failures.

---

### Task 1: Update `satusehat-panel` Test Suite for `/patient` PATCH Operation

**Files:**
- Modify: `satusehat-panel/tests/PanelUpdateStrategyTest.php:90-120`

**Interfaces:**
- Consumes: `satusehat-panel/src/Core/BaseModuleController.php`
- Produces: Failing test expecting `/patient` at index 0 of `patchOps` for `EpisodeOfCare`

- [ ] **Step 1: Write the failing test in `PanelUpdateStrategyTest.php`**

Update `testEpisodeOfCareWithIdRoutesToDirectPatch` in `satusehat-panel/tests/PanelUpdateStrategyTest.php` to include `patient` in the test payload and assert that `$call['ops'][0]` is `/patient`:

```php
    public function testEpisodeOfCareWithIdRoutesToDirectPatch(): void
    {
        $mock = $this->createMockClient();
        $payload = [
            'resourceType' => 'EpisodeOfCare',
            'id' => 'eoc-100',
            'status' => 'finished',
            'patient' => [
                'reference' => 'Patient/P1000',
                'display' => 'PASIEN TEST',
            ],
            'period' => ['end' => '2026-09-12T12:00:00+07:00'],
            'diagnosis' => [['condition' => ['reference' => 'Condition/c1']]],
        ];

        $res = $this->invokeSend('/EpisodeOfCare', $payload, $mock);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $mock->calls);
        $call = $mock->calls[0];

        $this->assertSame('PATCH', $call['method']);
        $this->assertSame('/EpisodeOfCare/eoc-100', $call['path']);
        $this->assertCount(4, $call['ops']);
        $this->assertSame('/patient', $call['ops'][0]['path']);
        $this->assertSame('Patient/P1000', $call['ops'][0]['value']['reference']);
        $this->assertSame('PASIEN TEST', $call['ops'][0]['value']['display']);
        $this->assertSame('/status', $call['ops'][1]['path']);
        $this->assertSame('/period/end', $call['ops'][2]['path']);
        $this->assertSame('/diagnosis', $call['ops'][3]['path']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php satusehat-panel/scripts/test-runner.php`
Expected: FAIL on `PanelUpdateStrategyTest.php` with `Failed asserting that 3 matches expected size 4` or `/status matches expected '/patient'`.

---

### Task 2: Implement `/patient` Operation in `satusehat-panel/src/Core/BaseModuleController.php`

**Files:**
- Modify: `satusehat-panel/src/Core/BaseModuleController.php:280-297`

**Interfaces:**
- Consumes: `$payload['patient']` from incoming `EpisodeOfCare` payload
- Produces: `$ops` array with `['op' => 'replace', 'path' => '/patient', 'value' => $payload['patient']]` as entry [0]

- [ ] **Step 1: Implement the minimal code in `BaseModuleController.php`**

In `satusehat-panel/src/Core/BaseModuleController.php`, update the `if ($endpoint === '/EpisodeOfCare')` block:

```php
                    if ($endpoint === '/EpisodeOfCare') {
                        // EpisodeOfCare: PUT triggers "Operation cannot be performed due to consent or privacy rules".
                        // Direct targeted PATCH bypasses consent engine on SATUSEHAT.
                        $ops = [];
                        if (!empty($payload['patient'])) {
                            $ops[] = ['op' => 'replace', 'path' => '/patient', 'value' => $payload['patient']];
                        }
                        $ops[] = ['op' => 'replace', 'path' => '/status', 'value' => $payload['status'] ?? 'finished'];
                        if (!empty($payload['period']['end'])) {
                            $ops[] = ['op' => 'replace', 'path' => '/period/end', 'value' => $payload['period']['end']];
                        }
                        if (!empty($payload['diagnosis'])) {
                            $ops[] = ['op' => 'replace', 'path' => '/diagnosis', 'value' => $payload['diagnosis']];
                        }
                        if (!empty($payload['statusHistory'])) {
                            $ops[] = ['op' => 'replace', 'path' => '/statusHistory', 'value' => $payload['statusHistory']];
                        }
                        $apiRes = $client->patch($url, $ops);
                    }
```

- [ ] **Step 2: Run test to verify it passes**

Run: `php satusehat-panel/scripts/test-runner.php`
Expected: `PanelUpdateStrategyTest.php` passes with 0 failures.

- [ ] **Step 3: Commit panel changes**

```bash
git add satusehat-panel/src/Core/BaseModuleController.php satusehat-panel/tests/PanelUpdateStrategyTest.php
git commit -m "feat(panel): prepend /patient replace operation to EpisodeOfCare PATCH payload"
```

---

### Task 3: Implement `/patient` Operation in `php-service/lib/satusehat/EpisodeOfCareProcessor.php`

**Files:**
- Modify: `php-service/lib/satusehat/EpisodeOfCareProcessor.php:255-275` (Phase 2 PATCH)
- Modify: `php-service/lib/satusehat/EpisodeOfCareProcessor.php:510-520` (`patchAndRepost`)

**Interfaces:**
- Consumes: `$idPasien`, `$p['nm_pasien']`, `$payload['patient']`
- Produces: `$ops` with `/patient` at index 0 before `/status`

- [ ] **Step 1: Implement in Phase 2 PATCH of `EpisodeOfCareProcessor.php`**

In `php-service/lib/satusehat/EpisodeOfCareProcessor.php` around line 260:

```php
            $ops = [];

            // 1. Replace patient
            if ($idPasien) {
                $ops[] = [
                    'op' => 'replace',
                    'path' => '/patient',
                    'value' => [
                        'reference' => 'Patient/' . $idPasien,
                        'display'   => $p['nm_pasien'] ?? ''
                    ]
                ];
            }

            // 2. Replace status
            $ops[] = ['op' => 'replace', 'path' => '/status', 'value' => 'finished'];
```

- [ ] **Step 2: Implement in `patchAndRepost()` of `EpisodeOfCareProcessor.php`**

In `php-service/lib/satusehat/EpisodeOfCareProcessor.php` around line 510:

```php
        // Build PATCH operations
        $operations = [];
        if (!empty($payload['patient'])) {
            $operations[] = ['op' => 'replace', 'path' => '/patient', 'value' => $payload['patient']];
        }
        $operations[] = ['op' => 'replace', 'path' => '/status', 'value' => $newStatus];
```

- [ ] **Step 3: Verify all test suites pass**

Run:
1. `php php-service/tests/payload_shape_test.php`
2. `bash satusehat-panel/scripts/ci.sh`
Expected: 100% tests pass.

- [ ] **Step 4: Commit php-service changes**

```bash
git add php-service/lib/satusehat/EpisodeOfCareProcessor.php
git commit -m "feat(satusehat): prepend /patient replace operation in EpisodeOfCare processor PATCH"
```
