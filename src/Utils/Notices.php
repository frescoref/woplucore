<?php declare(strict_types=1);

namespace Frescoref\Woplucore\Utils;

use Frescoref\Woplucore\Contracts\Hooksable;
use Frescoref\Woplucore\Contracts\Noncesable;
use Frescoref\Woplucore\Contracts\Viewable;
use Frescoref\Woplucore\NoticesManager;

/**
 * Class Notices
 *
 * @package Frescoref\Woplucore\Utils
 * @since 1.0.0
 */
class Notices
{
    /**
     * Resolved manager instance.
     *
     * @var NoticesManager|null
     */
    private static $instance = null;

    /**
     * Configure the notices manager singleton.
     *
     * @param Viewable   $views  Views manager.
     * @param Noncesable $nonces Nonce provider.
     * @param string     $prefix Isolation prefix.
     * @param int        $userId Target user ID.
     * @return void
     */
    public static function configure(Viewable $views, Noncesable $nonces, string $prefix = '', int $userId = 0): void
    {
        self::$instance = new NoticesManager($views, $nonces, $prefix, $userId);
    }

    /**
     * Resolve underlying manager instance.
     *
     * @return NoticesManager
     * @throws \RuntimeException If not configured.
     */
    private static function instance(): NoticesManager
    {
        if (null === self::$instance) {
            throw new \RuntimeException(
                'Notices facade is not configured. Call Notices::configure($views, $nonces, $prefix) first.'
            );
        }
        return self::$instance;
    }

    /** {@inheritDoc NoticesManager::registerType()} */
    public static function registerType(string $type, array $config = []): void
    {
        self::instance()->registerType($type, $config);
    }

    /** {@inheritDoc NoticesManager::setDefaultTemplate()} */
    public static function setDefaultTemplate(string $template): void
    {
        self::instance()->setDefaultTemplate($template);
    }

    /** {@inheritDoc NoticesManager::setDefaultTtl()} */
    public static function setDefaultTtl(int $seconds): void
    {
        self::instance()->setDefaultTtl($seconds);
    }

    /** {@inheritDoc NoticesManager::setContext()} */
    public static function setContext(string $context): void
    {
        self::instance()->setContext($context);
    }

    /** {@inheritDoc NoticesManager::filter()} */
    public static function filter(callable $interceptor): void
    {
        self::instance()->filter($interceptor);
    }

    /** {@inheritDoc NoticesManager::add()} */
    public static function add(
        string $message,
        string $type = 'info',
        array $args = [],
        ?string $context = null,
        int $priority = 10,
        ?string $requiredCap = null,
        ?int $expiresAt = null,
        array $actions = []
    ): void {
        self::instance()->add($message, $type, $args, $context, $priority, $requiredCap, $expiresAt, $actions);
    }

    /** {@inheritDoc NoticesManager::persistent()} */
    public static function persistent(
        string $id,
        string $message,
        string $type = 'info',
        array $args = [],
        ?string $context = null,
        int $priority = 10,
        ?string $requiredCap = null,
        ?int $expiresAt = null,
        array $actions = []
    ): void {
        self::instance()->persistent($id, $message, $type, $args, $context, $priority, $requiredCap, $expiresAt, $actions);
    }

    /** {@inheritDoc NoticesManager::dismiss()} */
    public static function dismiss(string $id, ?string $context = null): bool
    {
        return self::instance()->dismiss($id, $context);
    }

    /** {@inheritDoc NoticesManager::restore()} */
    public static function restore(string $id, ?string $context = null): bool
    {
        return self::instance()->restore($id, $context);
    }

    /** {@inheritDoc NoticesManager::isDismissed()} */
    public static function isDismissed(string $id, ?string $context = null): bool
    {
        return self::instance()->isDismissed($id, $context);
    }

    /** {@inheritDoc NoticesManager::get()} */
    public static function get(bool $includePersistent = true, ?string $context = null): array
    {
        return self::instance()->get($includePersistent, $context);
    }

    /** {@inheritDoc NoticesManager::consume()} */
    public static function consume(?string $context = null): array
    {
        return self::instance()->consume($context);
    }

    /** {@inheritDoc NoticesManager::render()} */
    public static function render(?string $context = null): void
    {
        self::instance()->render($context);
    }

    /** {@inheritDoc NoticesManager::bind()} */
    public static function bind(Hooksable $hooks, string $renderHook = 'admin_notices', string $dismissHookTag = 'wp_ajax'): void
    {
        self::instance()->bind($hooks, $renderHook, $dismissHookTag);
    }

    /** {@inheritDoc NoticesManager::clear()} */
    public static function clear(?string $context = null): void
    {
        self::instance()->clear($context);
    }

    /** {@inheritDoc NoticesManager::clearPersistent()} */
    public static function clearPersistent(?string $context = null): void
    {
        self::instance()->clearPersistent($context);
    }

    /** {@inheritDoc NoticesManager::has()} */
    public static function has(?string $context = null): bool
    {
        return self::instance()->has($context);
    }

    /** {@inheritDoc NoticesManager::count()} */
    public static function count(?string $context = null): int
    {
        return self::instance()->count($context);
    }

    /** {@inheritDoc NoticesManager::handleUrlDismissal()} */
    public static function handleUrlDismissal(array $request): ?string
    {
        return self::instance()->handleUrlDismissal($request);
    }

    /** {@inheritDoc NoticesManager::handleAjaxDismissal()} */
    public static function handleAjaxDismissal(array $request): array
    {
        return self::instance()->handleAjaxDismissal($request);
    }

    /** {@inheritDoc NoticesManager::flush()} */
    public static function flush(): void
    {
        self::instance()->flush();
    }
}