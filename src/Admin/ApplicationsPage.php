<?php

declare(strict_types=1);

namespace Freedom\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Freedom\Applications\Application;
use Freedom\Applications\ApplicationRepository;
use Freedom\Applications\CallbackUri;
use Freedom\Core\Capabilities;
use Freedom\Tablets\TabletRepository;
use RuntimeException;

/**
 * Freedom → Applications, and the one menu page every Freedom screen
 * lives under.
 *
 * One page routed by query argument rather than three registered pages:
 * `&app=<id>` is an application's own screen ({@see ApplicationPage}),
 * `&tablet=<id>` a tablet's overrides ({@see TabletPage}), and neither is
 * a list anybody should reach from the menu on its own.
 */
final class ApplicationsPage
{
    use AdminSupport;

    public const PAGE_SLUG = 'freedom';
    public const ADD_ACTION = 'freedom_add_application';

    private const NONCE = 'freedom_add_application';

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly TabletRepository $tablets,
        private readonly ApplicationPage $applicationPage,
        private readonly TabletPage $tabletPage,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::ADD_ACTION, [$this, 'handleAdd']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Freedom', 'freedom'),
            __('Freedom', 'freedom'),
            Capabilities::MANAGE_APPLICATIONS,
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-admin-network',
            58,
        );
    }

    public function render(): void
    {
        if (!current_user_can(Capabilities::MANAGE_APPLICATIONS)) {
            return;
        }

        $tabletId = (int) filter_var($_GET['tablet'] ?? null, FILTER_VALIDATE_INT);
        if ($tabletId > 0) {
            $this->tabletPage->render($tabletId);

            return;
        }

        $applicationId = (int) filter_var($_GET['app'] ?? null, FILTER_VALIDATE_INT);
        if ($applicationId > 0) {
            $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : '';
            $this->applicationPage->render($applicationId, $tab);

            return;
        }

        $this->renderList();
    }

    public function handleAdd(): void
    {
        $result = $this->addFromRequest();

        // A Symfony rule matching ->redirect…() with a non-literal argument.
        // This is not Symfony's redirect: it builds wp_safe_redirect() over
        // admin_url('admin.php') and the argument only becomes query
        // parameter values. See Fellowship's DevicesPage.
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        $this->redirectTo($result['code'], $result['app'] > 0 ? ['app' => $result['app']] : []);
    }

    /**
     * @return array{code: string, app: int} The result code, and the new application's id when one was made.
     */
    public function addFromRequest(): array
    {
        $this->authorise(Capabilities::MANAGE_APPLICATIONS, self::NONCE);

        $slug = strtolower($this->postedText('slug'));
        $name = $this->postedText('name');
        $callback = trim($this->postedRaw('callback_uri'));

        if (!Application::isValidSlug($slug)) {
            return ['code' => 'bad_slug', 'app' => 0];
        }

        if ($name === '') {
            return ['code' => 'bad_name', 'app' => 0];
        }

        if ($callback !== '' && !CallbackUri::isAcceptable($callback)) {
            return ['code' => 'bad_callback', 'app' => 0];
        }

        if ($this->applications->findBySlug($slug) !== null) {
            return ['code' => 'slug_taken', 'app' => 0];
        }

        try {
            $application = $this->applications->create(
                $slug,
                $name,
                $callback,
                $this->postedFlag('allow_loopback'),
                $this->postedFlag('accept_fellowship_sessions'),
                time(),
            );
        } catch (RuntimeException) {
            return ['code' => 'failed', 'app' => 0];
        }

        return ['code' => 'app_added', 'app' => $application->id];
    }

    private function renderList(): void
    {
        $applications = $this->applications->all();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Freedom applications', 'freedom') . '</h1>';
        echo '<p class="description">'
            . esc_html__('Each application is an app that fetches its configuration from here instead of having it built in. Tablets sign in with a Google account — a member\'s own, or one of the application\'s common accounts — and receive the values set below, secrets sealed to the tablet\'s own key.', 'freedom')
            . '</p>';

        $this->notice();

        if ($applications === []) {
            echo '<p>' . esc_html__('No applications yet.', 'freedom') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th scope="col">' . esc_html__('Application', 'freedom') . '</th>';
            echo '<th scope="col">' . esc_html__('Slug', 'freedom') . '</th>';
            echo '<th scope="col">' . esc_html__('Callback', 'freedom') . '</th>';
            echo '<th scope="col">' . esc_html__('Devices', 'freedom') . '</th>';
            echo '<th scope="col">' . esc_html__('Revision', 'freedom') . '</th>';
            echo '<th scope="col">' . esc_html__('Status', 'freedom') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($applications as $application) {
                echo '<tr>';
                echo '<td><a href="' . esc_url($this->url(['app' => $application->id])) . '"><strong>'
                    . esc_html($application->name) . '</strong></a></td>';
                echo '<td><code>' . esc_html($application->slug) . '</code></td>';
                echo '<td><code>' . esc_html($application->callbackUri !== '' ? $application->callbackUri : '—') . '</code></td>';
                echo '<td>' . esc_html((string) $this->tablets->countForApplication($application->id)) . '</td>';
                echo '<td>' . esc_html((string) $application->revision) . '</td>';
                echo '<td>' . ($application->enabled ? esc_html__('Enabled', 'freedom') : '<strong>' . esc_html__('Disabled', 'freedom') . '</strong>') . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        $this->addForm();

        echo '</div>';
    }

    private function addForm(): void
    {
        echo '<h2>' . esc_html__('Add an application', 'freedom') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ADD_ACTION) . '">';
        wp_nonce_field(self::NONCE);

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="freedom-slug">' . esc_html__('Slug', 'freedom') . '</label></th><td>'
            . '<input type="text" id="freedom-slug" name="slug" class="regular-text" required pattern="[a-z0-9][a-z0-9\-]{0,63}">'
            . '<p class="description">' . esc_html__('What the app sends as its application, e.g. register. Built into the app, so it cannot be changed later.', 'freedom') . '</p>'
            . '</td></tr>';

        echo '<tr><th scope="row"><label for="freedom-name">' . esc_html__('Name', 'freedom') . '</label></th><td>'
            . '<input type="text" id="freedom-name" name="name" class="regular-text" required>'
            . '</td></tr>';

        echo '<tr><th scope="row"><label for="freedom-callback">' . esc_html__('Callback URI', 'freedom') . '</label></th><td>'
            . '<input type="text" id="freedom-callback" name="callback_uri" class="regular-text" placeholder="org.example.app.freedom://auth">'
            . '<p class="description">' . esc_html__('Where a browser sign-in returns to the app. It must match the app\'s intent filter exactly. Leave empty for an application that only accepts Link sessions.', 'freedom') . '</p>'
            . '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Sign-in', 'freedom') . '</th><td>'
            . '<label><input type="checkbox" name="accept_fellowship_sessions" value="1"> '
            . esc_html__('Accept a Link session instead of a second sign-in (for Link itself)', 'freedom') . '</label><br>'
            . '<label><input type="checkbox" name="allow_loopback" value="1"> '
            . esc_html__('Allow a loopback callback — development only', 'freedom') . '</label>'
            . '</td></tr>';

        echo '</tbody></table>';

        submit_button(__('Add application', 'freedom'));

        echo '</form>';
    }
}
