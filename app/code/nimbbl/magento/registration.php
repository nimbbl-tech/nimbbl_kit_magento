<?php

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Nimbbl_Magento',
    __DIR__
);

// PSR-4 autoloader for the bundled Nimbbl PHP SDK.
//
// Maps `Nimbbl\Api\<Class>` → lib/nimbbl-php-sdk/src/<Class>.php so the
// plugin works without Composer — no `nimbbl/nimbbl-sdk` package required.
// If Composer's autoloader already has the SDK (e.g. on a legacy Composer
// install) it will handle the class first; this loader is a safe fallback.
spl_autoload_register(function (string $class): void {
    $prefix    = 'Nimbbl\\Api\\';
    $prefixLen = 10; // strlen('Nimbbl\\Api\\')
    if (strncmp($class, $prefix, $prefixLen) !== 0) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, $prefixLen));
    $file = __DIR__
        . DIRECTORY_SEPARATOR . 'lib'
        . DIRECTORY_SEPARATOR . 'nimbbl-php-sdk'
        . DIRECTORY_SEPARATOR . 'src'
        . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}, false, false);
