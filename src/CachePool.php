<?php
declare(strict_types=1);

namespace WpOop\TransientCache;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Psr\SimpleCache\CacheInterface;
use RangeException;
use wpdb;
use WpOop\TransientCache\Exception\CacheException;
use WpOop\TransientCache\Exception\InvalidArgumentException;

/**
 * {@inheritDoc}
 *
 * Uses WordPress transients as storage medium.
 */
class CachePool implements CacheInterface
{
    public const RESERVED_KEY_SYMBOLS = '{}()/\@:';
    public const NAMESPACE_SEPARATOR = '/';

    protected const TABLE_NAME_OPTIONS = 'options';
    protected const FIELD_NAME_OPTION_NAME = 'option_name';
    protected const OPTION_NAME_PREFIX_TRANSIENT = '_transient_';
    protected const OPTION_NAME_PREFIX_TIMEOUT = 'timeout_';
    protected const OPTION_NAME_MAX_LENGTH = 191;

    /**
     * @var wpdb
     */
    protected $wpdb;
    /**
     * @var string
     */
    protected $poolName;
    /**
     * @var mixed
     */
    protected $defaultValue;

    /**
     * @param wpdb   $wpdb         The WP database object.
     * @param string $poolName     The name of this cache pool. Must be unique to this instance.
     * @param mixed $defaultValue  A random value. Used for false-negative detection. The more chaotic - the better.
     */
    public function __construct(wpdb $wpdb, string $poolName, $defaultValue)
    {
        if ($poolName === static::OPTION_NAME_PREFIX_TIMEOUT) {
            throw new RangeException(sprintf('Pool name cannot be "%1$s"', static::OPTION_NAME_PREFIX_TIMEOUT));
        }

        $this->wpdb = $wpdb;
        $this->poolName = $poolName;
        $this->defaultValue = $defaultValue;
    }

    /**
     * @inheritDoc
     */
    public function get($key, $default = null)
    {
        $this->validateKey($key);
        $key = $this->prepareKey($key);
        $value = $this->getTransient($key);

        if ($value !== false) {
            return $value;
        }

        $prefix = $this->getOptionNamePrefix();
        $optionValue = $this->getOption("{$prefix}{$key}", $this->defaultValue);

        if ($optionValue === $this->defaultValue) {
            return $default;
        }

        return $value;
    }

    /**
     * @inheritDoc
     */
    public function set($key, $value, $ttl = null)
    {
        $this->validateKey($key);
        $origKey = $key;
        $key = $this->prepareKey($key);

        try {
            $ttl = $ttl instanceof DateInterval
                ? $this->getIntervalDuration($ttl)
                : $ttl;
        } catch (Exception $e) {
            throw new CacheException(sprintf('Could not normalize cache TTL'));
        }

        $ttl = is_null($ttl) ? 0 : $ttl;

        if (!is_int($ttl)) {
            throw new InvalidArgumentException(sprintf('The specified cache TTL is invalid'));
        }

        if (!set_transient($key, $value, $ttl)) {
            throw new CacheException(sprintf('Could not write value for key "%1$s" to cache', $origKey));
        }
    }

    /**
     * @inheritDoc
     */
    public function delete($key)
    {
        $this->validateKey($key);
        $origKey = $key;
        $key = $this->prepareKey($key);

        if (!delete_transient($key)) {
            throw new CacheException(sprintf('Could not delete cache for key "%1$s"', $origKey));
        }
    }

    /**
     * @inheritDoc
     */
    public function clear()
    {
        try {
            $keys = $this->getAllKeys();
            $this->deleteMultiple($keys);
        } catch (Exception $e) {
            throw new CacheException(sprintf('Could not clear cache'), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getMultiple($keys, $default = null)
    {
        if (!is_iterable($keys)) {
            throw new InvalidArgumentException(sprintf('List of keys is not a list'));
        }

        $entries = [];
        foreach ($keys as $key) {
            $value = $this->get($key, $default);
            $entries[$key] = $value;
        }

        return $entries;
    }

    /**
     * @inheritDoc
     */
    public function setMultiple($values, $ttl = null)
    {
        if (!is_iterable($values)) {
            throw new InvalidArgumentException(sprintf('List of keys is not a list'));
        }

        $ttl = $ttl instanceof DateInterval
            ? $this->getIntervalDuration($ttl)
            : $ttl;

        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteMultiple($keys)
    {
        if (!is_iterable($keys)) {
            throw new InvalidArgumentException(sprintf('List of keys is not a list'));
        }

        foreach ($keys as $key) {
            $this->delete($key);
        }
    }

    /**
     * @inheritDoc
     */
    public function has($key)
    {
        $default = $this->defaultValue;
        $prefix = $this->getOptionNamePrefix();
        $value = $this->getOption("{$prefix}{$key}", $default);

        return $value !== $default;
    }

    /**
     * Retrieves a transient value, by key.
     *
     * @param string $key The transient key.
     *
     * @return string|bool The transient value.
     */
    protected function getTransient(string $key)
    {
        $value = get_transient($key);

        return $value;
    }

    /**
     * Retrieves an option value by name.
     *
     * @param string $name    The option name.
     * @param null   $default The value to return if option not found.
     *
     * @return string The option value.
     */
    protected function getOption(string $name, $default = null): string
    {
        return (string) get_option($name, $default);
    }

    /**
     * Validates a cache key.
     *
     * @param string $key The key to validate.
     *
     * @throws InvalidArgumentException If key is invalid.
     */
    protected function validateKey(string $key)
    {
        $prefix = $this->getTimeoutOptionNamePrefix();
        if (strlen("{$prefix}{$key}") > static::OPTION_NAME_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Given the %1$d char length of this cache pool\'s name, the key length must not exceed %2$d chars',
                strlen($this->poolName),
                static::OPTION_NAME_MAX_LENGTH - strlen($prefix)
            ));
        }

        $reservedSymbols = str_split(static::RESERVED_KEY_SYMBOLS, 1);

        foreach ($reservedSymbols as $symbol) {
            if (strpos($key, $symbol) !== false) {
                throw new InvalidArgumentException(sprintf('Cache key "%1$s" is invalid', $key));
            }
        }
    }

    /**
     * Prepares a cache key, giving it a namespace.
     *
     * @param string $key The key to prepare.
     *
     * @return string The prepared key.
     */
    protected function prepareKey(string $key): string
    {
        $namespace = $this->poolName;
        $separator = static::NAMESPACE_SEPARATOR;
        return "{$namespace}{$separator}{$key}";
    }

    /**
     * Retrieves all keys that correspond to this cache pool.
     *
     * @throws Exception If problem retrieving.
     *
     * @return iterable A list of keys.
     */
    protected function getAllKeys(): iterable
    {
        $tableName = $this->getTableName(static::TABLE_NAME_OPTIONS);
        $fieldName = static::FIELD_NAME_OPTION_NAME;
        $prefix = $this->getOptionNamePrefix();
        $query = "SELECT `$fieldName` FROM `$tableName` WHERE `$fieldName` LIKE '%$prefix'";
        $results = $this->selectColumn($query, $fieldName);
        $keys = $this->getCacheKeysFromOptionNames($results);

        return $keys;
    }

    /**
     * Runs a SELECT query, and retrieves a list of values for a field with the specified name.
     *
     * @param string $query      The SELECT query.
     * @param string $columnName The name of the field to retrieve.
     * @param array  $args       Query parameters.
     *
     * @return iterable The list of values for the specified field.
     */
    protected function selectColumn(string $query, string $columnName, array $args = []): iterable
    {
        $query = $this->prepareQuery($query, $args);
        $results = $this->wpdb->get_col($query, $columnName);

        return $results;
    }

    /**
     * Retrieve the name of a DB table by its identifier.
     *
     * @param string $identifier The table identifier.
     *
     * @return string The table name in the DB.
     */
    protected function getTableName(string $identifier): string
    {
        $prefix = $this->wpdb->prefix;
        $tableName = "{$prefix}{$identifier}";

        return $tableName;
    }

    /**
     * Prepares a parameterized query.
     *
     * @param string $query  The query to prepare. May include placeholders.
     * @param array  $params The parameters that will replace corresponding placeholders in the query.
     *
     * @return string The prepared query. Parameters will be interpolated.
     */
    protected function prepareQuery(string $query, array $params = []): string
    {
        if (empty($params)) {
            return $query;
        }

        $prepared = $this->wpdb->prepare($query, ...$params);

        return $prepared;
    }

    /**
     * Retrieves all cache keys that correspond to the given list of option names
     *
     * @param iterable $optionNames
     *
     * @throws Exception If problem retrieving.
     *
     * @return iterable A list of cache keys.
     */
    protected function getCacheKeysFromOptionNames(iterable $optionNames): iterable
    {
        $keys = [];

        foreach ($optionNames as $name) {
            $key = $this->getCacheKeyFromOptionName($name);
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Retrieves the prefix of option names that represent transients of this cache pool.
     *
     * @return string The prefix.
     */
    protected function getOptionNamePrefix(): string
    {
        $transientPrefix = static::OPTION_NAME_PREFIX_TRANSIENT;
        $separator = static::NAMESPACE_SEPARATOR;
        $namespace = $this->poolName;
        $prefix = "{$transientPrefix}{$namespace}{$separator}";

        return $prefix;
    }

    /**
     * Retrieves the prefix of option names that represent transient timeouts of this cache pool.
     *
     * @return string The prefix.
     */
    protected function getTimeoutOptionNamePrefix(): string
    {
        $transientPrefix = static::OPTION_NAME_PREFIX_TRANSIENT . static::OPTION_NAME_PREFIX_TIMEOUT;
        $separator = static::NAMESPACE_SEPARATOR;
        $namespace = $this->poolName;
        $prefix = "{$transientPrefix}{$namespace}{$separator}";

        return $prefix;
    }

    /**
     * Retrieves the cache key that corresponds to the specified option name.
     *
     * @param string $name The option name.
     *
     * @return string The cache key.
     *
     * @throws Exception If problem determining key.
     */
    protected function getCacheKeyFromOptionName(string $name): string
    {
        $prefix = $this->getOptionNamePrefix();

        if (strpos($name, $prefix) !== 0) {
            throw new RangeException(sprintf('Option name "%1$s" is not formed according to this cache pool', $name));
        }

        $key = substr($name, strlen($prefix));

        return $key;
    }

    /**
     * Retrieves the total duration from an interval.
     *
     * @param DateInterval $interval The interval.
     *
     * @throws Exception If problem retrieving.
     *
     * @return int The duration in seconds.
     */
    protected function getIntervalDuration(DateInterval $interval): int
    {
        $reference = new DateTimeImmutable();
        $endTime = $reference->add($interval);

        return $endTime->getTimestamp() - $reference->getTimestamp();
    }
}
