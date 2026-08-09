<?php

declare(strict_types=1);

define('PANEL_BASE', dirname(__DIR__));
define('PANEL_SRC', PANEL_BASE . '/src');
define('BASE_DIR', PANEL_BASE);
define('PANEL_TEST_STORAGE', sys_get_temp_dir() . '/satusehat-panel-tests-' . getmypid());

if (!is_dir(PANEL_TEST_STORAGE)) {
    mkdir(PANEL_TEST_STORAGE, 0755, true);
}

// Prefer composer autoload (dev/test); fall back to the legacy loader so
// the test suite also runs in a drop-in deployment without vendor/.
if (is_file(PANEL_BASE . '/vendor/autoload.php')) {
    require PANEL_BASE . '/vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        if (!str_starts_with($class, 'SatusehatPanel\\')) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen('SatusehatPanel\\')));
        $file = PANEL_SRC . '/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });

    foreach ([
        'SatuSehatClient',
        'PayloadBuilder',
        'AllergyDictionary',
        'ObservationTTVDictionary',
        'EpisodeOfCareType',
        'Logger',
        'CredentialLocator',
        'SatuSehatConfig',
        'DateTimeUtil',
        'NumberUtil',
    ] as $lib) {
        require_once PANEL_SRC . '/Util/' . $lib . '.php';
    }
}

date_default_timezone_set('Asia/Jakarta');
