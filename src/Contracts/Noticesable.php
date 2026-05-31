<?php declare(strict_types=1);

namespace Frescoref\Woplucore\Contracts;

/**
 * Interface Noticesable
 *
 * Production-grade notification manager with context isolation, capability scoping,
 * time-expiring notices, actionable buttons, pre-render interceptors,
 * and multisite/network-aware persistence.
 * Direct hook registration or superglobal access in business logic are STRICTLY FORBIDDEN.
 *
 * @package Frescoref\Woplucore\Contracts
 * @since 1.0.0
 */
interface Noticesable
{
    /**
     * Register or override a notice type with visual, template, and ARIA configuration.
     *
     * @param string $type   Unique type identifier.
     * @param array  $config Configuration: ['class' => string, 'icon' => string, 'color' => string, 'dismissible' => bool, 'template' => string|null, 'aria_role' => string].
     * @return self
     */
    public function registerType(string $type, array $config = []): self;

    /**
     * Set default template for notices without type-specific override.
     *
     * @param string $template Template path without extension.
     * @return self
     */
    public function setDefaultTemplate(string $template): self;

    /**
     * Set default TTL (seconds) for flash notice transients.
     *
     * @param int $seconds TTL value (minimum 1).
     * @return self
     */
    public function setDefaultTtl(int $seconds): self;

    /**
     * Set current execution context for filtering notices.
     *
     * @param string $context Context slug (e.g., 'products_edit', 'settings_general').
     * @return self
     */
    public function setContext(string $context): self;

    /**
     * Register a pre-render interceptor to modify, filter, or aggregate notices.
     * Applied before system visibility filters (context, expiry, capability, dismissal).
     *
     * @param callable $interceptor Function(array $notices): array
     * @return self
     */
    public function filter(callable $interceptor): self;

    /**
     * Queue a one-time flash notice.
     * Automatically cleared after next render or page load.
     *
     * @param string      $message     Notice message (HTML allowed, sanitized via wp_kses_post).
     * @param string      $type        Notice type (registered or default).
     * @param array       $args        Overrides: ['class' => string, 'dismissible' => bool].
     * @param string|null $context     Optional context for scoping (null uses current).
     * @param int         $priority    Execution priority (lower = higher).
     * @param string|null $requiredCap Optional required capability to view.
     * @param int|null    $expiresAt   Optional expiration timestamp (null = never).
     * @param array       $actions     Optional action buttons [['label' => '...', 'url' => '...', 'primary' => bool]].
     * @return self
     */
    public function add(
        string $message,
        string $type = 'info',
        array $args = [],
        ?string $context = null,
        int $priority = 10,
        ?string $requiredCap = null,
        ?int $expiresAt = null,
        array $actions = []
    ): self;

    /**
     * Register a persistent notice (deduplicates per ID + message + context).
     * Auto-restores if previously dismissed and now expired.
     *
     * @param string      $id          Unique identifier (per context).
     * @param string      $message     Notice message.
     * @param string      $type        Notice type.
     * @param array       $args        Overrides.
     * @param string|null $context     Optional context for scoping.
     * @param int         $priority    Execution priority.
     * @param string|null $requiredCap Optional required capability.
     * @param int|null    $expiresAt   Optional expiration timestamp.
     * @param array       $actions     Optional action buttons.
     * @return self
     */
    public function persistent(
        string $id,
        string $message,
        string $type = 'info',
        array $args = [],
        ?string $context = null,
        int $priority = 10,
        ?string $requiredCap = null,
        ?int $expiresAt = null,
        array $actions = []
    ): self;

    /**
     * Mark a persistent notice as dismissed for current user/site (context-aware).
     *
     * @param string      $id      Notice identifier.
     * @param string|null $context Optional context (null uses current).
     * @return bool True if dismissed, false on failure.
     */
    public function dismiss(string $id, ?string $context = null): bool;

    /**
     * Restore a previously dismissed notice for current user/site (context-aware).
     *
     * @param string      $id      Notice identifier.
     * @param string|null $context Optional context (null uses current).
     * @return bool True if restored, false if was not dismissed.
     */
    public function restore(string $id, ?string $context = null): bool;

    /**
     * Check if a persistent notice is dismissed (context-aware).
     *
     * @param string      $id      Notice identifier.
     * @param string|null $context Optional context (null uses current).
     * @return bool
     */
    public function isDismissed(string $id, ?string $context = null): bool;

    /**
     * Get merged notices (flash + persistent), filtered by context, expiry, capability, dismissal,
     * and sorted by priority.
     *
     * @param bool        $includePersistent Include persistent notices.
     * @param string|null $context           Context filter (null uses current).
     * @return array<int, array{id: string, base_id: string, message: string, type: string, args: array, config: array, actions: array, dismiss_data: array, priority: int, context: string}>
     */
    public function get(bool $includePersistent = true, ?string $context = null): array;

    /**
     * Consume flash notices for current context, clearing the queue.
     *
     * @param string|null $context Context filter.
     * @return array
     */
    public function consume(?string $context = null): array;

    /**
     * Render notices grouped by resolved template.
     *
     * @param string|null $context Context filter.
     * @return void
     */
    public function render(?string $context = null): void;

    /**
     * Bind rendering, AJAX dismissal, and URL fallback to lifecycle via Hooksable.
     *
     * @param Hooksable $hooks          Hook orchestrator.
     * @param string    $renderHook     Render hook tag (default: admin_notices).
     * @param string    $dismissHookTag Base AJAX hook tag (default: wp_ajax).
     * @return void
     */
    public function bind(Hooksable $hooks, string $renderHook = 'admin_notices', string $dismissHookTag = 'wp_ajax'): void;

    /**
     * Clear flash queue for given context.
     *
     * @param string|null $context Context filter.
     * @return void
     */
    public function clear(?string $context = null): void;

    /**
     * Clear persistent registrations for given context.
     *
     * @param string|null $context Context filter.
     * @return void
     */
    public function clearPersistent(?string $context = null): void;

    /**
     * Check if notices exist for given context.
     *
     * @param string|null $context Context filter.
     * @return bool
     */
    public function has(?string $context = null): bool;

    /**
     * Get notice count for given context.
     *
     * @param string|null $context Context filter.
     * @return int
     */
    public function count(?string $context = null): int;

    /**
     * Handle dismissal request (URL fallback mode). Returns redirect URL.
     *
     * @param array<string, mixed> $request Sanitized request data with keys: notice_id, nonce, context.
     * @return string|null Redirect URL or null if no dismissal action.
     * @throws \InvalidArgumentException If nonce verification fails.
     */
    public function handleUrlDismissal(array $request): ?string;

    /**
     * Handle AJAX dismissal request. Returns response array.
     *
     * @param array<string, mixed> $request Sanitized request data with keys: notice_id, nonce, context.
     * @return array{success: bool, message?: string}
     */
    public function handleAjaxDismissal(array $request): array;

    /**
     * Flush all queues and reset state.
     *
     * @return void
     */
    public function flush(): void;
}