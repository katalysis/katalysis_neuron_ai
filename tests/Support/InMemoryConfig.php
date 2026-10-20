<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Config\LoaderInterface;
use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Config\SaverInterface;
use Illuminate\Support\Arr;

/**
 * A Concrete config repository that lives in memory, so a test can save and read
 * settings (such as a dummy API key) without touching the dev site's real config.
 */
final class InMemoryConfig implements LoaderInterface, SaverInterface
{
    public static function repository(): Repository
    {
        return new Repository(new self(), new self(), 'test');
    }

    public function clearNamespace($namespace): void
    {
    }

    public function load($environment, $group, $namespace = null)
    {
        return self::$store[$group] ?? [];
    }

    public function exists($group, $namespace = null)
    {
        return isset(self::$store[$group]);
    }

    public function addNamespace($namespace, $hint): void
    {
    }

    public function getNamespaces()
    {
        return [];
    }

    public function cascadePackage($environment, $package, $group, $items)
    {
        return $items;
    }

    public function save($item, $value, $environment, $group, $namespace = null)
    {
        $items = self::$store[$group] ?? [];
        Arr::set($items, $item, $value);
        self::$store[$group] = $items;

        return true;
    }

    /** @var array<string, array<string, mixed>> shared by the loader and the saver of one test */
    private static array $store = [];

    public static function reset(): void
    {
        self::$store = [];
    }
}
