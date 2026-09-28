<?php

/**
 * Plugin Name: Freedom
 * Description: Zero-configuration settings for MAUI apps. A tablet proves a Google account through Fellowship — a Unity member's own, or one of the application's common tablet accounts — and receives that application's key/value configuration, with per-tablet overrides and secrets sealed to the tablet's own key. Every value carries a version, and the app polls a manifest of them on every start. Requires Fellowship, Unity and Scrutiny.
 * Version: 0.1.0
 * Requires at least: 6.1
 * Requires PHP: 8.4
 * Requires Plugins: unity, scrutiny, fellowship
 * GitHub Plugin URI: https://github.com/bleedingdeacons/freedom
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/freedom
 * Contact: thebleedingdeacons@gmail.com
 * Text Domain: freedom
 * License: MIT (Modified)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
}

$freedom_plugin_data = get_plugin_data(__FILE__, false, false);
define('FREEDOM_VERSION', $freedom_plugin_data['Version']);
define('FREEDOM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FREEDOM_PLUGIN_FILE', __FILE__);

/**
 * The first Fellowship release with the IdentityBroker. Named in the
 * admin notice, because `Requires Plugins` checks that Fellowship is
 * active and nothing about which version.
 */
define('FREEDOM_MIN_FELLOWSHIP', '2.5.0');

// Autoloader for the Freedom namespace.
spl_autoload_register(function ($class) {
    $prefix = 'Freedom\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $file = FREEDOM_PLUGIN_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Get the Freedom dependency container (Unity's container).
 *
 * @return \Psr\Container\ContainerInterface
 * @throws \RuntimeException If Freedom is not initialised
 */
function freedom(): \Psr\Container\ContainerInterface
{
    return \Freedom\Plugin::getContainer();
}

// Initialise after Fellowship, whose broker, member gate and sealer Freedom
// is built on. Fellowship fires this from inside unity/loaded, so Unity and
// Scrutiny are already up by the time it does.
add_action('fellowship/loaded', function ($container) {
    try {
        // The kill switch stands Freedom down without deactivating it: no
        // routes, no admin, and no audience registered with Fellowship, so
        // a sign-in started before it was set fails at the callback.
        //
        // Tablets are deliberately left alone. Their manifest polls fail,
        // and a tablet that cannot reach the server keeps the configuration
        // it has — which is exactly what a temporary stand-down wants.
        if (defined('FREEDOM_KILL') && FREEDOM_KILL) {
            return;
        }

        if (!function_exists('scrutiny')) {
            throw new \Exception('Scrutiny plugin is required but not active. Please install and activate Scrutiny before using Freedom.');
        }

        if (!class_exists('Fellowship\Auth\IdentityBroker')) {
            throw new \Exception(sprintf(
                'Freedom needs Fellowship %s or later, which signs tablets in on its behalf. Please update Fellowship.',
                FREEDOM_MIN_FELLOWSHIP,
            ));
        }

        if (!$container instanceof \Unity\Core\Interfaces\Container) {
            throw new \Exception('Fellowship did not hand Freedom a Unity container.');
        }

        \Freedom\Plugin::init($container);

        do_action('freedom/loaded', \Freedom\Plugin::getContainer());
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('freedom')->error('Freedom Plugin Initialization Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Freedom Plugin Initialization Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Freedom Plugin Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
}, 10);

// Show an admin notice if Fellowship never loaded — inactive, killed, or
// missing Unity or Scrutiny itself.
add_action('plugins_loaded', function () {
    if (!did_action('fellowship/loaded') && !class_exists('Fellowship\Plugin')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__('Freedom', 'freedom') . ':</strong> ';
            echo esc_html__('This plugin requires the Fellowship plugin, which signs tablets in on its behalf.', 'freedom');
            echo '</p></div>';
        });
    }
}, 20);

register_activation_hook(__FILE__, function () {
    if (!function_exists('scrutiny') || !class_exists('Fellowship\Plugin')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Freedom requires the Unity, Scrutiny and Fellowship plugins to be installed and activated.', 'freedom'),
            esc_html__('Plugin Activation Error', 'freedom'),
            ['back_link' => true]
        );
    }

    global $wpdb;
    \Freedom\Core\Schema::install($wpdb);
    \Freedom\Core\Schema::markInstalled();

    \Freedom\Core\Capabilities::ensureAssigned();
});

// Self-deactivate if Scrutiny goes: every enrolment is audited through it.
add_action('admin_init', function () {
    if (is_plugin_active(plugin_basename(__FILE__)) && !function_exists('scrutiny')) {
        deactivate_plugins(plugin_basename(__FILE__));
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Freedom has been deactivated:</strong> The Scrutiny plugin is required for audit logging but is not active.</p></div>';
        });
    }
});
