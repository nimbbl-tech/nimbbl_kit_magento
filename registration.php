<?php

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Nimbbl_Magento',
    __DIR__
);
require_once __DIR__ . '/nimbbl-sdk/Nimbbl.php';