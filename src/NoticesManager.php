<?php declare(strict_types=1);

namespace Frescoref\Woplucore;

use Frescoref\Woplucore\Contracts\Hooksable;
use Frescoref\Woplucore\Contracts\Noncesable;
use Frescoref\Woplucore\Contracts\Viewable;
use Frescoref\Woplucore\Contracts\Noticesable;

/**
 * Class NoticesManager
 *
 * @package Woplucore\Notices
 * @since 1.0.0
 */
class NoticesManager implements Noticesable
{
    /**
     * @var Viewable
     */
    private $views;

    /**
     * @var Noncesable
     */
    private $nonces;

    /**
     * @var Hooksable|null
     */
    private $hooks;

    /**
     * @var string
     */
    private $safePrefix;

    /**
     * @var int
     */
    private $userId;

    /**
     * @var bool
     */
    private $isNetworkContext;

    /**
     * @var string
     */
    private $defaultTemplate = 'notices/list';

    /**
     * @var int
     */
    private $defaultTtl = 60;

    /**
     * @var string|null
     */
    private $currentContext;

    /**
     * @var array<string, array{id: string, message: string, type: string, args: array, config: array, actions: array, dismiss_data: array, priority: int, context: string}>
     */
    private $persistent = [];

    /**
     * @var array<string, array{id: string, message: string, type: string, args: array, config: array, actions: array, dismiss_data: array, priority: int, context: string}>
     */
    private $flashQueue = [];

    /**
     * @var array<callable>
     */
    private $interceptors = [];

    /**
     * @var array<string, array{class: string, icon: string, color: string, dismissible: bool, template: string|null, aria_role: string}>
     */
    private $types = [
        'success' => ['class' => 'notice-success', 'icon' => '✅', 'color' => '#00a32a', 'dismissible' => true, 'template' => null, 'aria_role' => 'status'],
        'error'   => ['class' => 'notice-error',   'icon' => '❌', 'color' => '#d63638', 'dismissible' => true, 'template' => null, 'aria_role' => 'alert'],
        'warning' => ['class' => 'notice-warning', 'icon' => '⚠️', 'color' => '#dba617', 'dismissible' => true, 'template' => null, 'aria_role' => 'alert'],
        'info'    => ['class' => 'notice-info',    'icon' => 'ℹ️', 'color' => '#2271b1', 'dismissible' => true, 'template' => null, 'aria_role' => 'status'],
    ];

    /**
     * @param Viewable     $views  Views manager.
     * @param Noncesable   $nonces Nonce provider.
     * @param string       $prefix Isolation prefix.
     * @param int          $userId Target user ID.
     */
    public function __construct(Viewable $views, Noncesable $nonces, string $prefix = '', int $userId = 0)
    {
        $this->views            = $views;
        $this->nonces           = $nonces;
        $this->safePrefix       = '' !== $prefix ? \trim($prefix, '-_') : 'notices';
        $this->userId           = $userId > 0 ? $userId : (\function_exists('get_current_user_id') ? \get_current_user_id() : 0);
        $this->isNetworkContext = \function_exists('is_multisite') && \is_multisite() && \function_exists('is_network_admin') && \is_network_admin();
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
    // QUEUING & DEDUPLICATION
    // =========================================================================

    /** {@inheritDoc} */
    public function add(string $message, string $type = 'info', array $args = [], ?string $context = null, int $priority = 10, ?string $requiredCap = null, ?int $expiresAt = null, array $actions = []): self
    {
        $ctx = $context ?? $this->currentContext ?? 'global';
        $notice = $this->buildNotice($message, $type, $args, $ctx, $priority, $requiredCap, $expiresAt, $actions);
        $this->flashQueue[] = $notice;
        $this->saveFlashQueue();
        return $this;
    }

    /** {@inheritDoc} */
    public function persistent(string $id, string $message, string $type = 'info', array $args = [], ?string $context = null, int $priority = 10, ?string $requiredCap = null, ?int $expiresAt = null, array $actions = []): self
    {
        $ctx = $context ?? $this->currentContext ?? 'global';
        $noticeId = $this->safePrefix . '_' . $id . '_' . $ctx;

        // Deduplication: skip if identical message already registered
        if (isset($this->persistent[$noticeId]) && $this->persistent[$noticeId]['message'] === $message) {
            return $this;
        }

        // Deduplication: skip if already dismissed and not expired
        if ($this->isDismissed($id) && (null === $expiresAt || $expiresAt > \time())) {
            return $this;
        }

        $this->persistent[$noticeId] = $this->buildNotice($message, $type, $args, $ctx, $priority, $requiredCap, $expiresAt, $actions);
        return $this;
    }

    // =========================================================================
    // DISMISSAL
    // =========================================================================

    /** {@inheritDoc} */
    public function dismiss(string $id): bool
    {
        if (0 === $this->userId) {
            return false;
        }

        $dismissed = $this->getDismissedMeta();
        $metaId    = $this->safePrefix . '_' . $id;

        if (\in_array($metaId, $dismissed, true)) {
            return true;
        }

        $dismissed[] = $metaId;
        return $this->setDismissedMeta($dismissed);
    }

    /** {@inheritDoc} */
    public function restore(string $id): bool
    {
        if (0 === $this->userId) {
            return false;
        }

        $metaId    = $this->safePrefix . '_' . $id;
        $dismissed = $this->getDismissedMeta();
        $key       = \array_search($metaId, $dismissed, true);

        if (false === $key) {
            return false;
        }

        unset($dismissed[$key]);
        return $this->setDismissedMeta(\array_values($dismissed));
    }

    /** {@inheritDoc} */
    public function isDismissed(string $id): bool
    {
        if (0 === $this->userId) {
            return false;
        }

        return \in_array($this->safePrefix . '_' . $id, $this->getDismissedMeta(), true);
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

        // Apply interceptors first
        foreach ($this->interceptors as $cb) {
            $notices = \call_user_func($cb, $notices);
        }

        // Filter expired
        $now = \time();
        $notices = \array_filter($notices, function (array $n) use ($now): bool {
            return empty($n['args']['expires_at']) || $n['args']['expires_at'] > $now;
        });

        // Filter by capability
        $notices = \array_filter($notices, function (array $n): bool {
            if (empty($n['args']['required_cap'])) {
                return true;
            }
            return \function_exists('current_user_can') && \current_user_can($n['args']['required_cap']);
        });

        // Filter dismissed
        $notices = \array_filter($notices, function (array $n): bool {
            return !$this->isDismissed(\preg_replace('/^' . \preg_quote($this->safePrefix) . '_|_.*$/', '', $n['id']));
        });

        // Sort by priority (lower = higher)
        \usort($notices, static function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority'];
        });

        return $notices;
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
        $this->hooks = $hooks;
        $manager = $this;
        $ctx = $this->currentContext;
        $param = $this->dismissUrlParam();

        // Render hook
        $hooks->addAction($renderHook, static function () use ($manager, $ctx): void {
            $manager->render($ctx);
        }, 10);

        // AJAX dismissal hook
        $action = $this->dismissAjaxTag();
        $hooks->addAction("{$dismissHookTag}_{$action}", static function () use ($manager): void {
            $request = [];
            if (isset($_POST['notice_id'])) { $request['notice_id'] = \sanitize_key((string) $_POST['notice_id']); }
            if (isset($_POST['nonce'])) { $request['nonce'] = \sanitize_text_field((string) $_POST['nonce']); }

            echo \wp_json_encode($manager->handleAjaxDismissal($request));
            exit;
        }, 5);

        $hooks->addAction("{$dismissHookTag}_nopriv_{$action}", static function () use ($manager): void {
            $request = [];
            if (isset($_POST['notice_id'])) { $request['notice_id'] = \sanitize_key((string) $_POST['notice_id']); }
            if (isset($_POST['nonce'])) { $request['nonce'] = \sanitize_text_field((string) $_POST['nonce']); }

            echo \wp_json_encode($manager->handleAjaxDismissal($request));
            exit;
        }, 5);

        // URL fallback hook (legacy/non-JS)
        $hooks->addAction('admin_init', static function () use ($manager, $param): void {
            $request = [];
            if (isset($_GET[$param])) { $request['notice_id'] = \sanitize_key((string) $_GET[$param]); }
            if (isset($_GET['_wpnonce'])) { $request['nonce'] = \sanitize_text_field((string) $_GET['_wpnonce']); }

            $redirect = $manager->handleUrlDismissal($request);
            if (null !== $redirect) {
                \wp_safe_redirect($redirect);
                exit;
            }
        }, 5);
    }

    // =========================================================================
    // DISMISSAL HANDLERS
    // =========================================================================

    /** {@inheritDoc} */
    public function handleUrlDismissal(array $request): ?string
    {
        if (empty($request['notice_id']) || empty($request['nonce'])) {
            return null;
        }

        if (!$this->nonces->verify($request['nonce'], $this->nonceAction($request['notice_id']))) {
            throw new \InvalidArgumentException('Invalid dismissal nonce.');
        }

        $this->dismiss($request['notice_id']);
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

        $this->dismiss($request['notice_id']);
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

    private function buildNotice(string $message, string $type, array $args, string $context, int $priority, ?string $requiredCap, ?int $expiresAt, array $actions): array
    {
        $typeConfig = $this->resolveType($type);
        $noticeId = \bin2hex(\random_bytes(6));
        $prefixedId = $this->safePrefix . '_' . $noticeId;

        $processedActions = $this->resolveActions($prefixedId, $actions);
        $dismissData = [
            'url'      => $this->buildDismissUrl($prefixedId),
            'ajax_url' => \function_exists('admin_url') ? \admin_url('admin-ajax.php') : '',
            'action'   => $this->dismissAjaxTag(),
            'nonce'    => $this->nonces->create($this->nonceAction($prefixedId)),
        ];

        $finalArgs = \array_merge([
            'required_cap' => $requiredCap,
            'expires_at'   => $expiresAt,
            'dismissible'  => true,
            'class'        => '',
        ], $args);

        return [
            'id'          => $prefixedId,
            'message'     => $message,
            'type'        => $type,
            'args'        => $finalArgs,
            'config'      => \array_merge($typeConfig, ['class' => \trim("{$typeConfig['class']} " . ($finalArgs['class'] ?? ''))]),
            'actions'     => $processedActions,
            'dismiss_data'=> $dismissData,
            'priority'    => $priority,
            'context'     => $context,
        ];
    }

    private function resolveActions(string $noticeId, array $actions): array
    {
        return \array_map(function (array $act) use ($noticeId): array {
            $url = $act['url'] ?? '';
            if ('' !== $url && \function_exists('add_query_arg')) {
                $url = \add_query_arg([
                    $this->dismissUrlParam() => $noticeId,
                    '_wpnonce'               => $this->nonces->create($this->nonceAction($noticeId)),
                ], $url);
            }
            return [
                'label'   => $act['label'] ?? '',
                'url'     => \esc_url($url),
                'action'  => $act['action'] ?? '',
                'primary' => !empty($act['primary']),
            ];
        }, $actions);
    }

    private function resolveType(string $type): array
    {
        return $this->types[$type] ?? $this->types['info'];
    }

    private function buildDismissUrl(string $id): string
    {
        if (!\function_exists('add_query_arg')) {
            return '';
        }
        return \esc_url(\add_query_arg([
            $this->dismissUrlParam() => $id,
            '_wpnonce'               => $this->nonces->create($this->nonceAction($id)),
        ]));
    }

    private function nonceAction(string $id): string
    {
        return $this->safePrefix . '_dismiss_' . $id;
    }

    private function dismissAjaxTag(): string
    {
        return $this->safePrefix . '_dismiss_notice';
    }

    private function dismissUrlParam(): string
    {
        return $this->safePrefix . '_dismiss_notice';
    }

    private function getFlashQueue(): array
    {
        if (null !== $this->flashQueue) {
            return $this->flashQueue;
        }

        if (0 === $this->userId || !\function_exists('get_transient')) {
            return [];
        }

        $data = \get_transient($this->transientKey());
        return \is_array($data) ? $data : [];
    }

    private function saveFlashQueue(): void
    {
        if (0 === $this->userId || !\function_exists('set_transient')) {
            return;
        }

        \set_transient($this->transientKey(), $this->flashQueue, $this->defaultTtl);
    }

    private function getDismissedMeta(): array
    {
        if (0 === $this->userId) {
            return [];
        }

        if ($this->isNetworkContext) {
            return $this->getNetworkStorage();
        }
        return $this->getUserStorage();
    }

    private function setDismissedMeta(array $data): bool
    {
        if (0 === $this->userId) {
            return false;
        }

        if ($this->isNetworkContext) {
            return \update_site_option($this->metaKey(), $data);
        }
        return \update_user_meta($this->userId, $this->metaKey(), $data);
    }

    private function getUserStorage(): array
    {
        if (!\function_exists('get_user_meta')) {
            return [];
        }
        $data = \get_user_meta($this->userId, $this->metaKey(), true);
        return \is_array($data) ? $data : [];
    }

    private function getNetworkStorage(): array
    {
        if (!\function_exists('get_site_option')) {
            return [];
        }
        $data = \get_site_option($this->metaKey(), []);
        return \is_array($data) ? $data : [];
    }

    private function transientKey(): string
    {
        return $this->safePrefix . '_flash_' . $this->userId;
    }

    private function metaKey(): string
    {
        return $this->safePrefix . '_dismissed';
    }
}