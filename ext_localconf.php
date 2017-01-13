<?php
if (!defined('TYPO3_MODE')) {
    die ('Access denied.');
}

// Register cache frontend for proxy class generation
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['hookcheck'] = [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\PhpFrontend::class,
    'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
    'groups' => [
        'all',
        'system',
    ],
    'options' => [
        'defaultLifetime' => 0,
    ]
];
