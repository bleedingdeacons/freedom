<?php

declare(strict_types=1);

namespace Freedom\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Freedom\Applications\Application;
use Freedom\Applications\ApplicationRepository;
use Freedom\Config\ConfigEditor;
use Freedom\Config\ConfigValue;
use Freedom\Config\EffectiveConfig;
use Freedom\Config\ValueRepository;
use Freedom\Core\Capabilities;
use Freedom\Tablets\Tablet;
use Freedom\Tablets\TabletRepository;
use RuntimeException;

/**
 * One tablet's configuration: what it gets from the application's
 * defaults, and what it has been given instead.
 *
 * An override is a value like any other — encrypted, versioned, sealed if
 * secret — stored against this tablet. Removing one hands the tablet the
 * default again at its next start. The table shows each key's default,
 * this tablet's override and which version the tablet should now hold, so
 * an admin can see what a tablet actually receives rather than work it
 * out.
 */
final class TabletPage
{
    use AdminSupport;

    public const SET_ACTION = 'freedom_set_override';
    public const DELETE_ACTION = 'freedom_delete_override';

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly TabletRepository $tablets,
        private readonly ValueRepository $values,
        private readonly ConfigEditor $editor,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::SET_ACTION, [$this, 'handleSet']);
        add_action('admin_post_' . self::DELETE_ACTION, [$this, 'handleDelete']);
    }

    public function render(int $tabletId): void
    {
        $tablet = $this->tablets->findById($tabletId);
        $application = $tablet === null ? null : $this->applications->findById($tablet->applicationId);

        echo '<div class="wrap">';

        if ($tablet === null || $application === null) {
            echo '<h1>' . esc_html__('Freedom', 'freedom') . '</h1><p>' . esc_html__('That tablet no longer exists.', 'freedom') . '</p></div>';

            return;
        }

        echo '<h1>' . esc_html($tablet->label !== '' ? $tablet->label : __('(unnamed tablet)', 'freedom'))
            . ' <span class="description">' . esc_html($application->name) . '</span></h1>';
        echo '<p><a href="' . esc_url($this->url(['app' => $application->id, 'tab' => 'tablets'])) . '">&larr; '
            . esc_html__('All tablets', 'freedom') . '</a></p>';

        $this->notice();

        try {
            $defaults = $this->values->defaults($application->id);
            $overrides = $this->values->overrides($application->id, $tablet->id);
        } catch (RuntimeException) {
            echo '<p>' . esc_html__('The values could not be read. See the Freedom log.', 'freedom') . '</p></div>';

            return;
        }

        $effective = EffectiveConfig::resolve($defaults, $overrides);
        $defaultsByKey = [];
        foreach ($defaults as $value) {
            $defaultsByKey[$value->key] = $value;
        }
        $overridesByKey = [];
        foreach ($overrides as $value) {
            $overridesByKey[$value->key] = $value;
        }

        $canManage = current_user_can(Capabilities::MANAGE_TABLETS);

        echo '<p class="description">'
            . esc_html__('An override replaces the application\'s value for this tablet only. The tablet picks up a change the next time its app starts.', 'freedom')
            . '</p>';

        if ($effective === []) {
            echo '<p>' . esc_html__('No values are set for this application yet.', 'freedom') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
            echo '<th scope="col" style="width:20%">' . esc_html__('Key', 'freedom') . '</th>';
            echo '<th scope="col">' . esc_html__('Application value', 'freedom') . '</th>';
            echo '<th scope="col">' . esc_html__('This tablet', 'freedom') . '</th>';
            echo '<th scope="col" style="width:10%">' . esc_html__('Tablet holds', 'freedom') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($effective as $key => $winning) {
                $key = (string) $key;
                echo '<tr>';
                echo '<td><code>' . esc_html($key) . '</code></td>';
                echo '<td>' . esc_html($this->display($defaultsByKey[$key] ?? null)) . '</td>';
                echo '<td>';
                $this->overrideCell($application, $tablet, $key, $overridesByKey[$key] ?? null, $canManage);
                echo '</td>';
                echo '<td>v' . esc_html((string) $winning->version) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        if ($canManage) {
            echo '<h2>' . esc_html__('Override a value for this tablet', 'freedom') . '</h2>';
            $this->overrideForm($application, $tablet, '', false, false);
        }

        echo '</div>';
    }

    public function handleSet(): void
    {
        $code = $this->setFromRequest();
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        $this->redirectTo($code, ['tablet' => $this->postedInt('tablet')]);
    }

    public function setFromRequest(): string
    {
        [$application, $tablet] = $this->authorisedTablet(self::SET_ACTION);

        $key = strtolower($this->postedText('key'));
        $secret = $this->postedFlag('secret');
        $value = $this->postedRaw('value');

        if ($secret && $value === '' && $this->postedFlag('existing')) {
            return 'unchanged';
        }

        return $this->editor->set($application, $tablet->id, $key, $value, $secret, get_current_user_id());
    }

    public function handleDelete(): void
    {
        $code = $this->deleteFromRequest();
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        $this->redirectTo($code, ['tablet' => $this->postedInt('tablet')]);
    }

    public function deleteFromRequest(): string
    {
        [$application, $tablet] = $this->authorisedTablet(self::DELETE_ACTION);

        return $this->editor->delete($application, $tablet->id, strtolower($this->postedText('key')), get_current_user_id());
    }

    private function overrideCell(Application $application, Tablet $tablet, string $key, ?ConfigValue $override, bool $canManage): void
    {
        if ($override === null) {
            echo '<span class="description">' . esc_html__('Uses the application value', 'freedom') . '</span>';

            if ($canManage) {
                echo '<details><summary>' . esc_html__('Override', 'freedom') . '</summary>';
                $this->overrideForm($application, $tablet, $key, false, false);
                echo '</details>';
            }

            return;
        }

        if (!$canManage) {
            echo esc_html($this->display($override));

            return;
        }

        $this->overrideForm($application, $tablet, $key, $override->isSecret, true, $override);
        $this->button(
            self::DELETE_ACTION,
            self::DELETE_ACTION . '_' . $tablet->id,
            ['tablet' => $tablet->id, 'key' => $key],
            __('Use the application value', 'freedom'),
        );
    }

    private function overrideForm(
        Application $application,
        Tablet $tablet,
        string $key,
        bool $secret,
        bool $existing,
        ?ConfigValue $current = null,
    ): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" autocomplete="off">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SET_ACTION) . '">';
        echo '<input type="hidden" name="tablet" value="' . esc_attr((string) $tablet->id) . '">';
        wp_nonce_field(self::SET_ACTION . '_' . $tablet->id);

        if ($key !== '') {
            echo '<input type="hidden" name="key" value="' . esc_attr($key) . '">';
        } else {
            echo '<input type="text" name="key" class="regular-text" placeholder="smtp.host" required pattern="[a-z0-9][a-z0-9_.\-]{0,99}"> ';
        }

        if ($existing) {
            echo '<input type="hidden" name="existing" value="1">';
        }

        if ($secret && $current !== null) {
            echo '<input type="hidden" name="secret" value="1">';
            echo '<input type="password" name="value" class="regular-text" autocomplete="new-password" placeholder="'
                . esc_attr(sprintf(__('Set (version %d). Type to replace.', 'freedom'), $current->version)) . '"> ';
        } else {
            $shown = $current === null ? '' : (string) $this->editor->reveal($current);
            echo '<input type="text" name="value" class="regular-text" value="' . esc_attr($shown) . '"> ';
            echo '<label><input type="checkbox" name="secret" value="1"> ' . esc_html__('Secret', 'freedom') . '</label> ';
        }

        submit_button(__('Save', 'freedom'), 'secondary small', 'submit', false);
        echo '</form>';
    }

    private function display(?ConfigValue $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($value->isSecret) {
            return sprintf(__('Secret, version %d', 'freedom'), $value->version);
        }

        $plaintext = $this->editor->reveal($value);

        return $plaintext === null ? __('Unreadable — re-enter it', 'freedom') : $plaintext;
    }

    /** @return array{0: Application, 1: Tablet} */
    private function authorisedTablet(string $action): array
    {
        $id = $this->postedInt('tablet');

        $this->authorise(Capabilities::MANAGE_TABLETS, $action . '_' . $id);

        $tablet = $id > 0 ? $this->tablets->findById($id) : null;
        $application = $tablet === null ? null : $this->applications->findById($tablet->applicationId);

        if ($tablet === null || $application === null) {
            wp_die(esc_html__('That tablet no longer exists.', 'freedom'), '', ['response' => 404]);
        }

        return [$application, $tablet];
    }
}
