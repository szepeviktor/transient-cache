<?php
declare(strict_types=1);

namespace WpOop\TransientCache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use wpdb;

/**
 * @inheritDoc
 */
class CachePoolFactory implements CachePoolFactoryInterface
{
    /**
     * @var wpdb
     */
    protected $wpdb;
    /**
     * @var int|DateInterval
     */
    protected $defaultTtl;

    public function __construct(wpdb $wpdb, $defaultTtl = 0)
    {
        $this->wpdb = $wpdb;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * @inheritDoc
     */
    public function createCachePool(string $poolName): CacheInterface
    {
        $default = uniqid('default');
        $pool = new CachePool($this->wpdb, $poolName, $default, $this->defaultTtl);

        return $pool;
    }
}
