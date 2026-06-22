<?php
/**
 * Debug Stripe Keys - Check for mismatched test/live keys
 * 
 * Access: /wp-content/plugins/hmw-events/debug-stripe-keys.php
 */

require_once __DIR__ . '/../../../wp-load.php';

if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}

// Get all educators
$educators = get_users(['role' => 'educator']);

echo '<h1>Stripe Key Audit</h1>';
echo '<style>
    body { font-family: sans-serif; padding: 20px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #f5f5f5; }
    .test { color: orange; font-weight: bold; }
    .live { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .missing { color: #999; }
</style>';

echo '<table>';
echo '<tr>
    <th>Educator</th>
    <th>Payment Type</th>
    <th>Publishable Key</th>
    <th>Secret Key (first 20 chars)</th>
    <th>Status</th>
</tr>';

foreach ($educators as $educator) {
    $user_id = 'user_' . $educator->ID;
    
    $payment_type = get_field('educator_payment_type', $user_id);
    $publishable = get_field('educator_stripe_key', $user_id);
    $secret = get_field('educator_stripe_secret', $user_id);
    
    if ($payment_type !== 'stripe') {
        continue;
    }
    
    $pub_mode = 'unknown';
    $sec_mode = 'unknown';
    $status = '';
    
    if (!empty($publishable)) {
        if (strpos($publishable, 'pk_test_') === 0) {
            $pub_mode = 'test';
        } elseif (strpos($publishable, 'pk_live_') === 0) {
            $pub_mode = 'live';
        }
    }
    
    if (!empty($secret)) {
        // Try to decrypt if encrypted
        if (strlen($secret) > 100 && strpos($secret, 'def') === 0) {
            $decrypted = \HMWEvents\Helpers\Encryption::decrypt($secret);
            if ($decrypted !== false) {
                $secret = $decrypted;
            }
        }
        
        if (strpos($secret, 'sk_test_') === 0) {
            $sec_mode = 'test';
        } elseif (strpos($secret, 'sk_live_') === 0) {
            $sec_mode = 'live';
        }
    }
    
    // Check for mismatch
    if ($pub_mode !== $sec_mode) {
        $status = '<span class="error">MISMATCH!</span>';
    } elseif ($pub_mode === 'test' && $sec_mode === 'test') {
        $status = '<span class="test">Test Mode</span>';
    } elseif ($pub_mode === 'live' && $sec_mode === 'live') {
        $status = '<span class="live">Live Mode</span>';
    } else {
        $status = '<span class="error">Error</span>';
    }
    
    $pub_display = !empty($publishable) ? esc_html($publishable) : '<span class="missing">Missing</span>';
    $sec_display = !empty($secret) ? esc_html(substr($secret, 0, 20)) . '...' : '<span class="missing">Missing</span>';
    
    echo '<tr>';
    echo '<td>' . esc_html($educator->display_name) . '<br><small>' . esc_html($educator->user_email) . '</small></td>';
    echo '<td>' . esc_html($payment_type) . '</td>';
    echo '<td>' . $pub_display . '</td>';
    echo '<td>' . $sec_display . '</td>';
    echo '<td>' . $status . '</td>';
    echo '</tr>';
}

echo '</table>';

echo '<hr>';
echo '<h2>System Stripe Settings</h2>';

$options = get_option('hmw-events', []);
$mode = $options['hmwevents_stripe_mode'] ?? 'test';

echo '<p><strong>Current Mode:</strong> ' . esc_html($mode) . '</p>';

if ($mode === 'test') {
    $pub = $options['hmwevents_stripe_test_publishable_key'] ?? '';
    $sec = $options['hmwevents_stripe_test_secret_key'] ?? '';
} else {
    $pub = $options['hmwevents_stripe_live_publishable_key'] ?? '';
    $sec = $options['hmwevents_stripe_live_secret_key'] ?? '';
}

echo '<p><strong>System Publishable:</strong> ' . (!empty($pub) ? esc_html($pub) : '<span class="missing">Not set</span>') . '</p>';
echo '<p><strong>System Secret:</strong> ' . (!empty($sec) ? esc_html(substr($sec, 0, 20)) . '...' : '<span class="missing">Not set</span>') . '</p>';
