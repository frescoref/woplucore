<?php declare(strict_types=1);

namespace Frescoref\Woplucore\Utils;

use Frescoref\Woplucore\Contracts\Noncesable;
use Frescoref\Woplucore\ViewsManager;

/**
 * Class Views
 *
 * @package Woplucore\Utils
 * @since 1.0.0
 */
class Views
{
    /**
     * Resolved manager instance.
     *
     * @var ViewsManager|null
     */
    private static $instance = null;

    /**
     * Configure the views manager singleton.
     *
     * @param string          $baseDir Absolute path to templates directory.
     * @param Noncesable|null $nonces  Optional nonce provider from Woplucore.
     * @return void
     */
    public static function configure(string $baseDir, ?Noncesable $nonces = null): void
    {
        self::$instance = new ViewsManager($baseDir, $nonces);
    }

    /**
     * Resolve underlying manager instance.
     *
     * @return ViewsManager
     * @throws \RuntimeException If not configured.
     */
    private static function instance(): ViewsManager
    {
        if (null === self::$instance) {
            throw new \RuntimeException(
                'Views facade is not configured. Call Views::configure($baseDir) first.'
            );
        }
        return self::$instance;
    }

    /** {@inheritDoc ViewsManager::render()} */
    public static function render(string $template, array $data = []): string
    {
        return self::instance()->render($template, $data);
    }

    /** {@inheritDoc ViewsManager::display()} */
    public static function display(string $template, array $data = []): void
    {
        self::instance()->display($template, $data);
    }

    /** {@inheritDoc ViewsManager::exists()} */
    public static function exists(string $template): bool
    {
        return self::instance()->exists($template);
    }

    /** {@inheritDoc ViewsManager::path()} */
    public static function path(string $template): string
    {
        return self::instance()->path($template);
    }

    /** {@inheritDoc ViewsManager::addLocation()} */
    public static function addLocation(string $directory, int $priority = 10): void
    {
        self::instance()->addLocation($directory, $priority);
    }

    /** {@inheritDoc ViewsManager::share()} */
    public static function share(string $key, $value): void
    {
        self::instance()->share($key, $value);
    }

    /** {@inheritDoc ViewsManager::nonce()} */
    public static function nonce(string $action, string $name = '_wpnonce'): string
    {
        return self::instance()->nonce($action, $name);
    }
}