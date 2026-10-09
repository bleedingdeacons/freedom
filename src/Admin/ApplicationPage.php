<?php

declare(strict_types=1);

namespace Freedom\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Freedom\Accounts\CommonAccountRepository;
use Freedom\Applications\Application;
use Freedom\Applications\ApplicationRepository;
use Freedom\Applications\CallbackUri;
use Freedom\Auth\ClientSecrets;
use Freedom\Config\ConfigEditor;
use Freedom\Config\ConfigValue;
use Freedom\Config\ValueRepository;
use Freedom\Core\Capabilities;
use Freedom\Tablets\Tablet;
use Freedom\Tablets\TabletAudit;
use Freedom\Tablets\TabletRepository;
use RuntimeException;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\MemberRepository;

/**
 * One application: its configuration, its common accounts, its tablets,
 * and its details — a tab each.
 *
 * <b>Configuration first, because it is what changes.</b> A value is
 * edited in place and saved on its own row; a plain value is shown, and a
 * secret is write-only — the row says it is set and at which version, and
 * a new value replaces it. A secret is never echoed back into the page,
 * where it would sit in the browser's form history.
 */
final class ApplicationPage
{
    use AdminSupport;

    public const SAVE_ACTION = 'freedom_save_application';
    public const DELETE_ACTION = 'freedom_delete_application';
    public const ADD_ACCOUNT_ACTION = 'freedom_add_account';
    public const REMOVE_ACCOUNT_ACTION = 'freedom_remove_account';
    public const SET_VALUE_ACTION = 'freedom_set_value';
    public const DELETE_VALUE_ACTION = 'freedom_delete_value';
    public const TABLET_ACTION = 'freedom_tablet_action';

    private const TABS = ['configuration', 'accounts', 'devices', 'details'];

    private const TABLET_OPERATIONS = ['revoke', 'block', 'unblock', 'remove'];

    /** What Google's console issues. Catches a secret pasted into the id field. */
    private const GOOGLE_CLIENT_ID = '/^[A-Za-z0-9-]{1,200}\.apps\.googleusercontent\.com$/';

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly CommonAccountRepository $accounts,
        private readonly TabletRepository $tablets,
        private readonly ValueRepository $values,
        private readonly ConfigEditor $editor,
        private readonly TabletAudit $audit,
        private readonly MemberRepository $members,
        private readonly ClientSecrets $secrets,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
        add_action('admin_post_' . self::DELETE_ACTION, [$this, 'handleDelete']);
        add_action('admin_post_' . self::ADD_ACCOUNT_ACTION, [$this, 'handleAddAccount']);
        add_action('admin_post_' . self::REMOVE_ACCOUNT_ACTION, [$this, 'handleRemoveAccount']);
        add_action('admin_post_' . self::SET_VALUE_ACTION, [$this, 'handleSetValue']);
        add_action('admin_post_' . self::DELETE_VALUE_ACTION, [$this, 'handleDeleteValue']);
        add_action('admin_post_' . self::TABLET_ACTION, [$this, 'handleTabletAction']);
    }

    public function render(int $applicationId, string $tab): void
    {
        $application = $this->applications->findById($applicationId);
        if ($application === null) {
            echo '<div class="wrap"><h1>' . esc_html__('Freedom', 'freedom') . '</h1><p>'
                . esc_html__('That application no longer exists.', 'freedom') . '</p></div>';

            return;
        }

        $tab = in_array($tab, self::TABS, true) ? $tab : 'configuration';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($application->name) . ' <code>' . esc_html($application->slug) . '</code></h1>';
        echo '<p><a href="' . esc_url($this->url()) . '">&larr; ' . esc_html__('All applications', 'freedom') . '</a></p>';

        if (!$application->enabled) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('This application is disabled. Its tablets keep what they have and are refused anything new until it is enabled again.', 'freedom')
                . '</p></div>';
        }

        $this->notice();

        echo '<nav class="nav-tab-wrapper">';
        $labels = [
            'configuration' => __('Configuration', 'freedom'),
            'accounts'      => __('Common accounts', 'freedom'),
            'devices'       => __('Devices', 'freedom'),
            'details'       => __('Details', 'freedom'),
        ];
        foreach ($labels as $key => $label) {
            echo '<a class="nav-tab' . ($key === $tab ? ' nav-tab-active' : '') . '" href="'
                . esc_url($this->url(['app' => $application->id, 'tab' => $key])) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        match ($tab) {
            'accounts' => $this->renderAccounts($application),
            'devices'  => $this->renderTablets($application),
            'details'  => $this->renderDetails($application),
            default    => $this->renderConfiguration($application),
        };

        echo '</div>';
    }

    // ── Handlers ──────────────────────────────────────────────────────

    public function handleSave(): void
    {
        [$code, $id] = [$this->saveFromRequest(), $this->postedInt('app')];
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        $this->redirectTo($code, ['app' => $id, 'tab' => 'details']);
    }

    public function saveFromRequest(): string
    {
        $application = $this->authorisedApplication(Capabilities::MANAGE_APPLICATIONS, self::SAVE_ACTION);

        $name = $this->postedText('name');
        $callback = trim($this->postedRaw('callback_uri'));

        if ($name === '') {
            return 'bad_name';
        }

        if ($callback !== '' && !CallbackUri::isAcceptable($callback)) {
            return 'bad_callback';
        }

        // The Google client is checked before anything is written, so a
        // mistyped id cannot save half the form.
        $clientId = $this->postedText('google_client_id');
        $clientSecret = trim($this->postedRaw('google_client_secret'));
        if ($clientId !== '' && preg_match(self::GOOGLE_CLIENT_ID, $clientId) !== 1) {
            return 'bad_google_client';
        }
        if ($clientId !== '' && $clientSecret === '' && $application->googleClientSecret === null) {
            return 'google_secret_needed';
        }

        $saved = $this->applications->update(
            $application->id,
            $name,
            $callback,
            $this->postedFlag('allow_loopback'),
            $this->postedFlag('accept_fellowship_sessions'),
            $this->postedFlag('enabled'),
            time(),
        );

        return $saved && $this->saveGoogleClient($application, $clientId, $clientSecret) ? 'app_saved' : 'failed';
    }

    /**
     * An empty id goes back to Fellowship's client and clears the secret
     * with it. A blank secret keeps the stored one, so the id can be saved
     * without the secret ever being shown again.
     */
    private function saveGoogleClient(Application $application, string $clientId, string $clientSecret): bool
    {
        if ($clientId === '') {
            return $this->applications->setGoogleClient($application->id, '', '', time());
        }

        $encrypted = null;
        if ($clientSecret !== '') {
            $encrypted = $this->secrets->encrypt($clientSecret);
            if ($encrypted === '') {
                return false;
            }
        }

        return $this->applications->setGoogleClient($application->id, $clientId, $encrypted, time());
    }

    public function handleDelete(): void
    {
        $code = $this->deleteFromRequest();
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        $this->redirectTo($code);
    }

    /**
     * Delete an application and everything under it. Values first, then
     * tablets, then accounts, then the row: a failure part-way leaves an
     * application with less in it, never values belonging to nothing.
     */
    public function deleteFromRequest(): string
    {
        $application = $this->authorisedApplication(Capabilities::MANAGE_APPLICATIONS, self::DELETE_ACTION);

        $this->values->removeForApplication($application->id);

        foreach ($this->tablets->forApplication($application->id) as $tablet) {
            $this->audit->removed($tablet, 'admin:' . get_current_user_id());
        }
        $this->tablets->removeForApplication($application->id);

        foreach ($this->accounts->forApplication($application->id) as $account) {
            $this->audit->accountRemoved($account, 'admin:' . get_current_user_id());
        }
        $this->accounts->removeForApplication($application->id);

        return $this->applications->remove($application->id) ? 'app_deleted' : 'failed';
    }

    public function handleAddAccount(): void
    {
        [$code, $id] = [$this->addAccountFromRequest(), $this->postedInt('app')];
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        $this->redirectTo($code, ['app' => $id, 'tab' => 'accounts']);
    }

    public function addAccountFromRequest(): string
    {
        $application = $this->authorisedApplication(Capabilities::MANAGE_APPLICATIONS, self::ADD_ACCOUNT_ACTION);

        $posted = $this->postedRaw('email');
        $email = strtolower(trim(sanitize_email($posted)));

        // 191: the column is sized to fit utf8mb4's index limit alongside
        // the application id. See WpdbCommonAccountRepository.
        if ($email === '' || !is_email($email) || strlen($email) > 191) {
            return 'bad_address';
        }

        if ($this->accounts->find($application->id, $email) !== null) {
            return 'account_exists';
        }

        try {
            $account = $this->accounts->add($application->id, $email, $this->postedText('label'), get_current_user_id(), time());
        } catch (RuntimeException) {
            return 'failed';
        }

        $this->audit->accountAdded($account, 'admin:' . get_current_user_id());

        return 'account_added';
    }

    public function handleRemoveAccount(): void
    {
        [$code, $id] = [$this->removeAccountFromRequest(), $this->postedInt('app')];
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        $this->redirectTo($code, ['app' => $id, 'tab' => 'accounts']);
    }

    /**
     * Take an account off the list. Its tablets' rows are left alone: the
     * gate refuses them from their next request, and putting the account
     * back restores them without anybody signing in again.
     */
    public function removeAccountFromRequest(): string
    {
        $application = $this->authorisedApplication(Capabilities::MANAGE_APPLICATIONS, self::REMOVE_ACCOUNT_ACTION);

        $account = $this->accounts->findById($this->postedInt('account'));
        if ($account === null || $account->applicationId !== $application->id) {
            return 'not_found';
        }

        if (!$this->accounts->remove($account->id)) {
            return 'failed';
        }

        $this->audit->accountRemoved($account, 'admin:' . get_current_user_id());

        return 'account_removed';
    }

    public function handleSetValue(): void
    {
        [$code, $id] = [$this->setValueFromRequest(), $this->postedInt('app')];
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        $this->redirectTo($code, ['app' => $id, 'tab' => 'configuration']);
    }

    public function setValueFromRequest(): string
    {
        $application = $this->authorisedApplication(Capabilities::MANAGE_APPLICATIONS, self::SET_VALUE_ACTION);

        $key = strtolower($this->postedText('key'));
        $secret = $this->postedFlag('secret');
        $value = $this->postedRaw('value');

        // A secret row's field starts empty, since the value is never
        // echoed back. Saved empty, it means "leave it as it is".
        if ($secret && $value === '' && $this->postedFlag('existing')) {
            return 'unchanged';
        }

        return $this->editor->set($application, 0, $key, $value, $secret, get_current_user_id());
    }

    public function handleDeleteValue(): void
    {
        [$code, $id] = [$this->deleteValueFromRequest(), $this->postedInt('app')];
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        $this->redirectTo($code, ['app' => $id, 'tab' => 'configuration']);
    }

    public function deleteValueFromRequest(): string
    {
        $application = $this->authorisedApplication(Capabilities::MANAGE_APPLICATIONS, self::DELETE_VALUE_ACTION);

        return $this->editor->delete($application, 0, strtolower($this->postedText('key')), get_current_user_id());
    }

    public function handleTabletAction(): void
    {
        [$code, $id] = [$this->tabletActionFromRequest(), $this->postedInt('app')];
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        $this->redirectTo($code, ['app' => $id, 'tab' => 'devices']);
    }

    public function tabletActionFromRequest(): string
    {
        $tabletId = $this->postedInt('tablet');

        // The tablet id is part of the nonce, so a form for one tablet
        // cannot be replayed against another.
        $this->authorise(Capabilities::MANAGE_TABLETS, self::TABLET_ACTION . '_' . $tabletId);

        $tablet = $this->tablets->findById($tabletId);
        if ($tablet === null || $tablet->applicationId !== $this->postedInt('app')) {
            return 'not_found';
        }

        $operation = $this->postedText('operation');
        if (!in_array($operation, self::TABLET_OPERATIONS, true)) {
            return 'no_change';
        }

        $by = 'admin:' . get_current_user_id();
        $now = time();

        switch ($operation) {
            case 'revoke':
                if (!$this->tablets->revoke($tablet->id, $now)) {
                    return 'no_change';
                }
                $this->audit->revoked($tablet, $by);

                return 'revoked';

            case 'block':
                if (!$this->tablets->block($tablet->id, $now)) {
                    return 'failed';
                }
                $this->audit->blocked($tablet, $by);

                return 'blocked';

            case 'unblock':
                if (!$this->tablets->unblock($tablet->id)) {
                    return 'failed';
                }
                $this->audit->unblocked($tablet, $by);

                return 'unblocked';

            default:
                // Revoked first, then its overrides, then the row: a failed
                // delete still leaves the tablet cut off.
                $this->tablets->revoke($tablet->id, $now);
                $this->values->removeForTablet($tablet->id);
                if (!$this->tablets->remove($tablet->id)) {
                    return 'failed';
                }
                $this->audit->removed($tablet, $by);

                return 'removed';
        }
    }

    // ── Tabs ──────────────────────────────────────────────────────────

    private function renderConfiguration(Application $application): void
    {
        try {
            $values = $this->values->defaults($application->id);
        } catch (RuntimeException) {
            echo '<p>' . esc_html__('The values could not be read. See the Freedom log.', 'freedom') . '</p>';

            return;
        }

        echo '<p class="description">'
            . esc_html__('Every tablet of this application receives these, unless a tablet has its own override. A change reaches each tablet the next time its app starts. Secrets are stored encrypted and sealed to each tablet\'s own key on the way; they are never shown here again once saved.', 'freedom')
            . '</p>';

        if ($values !== []) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th scope="col" style="width:22%">' . esc_html__('Key', 'freedom') . '</th>';
            echo '<th scope="col">' . esc_html__('Value', 'freedom') . '</th>';
            echo '<th scope="col" style="width:8%">' . esc_html__('Version', 'freedom') . '</th>';
            echo '<th scope="col" style="width:14%">' . esc_html__('Updated', 'freedom') . '</th>';
            echo '<th scope="col" style="width:8%"></th>';
            echo '</tr></thead><tbody>';

            foreach ($values as $value) {
                $this->valueRow($application, $value);
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No values yet.', 'freedom') . '</p>';
        }

        echo '<h2>' . esc_html__('Add a value', 'freedom') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" autocomplete="off">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SET_VALUE_ACTION) . '">';
        echo '<input type="hidden" name="app" value="' . esc_attr((string) $application->id) . '">';
        wp_nonce_field(self::SET_VALUE_ACTION . '_' . $application->id);
        echo '<p><input type="text" name="key" class="regular-text" placeholder="smtp.host" required pattern="[a-z0-9][a-z0-9_.\-]{0,99}"> ';
        echo '<input type="text" name="value" class="regular-text" placeholder="' . esc_attr__('Value', 'freedom') . '"> ';
        echo '<label><input type="checkbox" name="secret" value="1"> ' . esc_html__('Secret', 'freedom') . '</label> ';
        submit_button(__('Add', 'freedom'), 'secondary', 'submit', false);
        echo '</p></form>';
    }

    private function valueRow(Application $application, ConfigValue $value): void
    {
        $plaintext = $value->isSecret ? null : $this->editor->reveal($value);
        $unreadable = $this->editor->reveal($value) === null;
        $formId = 'freedom-value-' . $value->id;

        echo '<tr>';
        echo '<td><code>' . esc_html($value->key) . '</code>'
            . ($value->isSecret ? ' <span class="dashicons dashicons-lock" title="' . esc_attr__('Secret', 'freedom') . '"></span>' : '')
            . '</td>';

        echo '<td>';
        echo '<form id="' . esc_attr($formId) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" autocomplete="off">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SET_VALUE_ACTION) . '">';
        echo '<input type="hidden" name="app" value="' . esc_attr((string) $application->id) . '">';
        echo '<input type="hidden" name="key" value="' . esc_attr($value->key) . '">';
        echo '<input type="hidden" name="existing" value="1">';
        if ($value->isSecret) {
            echo '<input type="hidden" name="secret" value="1">';
        }
        wp_nonce_field(self::SET_VALUE_ACTION . '_' . $application->id);

        if ($unreadable) {
            echo '<strong style="color:#b32d2e">' . esc_html__('Unreadable — re-enter it', 'freedom') . '</strong><br>';
        }

        if ($value->isSecret) {
            echo '<input type="password" name="value" class="large-text" autocomplete="new-password" placeholder="'
                . esc_attr(sprintf(__('Set (version %d). Type to replace.', 'freedom'), $value->version)) . '">';
        } else {
            echo '<input type="text" name="value" class="large-text" value="' . esc_attr((string) $plaintext) . '">';
        }

        echo ' <button type="submit" class="button button-small">' . esc_html__('Save', 'freedom') . '</button>';
        echo '</form></td>';

        echo '<td>' . esc_html((string) $value->version) . '</td>';
        echo '<td>' . esc_html($this->when($value->updatedAt)) . '</td>';
        echo '<td>';
        $this->button(
            self::DELETE_VALUE_ACTION,
            self::DELETE_VALUE_ACTION . '_' . $application->id,
            ['app' => $application->id, 'key' => $value->key],
            __('Delete', 'freedom'),
            __('Delete this value? Every tablet removes it the next time it starts.', 'freedom'),
        );
        echo '</td>';
        echo '</tr>';
    }

    private function renderAccounts(Application $application): void
    {
        $accounts = $this->accounts->forApplication($application->id);

        echo '<p class="description">'
            . esc_html__('Any Unity member may sign a tablet in with their own Google account. These are the other accounts that may: shared accounts that tablets use and that belong to no member. A tablet signed in with one is not tied to anybody\'s membership.', 'freedom')
            . '</p>';

        if ($accounts !== []) {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
            echo '<th scope="col">' . esc_html__('Account', 'freedom') . '</th>';
            echo '<th scope="col">' . esc_html__('Label', 'freedom') . '</th>';
            echo '<th scope="col">' . esc_html__('Added', 'freedom') . '</th>';
            echo '<th scope="col"></th>';
            echo '</tr></thead><tbody>';

            foreach ($accounts as $account) {
                echo '<tr>';
                echo '<td>' . esc_html($account->email) . '</td>';
                echo '<td>' . esc_html($account->label) . '</td>';
                echo '<td>' . esc_html($this->when($account->createdAt)) . '</td>';
                echo '<td>';
                $this->button(
                    self::REMOVE_ACCOUNT_ACTION,
                    self::REMOVE_ACCOUNT_ACTION . '_' . $application->id,
                    ['app' => $application->id, 'account' => $account->id],
                    __('Remove', 'freedom'),
                    __('Remove this account? Tablets signed in with it are refused from their next request.', 'freedom'),
                );
                echo '</td></tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No common accounts. Only members can sign tablets in.', 'freedom') . '</p>';
        }

        echo '<h2>' . esc_html__('Add a common account', 'freedom') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ADD_ACCOUNT_ACTION) . '">';
        echo '<input type="hidden" name="app" value="' . esc_attr((string) $application->id) . '">';
        wp_nonce_field(self::ADD_ACCOUNT_ACTION . '_' . $application->id);
        echo '<p><input type="email" name="email" class="regular-text" required placeholder="tablets@example.org"> ';
        echo '<input type="text" name="label" class="regular-text" placeholder="' . esc_attr__('Label, e.g. Intergroup tablets', 'freedom') . '"> ';
        submit_button(__('Add account', 'freedom'), 'secondary', 'submit', false);
        echo '</p></form>';
    }

    private function renderTablets(Application $application): void
    {
        $tablets = $this->tablets->forApplication($application->id);
        $canManage = current_user_can(Capabilities::MANAGE_TABLETS);
        $canSeeMembers = current_user_can(PersonalDataPolicy::VIEW_CAPABILITY);

        if ($tablets === []) {
            echo '<p>' . esc_html__('No devices have signed in yet.', 'freedom') . '</p>';

            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Device', 'freedom') . '</th>';
        echo '<th scope="col">' . esc_html__('Signed in as', 'freedom') . '</th>';
        echo '<th scope="col">' . esc_html__('Enrolled', 'freedom') . '</th>';
        echo '<th scope="col">' . esc_html__('Last checked in', 'freedom') . '</th>';
        echo '<th scope="col">' . esc_html__('Status', 'freedom') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($tablets as $tablet) {
            echo '<tr>';
            echo '<td><a href="' . esc_url($this->url(['tablet' => $tablet->id])) . '">'
                . esc_html($tablet->label !== '' ? $tablet->label : __('(unnamed)', 'freedom')) . '</a>';
            echo '<br><span class="description">' . esc_html(trim($tablet->model . ' ' . $tablet->platform . ' ' . $tablet->appVersion)) . '</span></td>';
            echo '<td>' . esc_html($this->account($application, $tablet, $canSeeMembers)) . '</td>';
            echo '<td>' . esc_html($this->when($tablet->createdAt));
            if ($tablet->reattachedAt !== null) {
                // Worth seeing: a device identifier is the app's word, so a
                // re-attachment by a different account is the one thing an
                // admin may want to look at twice.
                echo '<br><span class="description">' . esc_html(sprintf(__('Re-attached %s', 'freedom'), $this->when($tablet->reattachedAt))) . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html($this->when($tablet->lastManifestAt)) . '</td>';
            echo '<td>';
            $this->tabletStatus($application, $tablet, $canManage);
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function tabletStatus(Application $application, Tablet $tablet, bool $canManage): void
    {
        if ($tablet->hasKeyFault()) {
            echo '<span style="color:#b32d2e"><strong>' . esc_html__('Cannot open its secrets — sign it in again', 'freedom') . '</strong></span><br>';
        }

        if ($tablet->isBlocked()) {
            echo '<strong>' . esc_html__('Blocked', 'freedom') . '</strong> ';
        } elseif ($tablet->isRevoked()) {
            echo esc_html(sprintf(__('Revoked %s', 'freedom'), $this->when($tablet->revokedAt))) . ' ';
        } else {
            echo esc_html__('Active', 'freedom') . ' ';
        }

        if (!$canManage) {
            return;
        }

        $nonce = self::TABLET_ACTION . '_' . $tablet->id;
        $fields = static fn(string $op): array => ['app' => $application->id, 'tablet' => $tablet->id, 'operation' => $op];

        if (!$tablet->isRevoked()) {
            $this->button(self::TABLET_ACTION, $nonce, $fields('revoke'), __('Revoke', 'freedom'));
            echo ' ';
        }

        if ($tablet->isBlocked()) {
            $this->button(self::TABLET_ACTION, $nonce, $fields('unblock'), __('Unblock', 'freedom'));
        } else {
            $this->button(self::TABLET_ACTION, $nonce, $fields('block'), __('Block', 'freedom'));
        }

        echo ' ';
        $this->button(
            self::TABLET_ACTION,
            $nonce,
            $fields('remove'),
            __('Remove', 'freedom'),
            __('Remove this tablet and its overrides? Revoking keeps them instead.', 'freedom'),
        );
    }

    private function account(Application $application, Tablet $tablet, bool $canSeeMembers): string
    {
        if (!$tablet->isMemberAccount()) {
            foreach ($this->accounts->forApplication($application->id) as $account) {
                if ($account->id === $tablet->accountId) {
                    return $account->label !== '' ? $account->label : $account->email;
                }
            }

            return __('A common account (since removed)', 'freedom');
        }

        if (!$canSeeMembers) {
            return __('A member', 'freedom');
        }

        $member = $tablet->memberId > 0 ? $this->members->findById($tablet->memberId) : null;
        $name = $member === null ? '' : trim($member->getAnonymousName());
        $suffix = $tablet->isLinkSession() ? ' ' . __('(via Link)', 'freedom') : '';

        return ($name !== '' ? $name : __('(no member record)', 'freedom')) . $suffix;
    }

    private function renderDetails(Application $application): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '">';
        echo '<input type="hidden" name="app" value="' . esc_attr((string) $application->id) . '">';
        wp_nonce_field(self::SAVE_ACTION . '_' . $application->id);

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Slug', 'freedom') . '</th><td><code>' . esc_html($application->slug) . '</code>'
            . '<p class="description">' . esc_html__('Built into the app, so it cannot be changed here.', 'freedom') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="freedom-name">' . esc_html__('Name', 'freedom') . '</label></th><td>'
            . '<input type="text" id="freedom-name" name="name" class="regular-text" required value="' . esc_attr($application->name) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="freedom-callback">' . esc_html__('Callback URI', 'freedom') . '</label></th><td>'
            . '<input type="text" id="freedom-callback" name="callback_uri" class="regular-text" value="' . esc_attr($application->callbackUri) . '">'
            . '<p class="description">' . esc_html__('Must match the app exactly. Changing it breaks sign-in for every copy of the app built with the old one.', 'freedom') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Sign-in', 'freedom') . '</th><td>'
            . '<label><input type="checkbox" name="accept_fellowship_sessions" value="1"' . checked($application->acceptFellowshipSessions, true, false) . '> '
            . esc_html__('Accept a Link session instead of a second sign-in', 'freedom') . '</label><br>'
            . '<label><input type="checkbox" name="allow_loopback" value="1"' . checked($application->allowLoopback, true, false) . '> '
            . esc_html__('Allow a loopback callback — development only', 'freedom') . '</label></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Status', 'freedom') . '</th><td>'
            . '<label><input type="checkbox" name="enabled" value="1"' . checked($application->enabled, true, false) . '> '
            . esc_html__('Enabled', 'freedom') . '</label>'
            . '<p class="description">' . esc_html__('Disabling stands the application down: tablets keep what they have and receive nothing new. It does not take anything away — revoke or block tablets for that.', 'freedom') . '</p></td></tr>';
        echo '</tbody></table>';

        $this->renderGoogleClient($application);

        submit_button(__('Save', 'freedom'));
        echo '</form>';

        echo '<h2>' . esc_html__('Delete this application', 'freedom') . '</h2>';
        echo '<p class="description">' . esc_html__('Deletes every value, common account and tablet with it. Tablets are refused from their next request and clear what they hold.', 'freedom') . '</p>';
        $this->button(
            self::DELETE_ACTION,
            self::DELETE_ACTION . '_' . $application->id,
            ['app' => $application->id],
            __('Delete application', 'freedom'),
            __('Delete this application and everything under it? This cannot be undone.', 'freedom'),
        );
    }

    /**
     * The application's own Google client: optional, so its tablets see its
     * own consent screen rather than Link's. The secret is write-only, like
     * a configuration secret.
     */
    private function renderGoogleClient(Application $application): void
    {
        echo '<h2>' . esc_html__('Google sign-in client', 'freedom') . '</h2>';
        echo '<p class="description">' . esc_html__('Optional. Without one, tablets sign in with Fellowship\'s Google client and see its consent screen. With one, they see this application\'s own.', 'freedom') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="freedom-google-client-id">' . esc_html__('Client ID', 'freedom') . '</label></th><td>'
            . '<input type="text" id="freedom-google-client-id" name="google_client_id" class="large-text code" autocomplete="off" value="' . esc_attr($application->googleClientId) . '">'
            . '<p class="description">' . esc_html__('A Web application client from the Credentials page of the application\'s Google Cloud project. Clear it to go back to Fellowship\'s client.', 'freedom') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="freedom-google-client-secret">' . esc_html__('Client secret', 'freedom') . '</label></th><td>'
            . '<input type="password" id="freedom-google-client-secret" name="google_client_secret" class="regular-text" autocomplete="new-password" value="">'
            . '<p class="description">' . esc_html($application->googleClientSecret !== null
                ? __('Stored. Leave blank to keep it.', 'freedom')
                : __('Not set.', 'freedom')) . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Authorized redirect URI', 'freedom') . '</th><td>'
            . '<code>' . esc_html(rest_url('fellowship/v1/auth/callback')) . '</code>'
            . '<p class="description">' . esc_html__('Add this to the client in Google\'s console. Sign-in still returns through Fellowship, whichever client starts it.', 'freedom') . '</p></td></tr>';
        echo '</tbody></table>';
    }

    /** The application the posted form names, after its capability and per-application nonce check. */
    private function authorisedApplication(string $capability, string $action): Application
    {
        $id = $this->postedInt('app');

        $this->authorise($capability, $action . '_' . $id);

        $application = $id > 0 ? $this->applications->findById($id) : null;
        if ($application === null) {
            wp_die(esc_html__('That application no longer exists.', 'freedom'), '', ['response' => 404]);
        }

        return $application;
    }
}
