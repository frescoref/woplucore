<?php declare(strict_types=1);

namespace Frescoref\Woplucore;

use RuntimeException;
use Frescoref\Woplucore\Contracts\Noncesable;
use Frescoref\Woplucore\Contracts\Viewable;

/**
 * Class ViewsManager
 *
 * @package Woplucore
 * @since 1.0.0
 */
class ViewsManager implements Viewable
{
    /**
     * Default file extension for templates.
     */
    private const EXTENSION = '.php';

    /**
     * Base directory for plugin templates (absolute path).
     * Registered as lowest priority location automatically.
     *
     * @var string
     */
    private $baseDir;

    /**
     * Nonce provider for CSRF protection in forms.
     *
     * @var Noncesable|null
     */
    private $nonces;

    /**
     * Template lookup directories with priorities.
     * Structure: [['dir' => string, 'priority' => int], ...]
     *
     * @var array<int, array{dir: string, priority: int}>
     */
    private $locations = [];

    /**
     * Variables shared across all templates.
     * Values are stored as-is (no lazy evaluation support).
     *
     * @var array<string, mixed>
     */
    private $shared = [];

    /**
     * Constructor.
     *
     * @param string $baseDir Absolute path to plugin templates directory.
     * @param Noncesable|null $nonces  Optional nonce provider from Woplucore.
     */
    public function __construct(string $baseDir, ?Noncesable $nonces = null)
    {
        $this->baseDir = \rtrim($baseDir, '/\\');
        $this->nonces  = $nonces;

        // Register baseDir as lowest priority default location
        $this->locations[] = ['dir' => $this->baseDir, 'priority' => 0];
    }

    // =========================================================================
    // CORE RENDERING
    // =========================================================================

    /** {@inheritDoc} */
    public function render(string $template, array $data = []): string
    {
        $path   = $this->path($template);
        $shared = $this->shared;

        // Static anonymous function guarantees language-level isolation:
        // PHP prevents access to $this inside static closures, ensuring
        // templates cannot leak into ViewsManager's private state.
        // This is a native language construct and provides forward-compatibility with PHP 8.x.
        $renderer = static function () use ($path, $shared, $data): string {
            // Shared variables extracted first (lower priority)
            \extract($shared, EXTR_SKIP);
            // Data extracted last (highest priority, cannot override by shared)
            \extract($data, EXTR_SKIP);

            \ob_start();
            try {
                include $path;
            } catch (\Throwable $e) {
                \ob_end_clean();
                throw $e;
            }
            return (string) \ob_get_clean();
        };

        return $renderer();
    }

    /** {@inheritDoc} */
    public function display(string $template, array $data = []): void
    {
        echo $this->render($template, $data);
    }

    // =========================================================================
    // TEMPLATE MANAGEMENT
    // =========================================================================

    /** {@inheritDoc} */
    public function exists(string $template): bool
    {
        try {
            $this->path($template);
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /** {@inheritDoc} */
    public function path(string $template): string
    {
        $template = \trim($template, '/\\');
        $filename = $template . self::EXTENSION;

        // Sort locations by priority (highest first).
        $sorted = $this->locations;
        \usort($sorted, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'];
        });

        foreach ($sorted as $location) {
            $path = $location['dir'] . \DIRECTORY_SEPARATOR . $filename;
            if (\is_readable($path)) {
                return $path;
            }
        }

        throw new RuntimeException(
            \sprintf('Template "%s" not found in any registered location.', $template)
        );
    }

    /** {@inheritDoc} */
    public function addLocation(string $directory, int $priority = 10): self
    {
        $directory = \rtrim($directory, '/\\');
        if (!\is_dir($directory)) {
            throw new RuntimeException(
                \sprintf('Template location directory does not exist: %s', $directory)
            );
        }

        $this->locations[] = ['dir' => $directory, 'priority' => $priority];
        return $this;
    }

    /** {@inheritDoc} */
    public function share(string $key, $value): self
    {
        // Direct value storage only.
        // No Closure/callable support - developer resolves lazy values explicitly
        // before calling share() if lazy evaluation is needed.
        $this->shared[$key] = $value;
        return $this;
    }

    /** {@inheritDoc} */
    public function nonce(string $action, string $name = '_wpnonce'): string
    {
        if (null !== $this->nonces) {
            $token = $this->nonces->create($action);
        } elseif (\function_exists('wp_create_nonce')) {
            $token = \wp_create_nonce($action);
        } else {
            return '';
        }

        return \sprintf(
            '<input type="hidden" name="%s" value="%s" />',
            \esc_attr($name),
            \esc_attr($token)
        );
    }
}