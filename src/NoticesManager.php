<?php declare(strict_types=1);

namespace Frescoref\Woplucore;

use Frescoref\Woplucore\Contracts\Hooksable;
use Frescoref\Woplucore\Contracts\Noncesable;
use Frescoref\Woplucore\Contracts\Noticesable;
use Frescoref\Woplucore\Contracts\Viewable;
use InvalidArgumentException;

/**
 * Class NoticesManager
 *
 * @package Frescoref\Woplucore
 * @since 1.0.0
 */
class NoticesManager implements Noticesable
{
    /**
     * Views manager for template rendering.
     *
     * @var Viewable
     */
    private $views;

    /**
     * Nonce provider for secure dismissal verification.
     *
     * @var Noncesable
     */
    private $nonces;

    /**
     * Safe prefix for keys, actions, and query parameters.
     *
     * @var string
     */
    private $safePrefix;

    /**
     * Target user ID. Defaults to current logged-in user.
     *
     * @var int
     */
    private $userId;

    /**
     * Default template path for notices without type-specific override.
     *
     * @var string
     */
    private $defaultTemplate = 'notices/list';

    /**
     * Default TTL (seconds) for flash transients.
     *
     * @var int
     */
    private $defaultTtl = 60;

    /**
     * Current execution context.
     *
     * @var string|null
     */
    private $currentContext = null;

    /**
     * Registered persistent notices, keyed by storage key (prefix_id_context).
     *
     * @var array<string, array>
     */
    private $persistent = [];

    /**
     * Current flash queue (also persisted to transient).
     *
     * @var array<int, array>
     */
    private $flashQueue = [];

    /**
     * Pre-render interceptors chain.
     *
     * @var array<int, callable>
     */
    private $interceptors = [];

    /**
     * Registered notice types with default visual and ARIA configuration.
     *
     * @var array<string, array{class: string, icon: string, color: string, dismissible: bool, template: string|null, aria_role: string}>
     */
    private $types = [
        'success' => ['class' => 'notice-success', 'icon' => '✅', 'color' => '#00a32a', 'dismissible' => true, 'template' => null, 'aria_role' => 'status'],
        'error'   => ['class' => 'notice-error',   'icon' => '❌', 'color' => '#d63638', 'dismissible' => true, 'template' => null, 'aria_role' => 'alert'],
        'warning' => ['class' => 'notice-warning', 'icon' => '⚠️', 'color' => '#dba617', 'dismissible' => true, 'template' => null, 'aria_role' => 'alert'],
        'info'    => ['class' => 'notice-info',    'icon' => 'ℹ️', 'color' => '#2271b1', 'dismissible' => true, 'template' => null, 'aria_role' => 'status'],
    ];

    /**
     * Constructor.
     *
     * @param Viewable   $views  Views manager.
     * @param Noncesable $nonces Nonce provider.
     * @param string     $prefix Isolation prefix.
     * @param int        $userId Target user ID (0 for current).
     */
    public function __construct(Viewable $views, Noncesable $nonces, string $prefix = '', int $userId = 0)
    {
        $this->views      = $views;
        $this->nonces     = $nonces;
        $this->safePrefix = '' !== $prefix ? \trim($prefix, '-_') : 'notices';
        $this->userId     = $userId > 0 ? $userId : (\function_exists('get_current_user_id') ? \get_current_user_id() : 0);
    }

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /** {@inheritDoc} */
    public function registerType(string $type, array $config = []): self
    {
        $defaults = [
            'class'       => "notice-{$type}",
            'icon'        => '',
            'color'       => '',
            'dismissible' => true,
            'template'    => null,
            'aria_role'   => 'status',
        ];
        $this->types[$type] = \array_merge($defaults, $config);
        return $this;
    }

    /** {@inheritDoc} */
    public function setDefaultTemplate(string $template): self
    {
        $this->defaultTemplate = \trim($template, '/\\');
        return $this;
    }

    /** {@inheritDoc} */
    public function setDefaultTtl(int $seconds): self
    {
        $this->defaultTtl = \max(1, $seconds);
        return $this;
    }

    /** {@inheritDoc} */
    public function setContext(string $context): self
    {
        $this->currentContext = \sanitize_key($context);
        return $this;
    }

    /** {@inheritDoc} */
    public function filter(callable $interceptor): self
    {
        $this->interceptors[] = $interceptor;
        return $this;
    }

    // =========================================================================
    // QUEUING
    // =========================================================================

    /** {@inheritDoc} */
    public function add(
        string $message,
        string $type = 'info',
        array $args = [],
        ?string $context = null,
        int $priority = 10,
        ?string $requiredCap = null,
        ?int $expiresAt = null,
        array $actions = []
    ): self {
        $ctx = $context ?? $this->currentContext ?? 'global';
        $this->flashQueue[] = $this->buildNotice(
            $message, $type, $args, $ctx, $priority, $requiredCap, $expiresAt, $actions, ''
        );
        $this->saveFlashQueue();
        return $this;
    }

    /** {@inheritDoc} */
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
    ): self {
        $ctx = $context ?? $this->currentContext ?? 'global';
        $storageKey = $this->buildStorageKey($id, $ctx);

        // Deduplication: skip if identical message already registered in current request
        if (isset($this->persistent[$storageKey]) && $this->persistent[$storageKey]['message'] === $message) {
            return $this;
        }

        // Check dismissal state (with context)
        $isDismissed = $this->isDismissed($id, $ctx);
        $isExpired   = null !== $expiresAt && $expiresAt <= \time();

        // Auto-restore if previously dismissed AND now expired
        if ($isDismissed && $isExpired) {
            $this->restore($id, $ctx);
            $isDismissed = false;
        }

        // Skip if still dismissed and not expired
        if ($isDismissed && !$isExpired) {
            return $this;
        }

        $this->persistent[$storageKey] = $this->buildNotice(
            $message, $type, $args, $ctx, $priority, $requiredCap, $expiresAt, $actions, $id
        );
        return $this;
    }

    // =========================================================================
    // DISMISSAL (context-aware)
    // =========================================================================

    /** {@inheritDoc} */
    public function dismiss(string $id, ?string $context = null): bool
    {
        if (0 === $this->userId) {
            return false;
        }

        $ctx = $context ?? $this->currentContext ?? 'global';
        $dismissed = $this->getDismissedMeta();
        $metaId = $this->buildMetaId($id, $ctx);

        if (\in_array($metaId, $dismissed, true)) {
            return true;
        }

        $dismissed[] = $metaId;
        return $this->setDismissedMeta($dismissed);
    }

    /** {@inheritDoc} */
    public function restore(string $id, ?string $context = null): bool
    {
        if (0 === $this->userId) {
            return false;
        }

        $ctx = $context ?? $this->currentContext ?? 'global';
        $metaId = $this->buildMetaId($id, $ctx);
        $dismissed = $this->getDismissedMeta();
        $key = \array_search($metaId, $dismissed, true);

        if (false === $key) {
            return false;
        }

        unset($dismissed[$key]);
        return $this->setDismissedMeta(\array_values($dismissed));
    }

    /** {@inheritDoc} */
    public function isDismissed(string $id, ?string $context = null): bool
    {
        if (0 === $this->userId) {
            return false;
        }

        $ctx = $context ?? $this->currentContext ?? 'global';
        return \in_array($this->buildMetaId($id, $ctx), $this->getDismissedMeta(), true);
    }

    // =========================================================================
    // RETRIEVAL & RENDERING
    // =========================================================================

    /** {@inheritDoc} */
    public function get(bool $includePersistent = true, ?string $context = null): array
    {
        $ctx = $context ?? $this->currentContext ?? 'global';
        $notices = $this->consume($ctx);

        if ($includePersistent) {
            foreach ($this->persistent as $notice) {
                if ($notice['context'] === $ctx) {
                    $notices[] = $notice;
                }
            }
        }

        // Apply interceptors first (user-defined transformations)
        foreach ($this->interceptors as $cb) {
            $notices = \call_user_func($cb, $notices);
        }

        // Filter expired
        $now = \time();
        $notices = \array_filter($notices, static function (array $n) use ($now): bool {
            return empty($n['args']['expires_at']) || $n['args']['expires_at'] > $now;
        });

        // Filter by capability
        $notices = \array_filter($notices, static function (array $n): bool {
            if (empty($n['args']['required_cap'])) {
                return true;
            }
            return \function_exists('current_user_can') && \current_user_can($n['args']['required_cap']);
        });

        // Filter dismissed - use base_id directly (no regex)
        $manager = $this;
        $notices = \array_filter($notices, static function (array $n) use ($manager): bool {
            return !$manager->isDismissed($n['base_id'], $n['context']);
        });

        // Sort by priority (lower = higher)
        \usort($notices, static function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority'];
        });

        return \array_values($notices);
    }

    /** {@inheritDoc} */
    public function consume(?string $context = null): array
    {
        $ctx = $context ?? $this->currentContext ?? 'global';
        $consumed = [];
        $remaining = [];

        foreach ($this->flashQueue as $notice) {
            if ($notice['context'] === $ctx) {
                $consumed[] = $notice;
            } else {
                $remaining[] = $notice;
            }
        }

        $this->flashQueue = $remaining;
        $this->saveFlashQueue();
        return $consumed;
    }

    /** {@inheritDoc} */
    public function render(?string $context = null): void
    {
        $notices = $this->get(true, $context);
        if (empty($notices)) {
            return;
        }

        $grouped = [];
        foreach ($notices as $notice) {
            $tpl = $notice['config']['template'] ?? $this->defaultTemplate;
            $grouped[$tpl][] = $notice;
        }

        foreach ($grouped as $template => $items) {
            $this->views->display($template, ['notices' => $items]);
        }
    }

    /** {@inheritDoc} */
    public function bind(Hooksable $hooks, string $renderHook = 'admin_notices', string $dismissHookTag = 'wp_ajax'): void
    {
        $manager = $this;
        $ctx = $this->currentContext;
        $param = $this->dismissUrlParam();
        $ajaxAction = $this->dismissAjaxTag();

        // Render hook
        $hooks->addAction($renderHook, static function () use ($manager, $ctx): void {
            $manager->render($ctx);
        }, 10);

        // AJAX dismissal hook (logged in)
        $hooks->addAction("{$dismissHookTag}_{$ajaxAction}", static function () use ($manager): void {
            $request = [];
            // Safe extraction isolated from business logic
            if (isset($_POST['notice_id'])) { $request['notice_id'] = \sanitize_key((string) $_POST['notice_id']); }
            if (isset($_POST['nonce'])) { $request['nonce'] = \sanitize_text_field((string) $_POST['nonce']); }
            if (isset($_POST['context'])) { $request['context'] = \sanitize_key((string) $_POST['context']); }

            echo \wp_json_encode($manager->handleAjaxDismissal($request));

            if (\function_exists('wp_die')) {
                \wp_die();
            }
        }, 5);

        // AJAX dismissal hook (nopriv fallback)
        $hooks->addAction("{$dismissHookTag}_nopriv_{$ajaxAction}", static function () use ($manager): void {
            $request = [];
            if (isset($_POST['notice_id'])) { $request['notice_id'] = \sanitize_key((string) $_POST['notice_id']); }
            if (isset($_POST['nonce'])) { $request['nonce'] = \sanitize_text_field((string) $_POST['nonce']); }
            if (isset($_POST['context'])) { $request['context'] = \sanitize_key((string) $_POST['context']); }

            echo \wp_json_encode($manager->handleAjaxDismissal($request));

            if (\function_exists('wp_die')) {
                \wp_die();
            }
        }, 5);

        // URL fallback hook (legacy/non-JS)
        $hooks->addAction('admin_init', static function () use ($manager, $param): void {
            $request = [];
            if (isset($_GET[$param])) { $request['notice_id'] = \sanitize_key((string) $_GET[$param]); }
            if (isset($_GET['_wpnonce'])) { $request['nonce'] = \sanitize_text_field((string) $_GET['_wpnonce']); }
            if (isset($_GET['context'])) { $request['context'] = \sanitize_key((string) $_GET['context']); }

            $redirect = $manager->handleUrlDismissal($request);
            if (null !== $redirect) {
                \wp_safe_redirect($redirect);
                if (\function_exists('wp_die')) {
                    \wp_die();
                }
            }
        }, 5);
    }

    // =========================================================================
    // HANDLERS
    // =========================================================================

    /** {@inheritDoc} */
    public function handleUrlDismissal(array $request): ?string
    {
        if (empty($request['notice_id']) || empty($request['nonce'])) {
            return null;
        }

        if (!$this->nonces->verify($request['nonce'], $this->nonceAction($request['notice_id']))) {
            throw new InvalidArgumentException('Invalid dismissal nonce.');
        }

        $ctx = $request['context'] ?? null;
        $this->dismiss($request['notice_id'], $ctx);

        $referer = isset($_SERVER['HTTP_REFERER']) ? \esc_url_raw((string) $_SERVER['HTTP_REFERER']) : '';
        return '' !== $referer ? $referer : (\function_exists('admin_url') ? \admin_url() : '/');
    }

    /** {@inheritDoc} */
    public function handleAjaxDismissal(array $request): array
    {
        if (empty($request['notice_id']) || empty($request['nonce'])) {
            return ['success' => false, 'message' => 'Missing dismissal data.'];
        }

        if (!$this->nonces->verify($request['nonce'], $this->nonceAction($request['notice_id']))) {
            return ['success' => false, 'message' => 'Nonce verification failed.'];
        }

        $ctx = $request['context'] ?? null;
        $this->dismiss($request['notice_id'], $ctx);
        return ['success' => true];
    }

    // =========================================================================
    // QUEUE MANAGEMENT
    // =========================================================================

    /** {@inheritDoc} */
    public function clear(?string $context = null): void
    {
        $ctx = $context ?? $this->currentContext ?? 'global';
        $this->flashQueue = \array_filter($this->flashQueue, static function (array $n) use ($ctx): bool {
            return $n['context'] !== $ctx;
        });
        $this->saveFlashQueue();
    }

    /** {@inheritDoc} */
    public function clearPersistent(?string $context = null): void
    {
        $ctx = $context ?? $this->currentContext ?? 'global';
        $this->persistent = \array_filter($this->persistent, static function (array $n) use ($ctx): bool {
            return $n['context'] !== $ctx;
        });
    }

    /** {@inheritDoc} */
    public function has(?string $context = null): bool
    {
        return $this->count($context) > 0;
    }

    /** {@inheritDoc} */
    public function count(?string $context = null): int
    {
        return \count($this->get(true, $context));
    }

    /** {@inheritDoc} */
    public function flush(): void
    {
        $this->flashQueue = [];
        $this->saveFlashQueue();
        $this->persistent = [];
        $this->interceptors = [];
        $this->currentContext = null;
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Build normalized notice array.
     *
     * @param string      $message     Notice message.
     * @param string      $type        Notice type.
     * @param array       $args        Overrides.
     * @param string      $context     Context.
     * @param int         $priority    Priority.
     * @param string|null $requiredCap Required capability.
     * @param int|null    $expiresAt   Expiration timestamp.
     * @param array       $actions     Action buttons.
     * @param string      $baseId      Base ID (empty for flash).
     * @return array
     */
    private function buildNotice(
        string $message,
        string $type,
        array $args,
        string $context,
        int $priority,
        ?string $requiredCap,
        ?int $expiresAt,
        array $actions,
        string $baseId
    ): array {
        $typeConfig = $this->resolveType($type);

        // Use provided base_id for persistent, generate random for flash
        $id = '' !== $baseId ? $baseId : \bin2hex(\random_bytes(6));
        $prefixedId = $this->safePrefix . '_' . $id;

        $processedActions = $this->resolveActions($actions);

        $dismissData = [
            'url'      => $this->buildDismissUrl($prefixedId, $context),
            'ajax_url' => \function_exists('admin_url') ? \admin_url('admin-ajax.php') : '',
            'action'   => $this->dismissAjaxTag(),
            'nonce'    => $this->nonces->create($this->nonceAction($prefixedId)),
            'context'  => $context,
        ];

        $finalArgs = \array_merge([
            'required_cap' => $requiredCap,
            'expires_at'   => $expiresAt,
            'dismissible'  => true,
            'class'        => '',
        ], $args);

        return [
            'id'           => $prefixedId,
            'base_id'      => $id,
            'message'      => $message,
            'type'         => $type,
            'args'         => $finalArgs,
            'config'       => \array_merge($typeConfig, ['class' => \trim("{$typeConfig['class']} " . ($finalArgs['class'] ?? ''))]),
            'actions'      => $processedActions,
            'dismiss_data' => $dismissData,
            'priority'     => $priority,
            'context'      => $context,
        ];
    }

    /**
     * Resolve actions. Actions are INDEPENDENT of dismiss - no auto-injection.
     *
     * @param array $actions Actions array.
     * @return array
     */
    private function resolveActions(array $actions): array
    {
        return \array_map(static function (array $act): array {
            return [
                'label'   => $act['label'] ?? '',
                'url'     => \esc_url($act['url'] ?? ''),
                'action'  => $act['action'] ?? '',
                'primary' => !empty($act['primary']),
            ];
        }, $actions);
    }

    /**
     * Resolve type configuration.
     *
     * @param string $type Type identifier.
     * @return array
     */
    private function resolveType(string $type): array
    {
        return $this->types[$type] ?? $this->types['info'];
    }

    /**
     * Build dismiss URL.
     *
     * @param string $id      Prefixed notice ID.
     * @param string $context Context.
     * @return string
     */
    private function buildDismissUrl(string $id, string $context): string
    {
        if (!\function_exists('add_query_arg')) {
            return '';
        }
        return \esc_url(\add_query_arg([
            $this->dismissUrlParam() => $id,
            '_wpnonce'               => $this->nonces->create($this->nonceAction($id)),
            'context'                => $context,
        ]));
    }

    /**
     * Build meta ID (context-aware).
     *
     * @param string $id      Base notice ID.
     * @param string $context Context.
     * @return string
     */
    private function buildMetaId(string $id, string $context): string
    {
        return $this->safePrefix . '_' . $id . '_' . $context;
    }

    /**
     * Build storage key for persistent notices.
     *
     * @param string $id      Base notice ID.
     * @param string $context Context.
     * @return string
     */
    private function buildStorageKey(string $id, string $context): string
    {
        return $this->safePrefix . '_' . $id . '_' . $context;
    }

    /**
     * Generate nonce action.
     *
     * @param string $id Notice ID (prefixed or base).
     * @return string
     */
    private function nonceAction(string $id): string
    {
        return $this->safePrefix . '_dismiss_' . $id;
    }

    /**
     * Get AJAX action tag.
     *
     * @return string
     */
    private function dismissAjaxTag(): string
    {
        return $this->safePrefix . '_dismiss_notice';
    }

    /**
     * Get URL parameter name.
     *
     * @return string
     */
    private function dismissUrlParam(): string
    {
        return $this->safePrefix . '_dismiss_notice';
    }

    /**
     * Save flash queue to transient.
     *
     * @return void
     */
    private function saveFlashQueue(): void
    {
        if (0 === $this->userId || !\function_exists('set_transient')) {
            return;
        }

        \set_transient($this->transientKey(), $this->flashQueue, $this->defaultTtl);
    }

    /**
     * Get dismissed meta (context-aware).
     *
     * @return array
     */
    private function getDismissedMeta(): array
    {
        if (0 === $this->userId) {
            return [];
        }

        return $this->isNetworkContext() ? $this->getNetworkStorage() : $this->getUserStorage();
    }

    /**
     * Set dismissed meta (context-aware).
     *
     * @param array $data Data to save.
     * @return bool
     */
    private function setDismissedMeta(array $data): bool
    {
        if (0 === $this->userId) {
            return false;
        }

        if ($this->isNetworkContext()) {
            return \update_site_option($this->metaKey(), $data);
        }
        return \update_user_meta($this->userId, $this->metaKey(), $data);
    }

    /**
     * Lazy multisite/network detection (runtime, not constructor).
     *
     * @return bool
     */
    private function isNetworkContext(): bool
    {
        return \function_exists('is_multisite') && \is_multisite()
            && \function_exists('is_network_admin') && \is_network_admin();
    }

    /**
     * Get user storage.
     *
     * @return array
     */
    private function getUserStorage(): array
    {
        if (!\function_exists('get_user_meta')) {
            return [];
        }
        $data = \get_user_meta($this->userId, $this->metaKey(), true);
        return \is_array($data) ? $data : [];
    }

    /**
     * Get network storage.
     *
     * @return array
     */
    private function getNetworkStorage(): array
    {
        if (!\function_exists('get_site_option')) {
            return [];
        }
        $data = \get_site_option($this->metaKey(), []);
        return \is_array($data) ? $data : [];
    }

    /**
     * Get transient key.
     *
     * @return string
     */
    private function transientKey(): string
    {
        return $this->safePrefix . '_flash_' . $this->userId;
    }

    /**
     * Get meta key.
     *
     * @return string
     */
    private function metaKey(): string
    {
        return $this->safePrefix . '_dismissed';
    }
}