<?php

declare(strict_types=1);

namespace Freedom\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the three screens share: the redirect after a form, the notice it
 * lands on, the permission-and-nonce check, and the small forms that
 * carry one button each.
 *
 * Fellowship's shape throughout — a handler is a one-line redirect around
 * a public `…FromRequest(): string` that returns a result code, so a test
 * can drive the decision without following wp_safe_redirect() and exit.
 */
trait AdminSupport
{
    /**
     * Result codes and the notice each lands on. A code not listed here
     * shows nothing rather than an empty box.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private static array $notices = [
        'app_added'        => ['success', 'Application added. Set its values and common accounts below.'],
        'app_saved'        => ['success', 'Application saved.'],
        'app_deleted'      => ['success', 'Application deleted, with its values, accounts and tablets.'],
        'bad_slug'         => ['error', 'A slug is lower-case letters, digits and hyphens, and starts with a letter or digit.'],
        'slug_taken'       => ['error', 'Another application already has that slug.'],
        'bad_callback'     => ['error', 'The callback must be a custom scheme such as org.example.app.freedom://auth — not http, https or link, and with no port, query or fragment.'],
        'bad_name'         => ['error', 'Give the application a name.'],
        'account_added'    => ['success', 'Common account added. Tablets signed in with it are admitted from now on.'],
        'account_removed'  => ['success', 'Common account removed. Its tablets are refused from their next request.'],
        'account_exists'   => ['error', 'That address is already listed.'],
        'bad_address'      => ['error', 'That is not a usable email address.'],
        'saved'            => ['success', 'Value saved. Tablets pick it up the next time they start.'],
        'unchanged'        => ['info', 'Nothing changed, so no tablet will fetch it again.'],
        'deleted'          => ['success', 'Value deleted. Tablets remove it the next time they start.'],
        'not_found'        => ['error', 'That value no longer exists.'],
        'bad_key'          => ['error', 'A key is lower-case letters, digits, dots, underscores and hyphens, up to 100 characters.'],
        'too_large'        => ['error', 'That value is too large. Configuration values are limited to 16 KB.'],
        'failed'           => ['error', 'That could not be saved. See the Freedom log.'],
        'revoked'          => ['success', 'Tablet revoked. It can sign in again; block it to stop that.'],
        'blocked'          => ['success', 'Tablet blocked. It cannot sign in again until unblocked.'],
        'unblocked'        => ['success', 'Tablet unblocked. It must sign in again to receive anything.'],
        'removed'          => ['success', 'Tablet removed, with its overrides.'],
        'no_change'        => ['info', 'Nothing to do.'],
    ];

    private function notice(): void
    {
        $code = isset($_GET['freedom_result']) ? sanitize_key((string) wp_unslash($_GET['freedom_result'])) : '';
        if (!isset(self::$notices[$code])) {
            return;
        }

        [$type, $message] = self::$notices[$code];

        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * Die unless the user holds $capability and the nonce for $action
     * verifies.
     */
    private function authorise(string $capability, string $action): void
    {
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You are not allowed to do that.', 'freedom'), '', ['response' => 403]);
        }

        check_admin_referer($action);
    }

    private function postedInt(string $name): int
    {
        // Validated, not cast: "12abc" is refused rather than becoming 12,
        // because several of these ids are folded into the nonce action.
        return (int) filter_var($_POST[$name] ?? null, FILTER_VALIDATE_INT);
    }

    private function postedText(string $name): string
    {
        $raw = isset($_POST[$name]) ? wp_unslash($_POST[$name]) : '';

        return is_string($raw) ? trim(sanitize_text_field($raw)) : '';
    }

    /**
     * A value exactly as typed. Deliberately not sanitised as text: a
     * password or an API key is an arbitrary string, and a sanitiser would
     * quietly turn it into one that no longer works. It is escaped
     * wherever it is echoed, and never echoed at all when it is secret.
     */
    private function postedRaw(string $name): string
    {
        $raw = isset($_POST[$name]) ? wp_unslash($_POST[$name]) : '';

        return is_string($raw) ? $raw : '';
    }

    private function postedFlag(string $name): bool
    {
        return isset($_POST[$name]) && (string) wp_unslash($_POST[$name]) !== '' && (string) wp_unslash($_POST[$name]) !== '0';
    }

    /** @param array<string, string|int> $args */
    private function redirectTo(string $result, array $args = []): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => ApplicationsPage::PAGE_SLUG] + $args + ['freedom_result' => $result],
            admin_url('admin.php'),
        ));
        exit;
    }

    /** @param array<string, string|int> $args */
    private function url(array $args = []): string
    {
        return add_query_arg(['page' => ApplicationsPage::PAGE_SLUG] + $args, admin_url('admin.php'));
    }

    /**
     * A form carrying one button, posted to admin-post.php.
     *
     * @param array<string, string|int> $fields
     */
    private function button(string $action, string $nonce, array $fields, string $label, string $confirm = ''): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';

        foreach ($fields as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        }

        wp_nonce_field($nonce, '_wpnonce', true, true);

        echo '<button type="submit" class="button button-small"';
        if ($confirm !== '') {
            echo ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm) ?: '""') . ')"';
        }
        echo '>' . esc_html($label) . '</button></form>';
    }

    private function when(?int $epoch): string
    {
        if ($epoch === null || $epoch <= 0) {
            return '—';
        }

        $formatted = wp_date('j M Y H:i', $epoch);

        return is_string($formatted) ? $formatted : '—';
    }
}
