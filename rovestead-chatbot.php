<?php
/**
 * Plugin Name: Rovestead Chatbot Email Notifier
 * Description: Enables email notifications for the Rovestead AI chatbot (escalations and error alerts)
 * Version: 1.1.0
 * Author: Bloomfield AI Solutions
 * Author URI: https://bloomfieldaisolutions.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// AUTO-UPDATER (checks GitHub for new releases)
// =============================================================================

require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$rovesteadUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/coleturnerai-ux/rovestead-chatbot-plugin',
    __FILE__,
    'rovestead-chatbot'
);
$rovesteadUpdateChecker->setBranch('main');
$rovesteadUpdateChecker->getVcsApi()->enableReleaseAssets();

// =============================================================================
// REST API ENDPOINT
// =============================================================================

add_action('rest_api_init', function() {
    register_rest_route('rovestead-chatbot/v1', '/notify', [
        'methods' => 'POST',
        'callback' => 'rovestead_send_notification',
        'permission_callback' => 'rovestead_verify_secret'
    ]);

    register_rest_route('rovestead-chatbot/v1', '/config', [
        'methods' => 'GET',
        'callback' => 'rovestead_get_config',
        'permission_callback' => 'rovestead_verify_secret_get'
    ]);
});

/**
 * Security check - verify the shared secret
 */
function rovestead_verify_secret($request) {
    $secret = $request->get_header('X-Chatbot-Secret');
    $expected = get_option('rovestead_chatbot_secret', '');

    if (empty($expected)) {
        return new WP_Error('no_secret', 'Plugin not configured - secret key missing', ['status' => 500]);
    }

    return hash_equals($expected, $secret);
}

/**
 * Handle the email notification
 */
function rovestead_send_notification($request) {
    $params = $request->get_json_params();

    $type = $params['type'] ?? 'escalation';
    $message = $params['message'] ?? 'No message provided';
    $context = $params['context'] ?? [];

    // Get notification email from settings
    $notification_email = get_option('rovestead_notification_email', get_option('admin_email'));

    // Email recipients and subject based on type
    if ($type === 'error') {
        $to = [$notification_email];
        $subject = '🚨 Chatbot Error Alert - ' . get_bloginfo('name');
    } else {
        $to = [$notification_email];
        $subject = '🤖 Customer Escalation Request - ' . get_bloginfo('name');
    }

    // Build email body
    $body = "Notification from Rovestead Chatbot\n";
    $body .= str_repeat("=", 50) . "\n\n";
    $body .= "Type: " . ucfirst($type) . "\n";
    $body .= "Time: " . current_time('mysql') . "\n";
    $body .= "Site: " . get_bloginfo('name') . " (" . get_site_url() . ")\n\n";
    $body .= "Message:\n" . $message . "\n\n";

    if (!empty($context)) {
        $body .= "Additional Context:\n";
        $body .= str_repeat("-", 50) . "\n";
        foreach ($context as $key => $value) {
            $body .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
        }
    }

    $body .= "\n" . str_repeat("=", 50) . "\n";
    $body .= "This notification was sent automatically by the Rovestead Chatbot.\n";

    // Send email using WordPress's configured SMTP
    $sent = wp_mail($to, $subject, $body);

    if ($sent) {
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Notification sent successfully'
        ], 200);
    } else {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to send email - check WordPress email configuration'
        ], 500);
    }
}

/**
 * Security check for GET requests (uses query parameter instead of header)
 */
function rovestead_verify_secret_get($request) {
    $secret = $request->get_param('secret');
    $expected = get_option('rovestead_chatbot_secret', '');

    if (empty($expected)) {
        return new WP_Error('no_secret', 'Plugin not configured - secret key missing', ['status' => 500]);
    }

    return hash_equals($expected, $secret);
}

/**
 * Get chatbot configuration (featured product, etc.)
 */
function rovestead_get_config($request) {
    $config = [
        'featured_product' => [
            'enabled' => get_option('rovestead_featured_enabled', '0') === '1',
            'product_id' => get_option('rovestead_featured_product_id', ''),
            'pitch' => get_option('rovestead_featured_pitch', '')
        ]
    ];

    return new WP_REST_Response($config, 200);
}

// =============================================================================
// AJAX PRODUCT SEARCH (for admin settings)
// =============================================================================

add_action('wp_ajax_rovestead_search_products', 'rovestead_search_products');

function rovestead_search_products() {
    check_ajax_referer('rovestead_product_search', 'nonce');

    $search = sanitize_text_field($_GET['term'] ?? '');
    if (empty($search)) {
        wp_send_json([]);
    }

    $args = [
        'post_type' => 'product',
        'posts_per_page' => 10,
        's' => $search,
        'post_status' => 'publish'
    ];

    $products = get_posts($args);
    $results = [];

    foreach ($products as $product) {
        $wc_product = function_exists('wc_get_product') ? wc_get_product($product->ID) : null;
        $price = $wc_product ? strip_tags($wc_product->get_price_html()) : '';
        $results[] = [
            'id' => $product->ID,
            'text' => $product->post_title . ' (#' . $product->ID . ')' . ($price ? ' - ' . $price : '')
        ];
    }

    wp_send_json($results);
}

// =============================================================================
// ADMIN SETTINGS PAGE
// =============================================================================

add_action('admin_menu', 'rovestead_add_admin_menu');
add_action('admin_init', 'rovestead_settings_init');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'rovestead_add_settings_link');

function rovestead_add_settings_link($links) {
    $settings_url = admin_url('options-general.php?page=rovestead_chatbot');
    array_unshift($links, '<a href="' . esc_url($settings_url) . '">Settings</a>');
    return $links;
}

function rovestead_add_admin_menu() {
    add_options_page(
        'Chatbot Notifications',
        'Chatbot Notifications',
        'manage_options',
        'rovestead_chatbot',
        'rovestead_options_page'
    );
}

function rovestead_settings_init() {
    register_setting('rovestead_chatbot', 'rovestead_chatbot_secret');
    register_setting('rovestead_chatbot', 'rovestead_notification_email');
    register_setting('rovestead_chatbot', 'rovestead_notifications_enabled');
    register_setting('rovestead_chatbot', 'rovestead_featured_enabled');
    register_setting('rovestead_chatbot', 'rovestead_featured_product_id');
    register_setting('rovestead_chatbot', 'rovestead_featured_pitch');

    add_settings_section(
        'rovestead_chatbot_section',
        'Email Notification Settings',
        'rovestead_settings_section_callback',
        'rovestead_chatbot'
    );

    add_settings_field(
        'rovestead_notifications_enabled',
        'Enable Notifications',
        'rovestead_notifications_enabled_render',
        'rovestead_chatbot',
        'rovestead_chatbot_section'
    );

    add_settings_field(
        'rovestead_chatbot_secret',
        'Secret Key',
        'rovestead_secret_render',
        'rovestead_chatbot',
        'rovestead_chatbot_section'
    );

    add_settings_field(
        'rovestead_notification_email',
        'Notification Email',
        'rovestead_email_render',
        'rovestead_chatbot',
        'rovestead_chatbot_section'
    );

    add_settings_section(
        'rovestead_featured_section',
        'Featured Product Settings',
        'rovestead_featured_section_callback',
        'rovestead_chatbot'
    );

    add_settings_field(
        'rovestead_featured_enabled',
        'Enable Featured Product',
        'rovestead_featured_enabled_render',
        'rovestead_chatbot',
        'rovestead_featured_section'
    );

    add_settings_field(
        'rovestead_featured_product_id',
        'Product ID',
        'rovestead_featured_product_id_render',
        'rovestead_chatbot',
        'rovestead_featured_section'
    );

    add_settings_field(
        'rovestead_featured_pitch',
        'Product Pitch',
        'rovestead_featured_pitch_render',
        'rovestead_chatbot',
        'rovestead_featured_section'
    );
}

function rovestead_notifications_enabled_render() {
    $enabled = get_option('rovestead_notifications_enabled', '1');
    ?>
    <input type='checkbox' name='rovestead_notifications_enabled' <?php checked($enabled, '1'); ?> value='1'>
    <p class="description">Uncheck to temporarily disable all chatbot email notifications</p>
    <?php
}

function rovestead_secret_render() {
    $secret = get_option('rovestead_chatbot_secret');
    ?>
    <input type='text' name='rovestead_chatbot_secret' value='<?php echo esc_attr($secret); ?>' style='width: 100%; max-width: 500px;' placeholder='Paste the secret key from your backend .env file'>
    <p class="description">This must match the <code>CHATBOT_NOTIFICATION_SECRET</code> in your backend environment variables. Keep this secure!</p>
    <?php
}

function rovestead_email_render() {
    $email = get_option('rovestead_notification_email', get_option('admin_email'));
    ?>
    <input type='email' name='rovestead_notification_email' value='<?php echo esc_attr($email); ?>' style='width: 100%; max-width: 500px;'>
    <p class="description">Where should escalation and error notifications be sent? Defaults to your WordPress admin email.</p>
    <?php
}

function rovestead_settings_section_callback() {
    echo '<p>Configure email notifications for your Rovestead chatbot. When customers request to speak with a human or when errors occur, you\'ll receive an email alert.</p>';
}

function rovestead_featured_section_callback() {
    echo '<p>Configure a featured product that the chatbot will subtly mention toward the end of conversations. This is optional.</p>';
}

function rovestead_featured_enabled_render() {
    $enabled = get_option('rovestead_featured_enabled', '0');
    ?>
    <input type='checkbox' name='rovestead_featured_enabled' <?php checked($enabled, '1'); ?> value='1'>
    <p class="description">Enable the featured product feature</p>
    <?php
}

function rovestead_featured_product_id_render() {
    $product_id = get_option('rovestead_featured_product_id', '');
    $product_name = '';
    if ($product_id) {
        $product = get_post($product_id);
        if ($product) {
            $product_name = $product->post_title . ' (#' . $product_id . ')';
        }
    }
    $nonce = wp_create_nonce('rovestead_product_search');
    ?>
    <div style="position: relative; max-width: 500px;">
        <input type='text' id='rovestead_product_search' value='<?php echo esc_attr($product_name); ?>' style='width: 100%;' placeholder='Start typing a product name...' autocomplete='off'>
        <input type='hidden' name='rovestead_featured_product_id' id='rovestead_featured_product_id' value='<?php echo esc_attr($product_id); ?>'>
        <div id='rovestead_search_results' style='display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ddd; border-top:none; max-height:200px; overflow-y:auto; z-index:100; box-shadow:0 2px 4px rgba(0,0,0,0.1);'></div>
    </div>
    <?php if ($product_id && $product_name): ?>
        <p class="description">Currently selected: <strong><?php echo esc_html($product_name); ?></strong></p>
    <?php else: ?>
        <p class="description">Search for a product by name. The product ID will be saved automatically.</p>
    <?php endif; ?>
    <script>
    (function() {
        var searchInput = document.getElementById('rovestead_product_search');
        var hiddenInput = document.getElementById('rovestead_featured_product_id');
        var resultsDiv = document.getElementById('rovestead_search_results');
        var debounceTimer;

        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            var term = this.value.trim();
            if (term.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }
            debounceTimer = setTimeout(function() {
                var url = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=rovestead_search_products&nonce=<?php echo $nonce; ?>&term=' + encodeURIComponent(term);
                fetch(url)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.length) {
                            resultsDiv.innerHTML = '<div style="padding:8px 12px;color:#666;">No products found</div>';
                        } else {
                            resultsDiv.innerHTML = data.map(function(p) {
                                return '<div style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;" data-id="' + p.id + '" data-text="' + p.text.replace(/"/g, '&quot;') + '">' + p.text + '</div>';
                            }).join('');
                        }
                        resultsDiv.style.display = 'block';
                    });
            }, 300);
        });

        resultsDiv.addEventListener('click', function(e) {
            var item = e.target.closest('[data-id]');
            if (item) {
                hiddenInput.value = item.getAttribute('data-id');
                searchInput.value = item.getAttribute('data-text');
                resultsDiv.style.display = 'none';
            }
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
                resultsDiv.style.display = 'none';
            }
        });
    })();
    </script>
    <?php
}

function rovestead_featured_pitch_render() {
    $pitch = get_option('rovestead_featured_pitch', '');
    ?>
    <textarea name='rovestead_featured_pitch' rows='3' style='width: 100%; max-width: 500px;' placeholder='Our handcrafted leather duffle - perfect for weekend adventures'><?php echo esc_textarea($pitch); ?></textarea>
    <p class="description">A natural, conversational pitch for the product. The AI will mention this toward the end of conversations when appropriate.</p>
    <?php
}

function rovestead_options_page() {
    ?>
    <div class="wrap">
        <h1>Chatbot Email Notifications</h1>

        <?php
        // Handle test email
        if (isset($_POST['rovestead_send_test']) && check_admin_referer('rovestead_test_email')) {
            $test_result = rovestead_send_test_email();
            if ($test_result) {
                echo '<div class="notice notice-success"><p><strong>✓ Test email sent successfully!</strong> Check your inbox at ' . esc_html(get_option('rovestead_notification_email', get_option('admin_email'))) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p><strong>✗ Failed to send test email.</strong> Check your WordPress email configuration (WP Mail SMTP plugin recommended).</p></div>';
            }
        }
        ?>

        <form action='options.php' method='post'>
            <?php
            settings_fields('rovestead_chatbot');
            do_settings_sections('rovestead_chatbot');
            submit_button('Save Settings');
            ?>
        </form>

        <hr style="margin: 30px 0;">

        <h2>Test Email Notifications</h2>
        <p>Send a test email to verify your configuration is working correctly.</p>

        <form method='post'>
            <?php wp_nonce_field('rovestead_test_email'); ?>
            <input type='hidden' name='rovestead_send_test' value='1'>
            <?php submit_button('Send Test Email', 'secondary', 'submit', false); ?>
        </form>

        <hr style="margin: 30px 0;">

        <h2>API Endpoint</h2>
        <p>Your chatbot backend should send notifications to:</p>
        <code style="display: block; background: #f5f5f5; padding: 10px; margin: 10px 0;"><?php echo esc_url(rest_url('rovestead-chatbot/v1/notify')); ?></code>

        <h3>Status</h3>
        <table class="widefat" style="max-width: 600px;">
            <tr>
                <td><strong>Plugin Version:</strong></td>
                <td>1.1.0</td>
            </tr>
            <tr>
                <td><strong>Secret Key Configured:</strong></td>
                <td><?php echo get_option('rovestead_chatbot_secret') ? '✓ Yes' : '✗ No - Please configure'; ?></td>
            </tr>
            <tr>
                <td><strong>Notifications Enabled:</strong></td>
                <td><?php echo get_option('rovestead_notifications_enabled', '1') ? '✓ Yes' : '✗ Disabled'; ?></td>
            </tr>
            <tr>
                <td><strong>Notification Email:</strong></td>
                <td><?php echo esc_html(get_option('rovestead_notification_email', get_option('admin_email'))); ?></td>
            </tr>
            <tr>
                <td><strong>Featured Product Enabled:</strong></td>
                <td><?php echo get_option('rovestead_featured_enabled', '0') === '1' ? '✓ Yes' : '✗ Disabled'; ?></td>
            </tr>
            <tr>
                <td><strong>Featured Product ID:</strong></td>
                <td><?php echo esc_html(get_option('rovestead_featured_product_id', 'Not set')); ?></td>
            </tr>
        </table>
    </div>
    <?php
}

function rovestead_send_test_email() {
    $to = get_option('rovestead_notification_email', get_option('admin_email'));
    $subject = '🧪 Test Email from Rovestead Chatbot - ' . get_bloginfo('name');
    $body = "This is a test email from the Rovestead Chatbot plugin.\n\n";
    $body .= "If you're seeing this, your email notifications are configured correctly!\n\n";
    $body .= "Time: " . current_time('mysql') . "\n";
    $body .= "Site: " . get_bloginfo('name') . " (" . get_site_url() . ")\n\n";
    $body .= str_repeat("=", 50) . "\n";
    $body .= "Sent by Rovestead Chatbot Email Notifier v1.1.0\n";

    return wp_mail($to, $subject, $body);
}
