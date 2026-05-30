<?php declare(strict_types=1);

namespace Frescoref\Woplucore\Contracts;

/**
 * Interface Viewable
 *
 * Secure, WP-native template rendering layer.
 * Agnostic to delivery method (REST, HTMX, page load, email, shortcode).
 * Provides scope isolation, priority-based location lookup,
 * explicit escaping via native WP functions in templates, and optional nonce injection.
 * Direct include/require/extract() in business logic are STRICTLY FORBIDDEN.
 *
 * @package Woplucore\Contracts
 * @since 1.0.0
 */
interface Viewable
{
    /**
     * Render a template and return as string.
     * Use explicit paths (e.g. 'cards/product', 'emails/welcome').
     * Variables passed in $data are available in template scope.
     * Use native WP escaping functions (esc_html, esc_attr, esc_url, wp_kses_post) in templates.
     *
     * @param string $template Template path without extension.
     * @param array $data Variables to inject into template scope.
     *
     * @return string Rendered HTML.
     * @throws \RuntimeException If template not found.
     */
    public function render(string $template, array $data = []): string;

    /**
     * Render a template and output directly (echo).
     *
     * @param string $template Template path.
     * @param array $data Variables.
     *
     * @return void
     * @throws \RuntimeException If template not found.
     */
    public function display(string $template, array $data = []): void;

    /**
     * Check if a template file exists in any registered location.
     *
     * @param string $template Template path.
     *
     * @return bool
     */
    public function exists(string $template): bool;

    /**
     * Resolve absolute path to a template file.
     * Follows priority order of registered locations (highest priority first).
     *
     * @param string $template Template path.
     *
     * @return string Absolute path.
     * @throws \RuntimeException If not found in any location.
     */
    public function path(string $template): string;

    /**
     * Register a custom template lookup directory.
     * Higher priority = checked first.
     *
     * @param string $directory Absolute path to templates directory.
     * @param int $priority  Lookup priority (default: 10).
     *
     * @return self
     * @throws \RuntimeException If directory does not exist.
     */
    public function addLocation(string $directory, int $priority = 10): self;

    /**
     * Share a variable across all subsequent renders.
     * Useful for current_user, site_url, plugin services, etc.
     * Values are stored as-is and extracted into template scope on each render.
     *
     * @param string $key Variable name.
     * @param mixed $value Value (resolved value, not callable).
     *
     * @return self
     */
    public function share(string $key, $value): self;

    /**
     * Generate a WP nonce hidden input field for forms.
     * Uses injected Noncesable provider if available, falls back to native wp_create_nonce.
     *
     * @param string $action Nonce action identifier.
     * @param string $name   Input name (default: '_wpnonce').
     *
     * @return string HTML <input type="hidden"> tag with escaped attributes.
     */
    public function nonce(string $action, string $name = '_wpnonce'): string;
}