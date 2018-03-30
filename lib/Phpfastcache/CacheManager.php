<?php
/**
 *
 * This file is part of phpFastCache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author Khoa Bui (khoaofgod)  <khoaofgod@gmail.com> http://www.phpfastcache.com
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 *
 */
declare(strict_types=1);

namespace Phpfastcache;

use Phpfastcache\Config\ConfigurationOption;
use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use Phpfastcache\Exceptions\{
  PhpfastcacheDeprecatedException, PhpfastcacheDriverCheckException, PhpfastcacheDriverNotFoundException, PhpfastcacheInstanceNotFoundException, PhpfastcacheInvalidArgumentException, PhpfastcacheInvalidConfigurationException, PhpfastcacheLogicException, PhpfastcacheUnsupportedOperationException
};
use Phpfastcache\Util\ClassNamespaceResolverTrait;

/**
 * Class CacheManager
 * @package phpFastCache
 *
 * @method static ExtendedCacheItemPoolInterface Apc() Apc($config = []) Return a driver "Apc" instance
 * @method static ExtendedCacheItemPoolInterface Apcu() Apcu($config = []) Return a driver "Apcu" instance
 * @method static ExtendedCacheItemPoolInterface Cassandra() Cassandra($config = []) Return a driver "Cassandra" instance
 * @method static ExtendedCacheItemPoolInterface Cookie() Cookie($config = []) Return a driver "Cookie" instance
 * @method static ExtendedCacheItemPoolInterface Couchbase() Couchbase($config = []) Return a driver "Couchbase" instance
 * @method static ExtendedCacheItemPoolInterface Couchdb() Couchdb($config = []) Return a driver "Couchdb" instance
 * @method static ExtendedCacheItemPoolInterface Devnull() Devnull($config = []) Return a driver "Devnull" instance
 * @method static ExtendedCacheItemPoolInterface Files() Files($config = []) Return a driver "files" instance
 * @method static ExtendedCacheItemPoolInterface Leveldb() Leveldb($config = []) Return a driver "Leveldb" instance
 * @method static ExtendedCacheItemPoolInterface Memcache() Memcache($config = []) Return a driver "Memcache" instance
 * @method static ExtendedCacheItemPoolInterface Memcached() Memcached($config = []) Return a driver "Memcached" instance
 * @method static ExtendedCacheItemPoolInterface Memstatic() Memstatic($config = []) Return a driver "Memstatic" instance
 * @method static ExtendedCacheItemPoolInterface Mongodb() Mongodb($config = []) Return a driver "Mongodb" instance
 * @method static ExtendedCacheItemPoolInterface Predis() Predis($config = []) Return a driver "Predis" instance
 * @method static ExtendedCacheItemPoolInterface Redis() Redis($config = []) Return a driver "Pedis" instance
 * @method static ExtendedCacheItemPoolInterface Riak() Riak($config = []) Return a driver "Riak" instance
 * @method static ExtendedCacheItemPoolInterface Sqlite() Sqlite($config = []) Return a driver "Sqlite" instance
 * @method static ExtendedCacheItemPoolInterface Ssdb() Ssdb($config = []) Return a driver "Ssdb" instance
 * @method static ExtendedCacheItemPoolInterface Wincache() Wincache($config = []) Return a driver "Wincache" instance
 * @method static ExtendedCacheItemPoolInterface Xcache() Xcache($config = []) Return a driver "Xcache" instance
 * @method static ExtendedCacheItemPoolInterface Zenddisk() Zenddisk($config = []) Return a driver "Zend disk cache" instance
 * @method static ExtendedCacheItemPoolInterface Zendshm() Zendshm($config = []) Return a driver "Zend memory cache" instance
 *
 */
class CacheManager
{
    use ClassNamespaceResolverTrait;

    /**
     * @var ConfigurationOption
     */
    protected static $config;

    /**
     * @var int
     */
    public static $ReadHits = 0;

    /**
     * @var int
     */
    public static $WriteHits = 0;

    /**
     * @var string
     */
    protected static $namespacePath;

    /**
     * @var ExtendedCacheItemPoolInterface[]
     */
    protected static $instances = [];

    /**
     * @param string $driver
     * @param array|ConfigurationOption $config
     * @param string $instanceId
     *
     * @return ExtendedCacheItemPoolInterface
     *
     * @throws PhpfastcacheDriverCheckException
     * @throws PhpfastcacheInvalidConfigurationException
     * @throws PhpfastcacheDriverNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     */
    public static function getInstance($driver = 'auto', $config = null, $instanceId = null): ExtendedCacheItemPoolInterface
    {
        static $badPracticeOmeter = [];

        if ($instanceId !== null && !\is_string($instanceId)) {
            throw new PhpfastcacheInvalidArgumentException('The Instance ID must be a string');
        }

        if (\is_array($config)) {
            $config = new ConfigurationOption($config);
            trigger_error(
              'The CacheManager will drops the support of primitive configuration arrays, use a "\Phpfastcache\Config\ConfigurationOption" object instead',
              E_USER_DEPRECATED
            );
        }elseif ($config === null){
            $config = self::getDefaultConfig();
        }else if(!($config instanceof ConfigurationOption)){
            throw new PhpfastcacheInvalidArgumentException(\sprintf('Unsupported config type: %s', gettype($config)));
        }

        $driver = self::standardizeDriverName($driver);

        if (!$driver || $driver === 'Auto') {
            $driver = self::getAutoClass($config);
        }

        $instance = $instanceId ?: md5($driver . \serialize($config->toArray()));

        if (!isset(self::$instances[ $instance ])) {
            $badPracticeOmeter[ $driver ] = 1;
            $driverClass = self::getNamespacePath() . $driver . '\Driver';
            try {
                if (\class_exists($driverClass)) {
                    $configClass = $driverClass::getConfigClass();
                    self::$instances[ $instance ] = new $driverClass(new $configClass($config->toArray()), $instance);
                    self::$instances[ $instance ]->setEventManager(EventManager::getInstance());
                } else {
                    throw new PhpfastcacheDriverNotFoundException(\sprintf('The driver "%s" does not exists', $driver));
                }
            } catch (PhpfastcacheDriverCheckException $e) {
                if ($config->getFallback()) {
                    try {
                        $fallback = $config->getFallback();
                        $config->setFallback('');
                        trigger_error(\sprintf('The "%s" driver is unavailable at the moment, the fallback driver "%s" has been used instead.', $driver, $fallback), E_USER_WARNING);
                        return self::getInstance($fallback, $config->getFallbackConfig());
                    } catch (PhpfastcacheInvalidArgumentException $e) {
                        throw new PhpfastcacheInvalidConfigurationException('Invalid fallback driver configuration', 0, $e);
                    }
                } else {
                    throw new PhpfastcacheDriverCheckException($e->getMessage(), $e->getCode(), $e);
                }
            }
        } else if ($badPracticeOmeter[ $driver ] >= 2) {
            trigger_error('[' . $driver . '] Calling many times CacheManager::getInstance() for already instanced drivers is a bad practice and have a significant impact on performances.
           See https://github.com/PHPSocialNetwork/phpfastcache/wiki/[V5]-Why-calling-getInstance%28%29-each-time-is-a-bad-practice-%3F');
        }

        $badPracticeOmeter[ $driver ]++;

        return self::$instances[ $instance ];
    }

    /**
     * @param string $instanceId
     *
     * @return ExtendedCacheItemPoolInterface
     *
     * @throws PhpfastcacheInvalidArgumentException
     * @throws PhpfastcacheInstanceNotFoundException
     */
    public static function getInstanceById($instanceId): ExtendedCacheItemPoolInterface
    {
        if ($instanceId !== null && !\is_string($instanceId)) {
            throw new PhpfastcacheInvalidArgumentException('The Instance ID must be a string');
        }

        if (isset(self::$instances[ $instanceId ])) {
            return self::$instances[ $instanceId ];
        }

        throw new PhpfastcacheInstanceNotFoundException(\sprintf('Instance ID %s not found', $instanceId));
    }

    /**
     * This method is intended for internal
     * use only and should not be used for
     * any external development use the
     * getInstances() method instead
     *
     * @internal
     * @return ExtendedCacheItemPoolInterface[]
     */
    public static function getInstances(): array
    {
        return self::$instances;
    }

    /**
     * This method is intended for internal
     * use only and should not be used for
     * any external development use the
     * getInstances() method instead
     *
     * @todo Use a proper way to passe them as a reference ?
     * @internal
     * @return ExtendedCacheItemPoolInterface[]
     */
    public static function &getInternalInstances(): array
    {
        return self::$instances;
    }

    /**
     * @param ConfigurationOption $config
     * @return string
     * @throws PhpfastcacheDriverCheckException
     * @throws \Phpfastcache\Exceptions\PhpfastcacheLogicException
     */
    public static function getAutoClass(ConfigurationOption $config): string
    {
        static $autoDriver;

        if ($autoDriver === null) {
            foreach (self::getDriverList() as $driver) {
                try {
                    self::getInstance($driver, $config);
                    $autoDriver = $driver;
                    break;
                } catch (PhpfastcacheDriverCheckException $e) {
                    continue;
                }
            }
        }

        if(!$autoDriver || !\is_string($autoDriver)){
            throw new PhpfastcacheLogicException('Unable to find out a valid driver automatically');
        }

        return $autoDriver;
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return \Psr\Cache\ExtendedCacheItemPoolInterface
     */
    public static function __callStatic(string $name, array $arguments): ExtendedCacheItemPoolInterface
    {
        $options = (\array_key_exists(0, $arguments) && \is_array($arguments) ? $arguments[ 0 ] : []);

        return self::getInstance($name, $options);
    }

    /**
     * @return bool
     */
    public static function clearInstances(): bool
    {
        self::$instances = [];

        gc_collect_cycles();
        return !\count(self::$instances);
    }

    /**
     * @return string
     */
    public static function getNamespacePath(): string
    {
        return self::$namespacePath ?: self::getDefaultNamespacePath();
    }

    /**
     * @return string
     */
    public static function getDefaultNamespacePath(): string
    {
        return  __NAMESPACE__ . '\Drivers\\';
    }

    /**
     * @param string $path
     */
    public static function setNamespacePath($path)
    {
        self::$namespacePath = \trim($path, "\\") . '\\';
    }

    /**
     * @param ConfigurationOption $config
     */
    public static function setDefaultConfig(ConfigurationOption $config)
    {
        self::$config = $config;
    }

    /**
     * @return ConfigurationOption
     */
    public static function getDefaultConfig(): ConfigurationOption
    {
        return self::$config ?: self::$config = new ConfigurationOption();
    }

    /**
     * @return array
     * @deprecated As of V7 will be removed soon or later, use CacheManager::getDriverList() instead
     */
    public static function getStaticSystemDrivers(): array
    {
        trigger_error(\sprintf('Method "%s" is deprecated as of the V7 and will be removed soon or later, use CacheManager::getDriverList() instead.', __METHOD__), E_USER_DEPRECATED);
        return [
          'Apc',
          'Apcu',
          'Cassandra',
          'Couchbase',
          'Couchdb',
          'Devnull',
          'Files',
          'Leveldb',
          'Memcache',
          'Memcached',
          'Memstatic',
          'Mongodb',
          'Predis',
          'Redis',
          'Riak',
          'Ssdb',
          'Sqlite',
          'Wincache',
          'Xcache',
          'Zenddisk',
          'Zendshm',
        ];
    }

    /**
     * @return array
     * @deprecated As of V7 will be removed soon or later, use CacheManager::getDriverList() instead
     */
    public static function getStaticAllDrivers(): array
    {
        trigger_error(\sprintf('Method "%s" is deprecated as of the V7 and will be removed soon or later, use CacheManager::getDriverList() instead.', __METHOD__), E_USER_DEPRECATED);
        return \array_merge(self::getStaticSystemDrivers(), [
          'Devtrue',
          'Devfalse',
          'Cookie',
        ]);
    }

    /**
     * @return string[]
     * @throws PhpfastcacheUnsupportedOperationException
     */
    public static function getDriverList(): array
    {
        static $driverList;

        if(self::getDefaultNamespacePath() === self::getNamespacePath()){
            if($driverList === null){
                $prefix = 'Phpfastcache\Drivers\\';
                $classMap = self::createClassMap(__DIR__ . '/Drivers');
                $driverList = [];

                foreach ($classMap as $class => $file) {
                    $driverList[] = str_replace($prefix, '', substr($class, 0, strrpos($class, '\\') ));
                }

                $driverList = array_values(array_unique($driverList));
            }

            return $driverList;
        }

        throw new PhpfastcacheUnsupportedOperationException('Cannot get the driver list if the default namespace path has changed.');
    }

    /**
     * @param string $driverName
     * @return string
     */
    public static function standardizeDriverName(string $driverName): string
    {
        return \ucfirst(\strtolower(\trim($driverName)));
    }
}
