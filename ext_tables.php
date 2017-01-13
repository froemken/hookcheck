<?php
if (!defined('TYPO3_MODE')) {
    die ('Access denied.');
}

\StefanFroemken\Hookcheck\Utility\ClassLoader::registerClassAliases();
\StefanFroemken\Hookcheck\Utility\ClassLoader::registerAutoloader();
