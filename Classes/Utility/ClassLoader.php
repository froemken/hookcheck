<?php
namespace StefanFroemken\Hookcheck\Utility;

/**
 * (c) 2014 Sebastian Fischer <typo3@evoweb.de>
 *
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Class ClassLoader
 */
class ClassLoader implements \TYPO3\CMS\Core\SingletonInterface
{
    /**
     * @var \TYPO3\CMS\Core\Cache\Frontend\PhpFrontend
     */
    protected $cacheInstance;
    
    /**
     * @var array
     */
    protected $allowedClasses = array();
    
    /**
     * Do not log hooks which are executed less than this value
     *
     * @var float
     */
    protected $minWaitingTime = 0.001;
    
    /**
     * Register instance of this class as spl autoloader
     *
     * @return void
     */
    public static function registerAutoloader()
    {
        spl_autoload_register([new self(), 'loadClass'], true, true);
    }
    
    /**
     * Initialize cache
     *
     * @return \TYPO3\CMS\Core\Cache\Frontend\PhpFrontend
     */
    public function initializeCache()
    {
        if (is_null($this->cacheInstance)) {
            /** @var CacheManager $cacheManager */
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            $this->cacheInstance = $cacheManager->getCache('hookcheck');
        }
        return $this->cacheInstance;
    }
    
    /**
     * Loads php files containing classes or interfaces part of the
     * classes directory of an extension.
     *
     * @param string $className Name of the class/interface to load
     * @return bool
     */
    public function loadClass($className)
    {
        $className = ltrim($className, '\\');
        
        // do not try to autoload TYPO3 classes
        if (strpos($className, 'StefanFroemken\\Hookcheck') === false) {
            return false;
        }
        
        $cacheEntryIdentifier = 'tx_hookcheck_' . strtolower(str_replace('/', '_', $this->changeClassName($className)));
        
        $classCache = $this->initializeCache();
        if (!empty($cacheEntryIdentifier) && !$classCache->has($cacheEntryIdentifier)) {
            $this->reBuild();
        }
        
        if (!empty($cacheEntryIdentifier) && $classCache->has($cacheEntryIdentifier)) {
            $classCache->requireOnce($cacheEntryIdentifier);
        }
        
        return true;
    }
    
    /**
     * Get class name from classRef
     *
     * @param string $classRef
     *
     * @return string
     */
    protected static function getClassNameFromClassRef($classRef)
    {
        if (strpos($classRef, ':') !== false) {
            list($_, $class) = GeneralUtility::revExplode(':', $classRef, 2);
        } else {
            $class = $classRef;
        }
        
        $parts = explode('->', $class);
        if (count($parts) === 2) {
            $class = $parts[0];
        }
        
        if (StringUtility::endsWith($class, '.php')) {
            $class = '';
        }
        
        if (StringUtility::beginsWith($class, '&')) {
            $class = str_replace('&', '', $class);
        }
        
        return $class;
    }
    
    /**
     * Register
     */
    public static function registerClassAliases()
    {
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php'] as $hookName => $hooks) {
            foreach ($hooks as $key => $classRef) {
                $className = self::getClassNameFromClassRef($classRef);
                if (empty($className)) {
                    continue;
                }
                $newClassName = $hookName . $key;
                $newFullQualifiedClassName = 'StefanFroemken\\Hookcheck\\' . $newClassName;
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][$className]['className'] = $newFullQualifiedClassName;
            }
        }
    }
    
    /**
     * Re-Build class cache
     *
     * @return void
     *
     * @throws \Exception
     */
    public function reBuild()
    {
        $allowedCoreHooks = [
            't3lib/class.t3lib_befunc.php',
            't3lib/class.t3lib_db.php',
            't3lib/class.t3lib_tceforms.php',
            't3lib/class.t3lib_tceforms_inline.php',
            't3lib/class.t3lib_tcemain.php',
            't3lib/class.t3lib_tsfebeuserauth.php',
            't3lib/class.t3lib_tstemplate.php',
            't3lib/class.t3lib_userauth.php',
            't3lib/class.t3lib_userauthgroup.php',
        ];
        foreach ($allowedCoreHooks as $allowedCoreHook) {
            if (!empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$allowedCoreHook])) {
                foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$allowedCoreHook] as $hookName => $hooks) {
                    $hookName = str_replace('::', '', $hookName);
                    foreach ($hooks as $key => $classRef) {
                        $key = str_replace('::', '', $key);
                        $className = $this->getClassNameFromClassRef($classRef);
                        if (empty($className)) {
                            continue;
                        }
                        $newClassName = $hookName . $key;
            
                        $rows = [];
                        $rows[] = 'namespace StefanFroemken\\Hookcheck;';
                        $rows[] = sprintf('class %s extends \\%s', $newClassName, $className);
                        $rows[] = '{';
                        $rows[] = $this->buildMethods($className);
                        $rows[] = '}';
            
                        $code = implode(LF, $rows);
            
                        $cacheEntryIdentifier = 'tx_hookcheck_' . strtolower(str_replace('/', '_', $this->changeClassName($newClassName)));
                        try {
                            $this->cacheInstance->set($cacheEntryIdentifier, $code);
                        } catch (\Exception $e) {
                            throw new \Exception($e->getMessage());
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Build methods which calls the parent methods
     *
     * @param string $className
     *
     * @return string
     */
    protected function buildMethods($className)
    {
        $newMethods = [];
        try {
            $refClass = new \ReflectionClass($className);
            $publicMethods = $refClass->getMethods(\ReflectionMethod::IS_PUBLIC);
            foreach ($publicMethods as $publicMethod) {
                $parameters = $publicMethod->getParameters();
                $newOrigParameters = [];
                $newParameters = [];
                foreach ($parameters as $parameter) {
                    $defaultValue = '';
                    if ($parameter->isOptional()) {
                        if ($parameter->getDefaultValue() === null) {
                            $defaultValue = ' = null';
                        } elseif($parameter->getDefaultValue() === array()) {
                            $defaultValue = ' = array()';
                        } else {
                            $defaultValue = ' = ' . $parameter->getDefaultValue();
                        }
                    }
                    $propertyClassName = $this->getPropertyClassName($parameter);
                    if ($parameter->isPassedByReference()) {
                        $newOrigParameters[] = $propertyClassName . ' &$' . $parameter->getName() . $defaultValue;
                    } else {
                        $newOrigParameters[] = $propertyClassName . ' $' . $parameter->getName() . $defaultValue;
                    }
                    $newParameters[] = '$' . $parameter->getName() . $defaultValue;
                }
                $newMethods[] = sprintf('    public function %s(%s)',
                    $publicMethod->getName(),
                    implode(', ', $newOrigParameters)
                );
                $newMethods[] = '    {';
                $newMethods[] = '        $msStart = microtime(true);';
                $newMethods[] = '        $tmp = parent::' . $publicMethod->getName() . '(' . implode(', ', $newParameters) . ');';
                $newMethods[] = '        if ((microtime(true) - $msStart) > ' . $this->minWaitingTime . ') {';
                $newMethods[] = '            \\TYPO3\\CMS\\Core\\Utility\\DebugUtility::debug(microtime(true) - $msStart, \'' . $className . ':' . $publicMethod->getName() . '\');';
                $newMethods[] = '        }';
                $newMethods[] = '        return $tmp;';
                $newMethods[] = '    }';
            }
        } catch (\Exception $e) {
            $newMethods = [];
        }

        return implode(LF, $newMethods);
    }
    
    /**
     * Get property class name
     *
     * @param \ReflectionParameter $parameter
     *
     * @return string
     */
    protected function getPropertyClassName(\ReflectionParameter $parameter)
    {
        if ($parameter->isArray()) {
            return 'array';
        }
        try {
            if (!$class = $parameter->getClass()) {
                return '';
            }
            $propertyClassName = $class->getName();
            if (
                $propertyClassName === 'string' ||
                $propertyClassName === 'int' ||
                $propertyClassName === 'integer'
            ) {
                $propertyClassName = '';
            } else {
                $propertyClassName = '\\' . $propertyClassName;
            }
        } catch (\Exception $e) {
            // class does not exists. \TYPO3\CMS\...\string
            $propertyClassName = '';
        }
        return $propertyClassName;
    }
    
    /**
     * Change class name
     *
     * @param string $className
     *
     * @return string
     */
    protected function changeClassName($className)
    {
        return str_replace('\\', '/', str_replace('StefanFroemken\\Hookcheck\\', '', $className));
    }
}
