<?php

/**
 * SATUSEHAT environment URL table — single source of truth for the API
 * hosts (T33). Every consumer (CredentialLocator, Settings UI, .env.example)
 * resolves through here; hardcoded api-satusehat hosts elsewhere are bugs.
 */
return [
    'dev' => [
        'label' => 'Development',
        'auth_url' => 'https://api-satusehat-dev.dto.kemkes.go.id/oauth2/v1',
        'base_url' => 'https://api-satusehat-dev.dto.kemkes.go.id/fhir-r4/v1',
    ],
    'sandbox' => [
        'label' => 'Sandbox (Staging)',
        'auth_url' => 'https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1',
        'base_url' => 'https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1',
    ],
    'production' => [
        'label' => 'Production',
        'auth_url' => 'https://api-satusehat.kemkes.go.id/oauth2/v1',
        'base_url' => 'https://api-satusehat.kemkes.go.id/fhir-r4/v1',
    ],
];