<?php
if (!defined('ABSPATH')) exit;

class ECS_Plugin {
  const OPTION_SETTINGS = 'ecs_connected_site_settings';
  const OPTION_AUDIT = 'ecs_connected_site_provision_audit';
  const META_ISSUER = '_ecs_central_issuer';
  const META_SUBJECT = '_ecs_central_subject';
  const META_CENTRAL_ID = '_ecs_central_user_id';
  const META_STATUS = '_ecs_connected_site_status';
  const META_PROFILE_IMAGE_URL = '_ecs_central_profile_image_url';
  const META_PROFILE_DATA = '_ecs_central_profile_data';
  const STATE_PREFIX = 'ecs_sso_state_';

  public function init(): void {
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_post_ecs_save_settings', [$this, 'save_settings']);
    add_action('admin_post_ecs_pair_internal', [$this, 'pair_with_internal_site']);
    add_action('rest_api_init', [$this, 'register_routes']);
    add_action('admin_post_nopriv_ecs_sso_login', [$this, 'start_sso']);
    add_action('admin_post_ecs_sso_login', [$this, 'start_sso']);
    add_action('admin_post_nopriv_ecs_sso_callback', [$this, 'finish_sso']);
    add_action('admin_post_ecs_sso_callback', [$this, 'finish_sso']);
    add_action('login_form', [$this, 'render_login_link']);
    add_filter('get_avatar_data', [$this, 'filter_avatar_data'], 10, 2);
    add_filter('get_avatar_url', [$this, 'filter_avatar_url'], 10, 3);
    add_filter('wp_authenticate_user', [$this, 'reject_inactive_linked_user'], 20, 2);
  }

  public static function default_settings(): array {
    return [
      'internal_site_url' => '',
      'client_id' => '',
      'client_secret' => '',
      'site_key' => '',
    ];
  }

  public static function get_settings(): array {
    $settings = get_option(self::OPTION_SETTINGS, []);
    if (!is_array($settings)) $settings = [];
    $settings = array_merge(self::default_settings(), $settings);
    $settings['internal_site_url'] = untrailingslashit(esc_url_raw((string)$settings['internal_site_url']));
    $settings['client_id'] = sanitize_text_field((string)$settings['client_id']);
    $settings['client_secret'] = trim((string)$settings['client_secret']);
    $settings['site_key'] = trim((string)$settings['site_key']);
    return $settings;
  }

  public function admin_menu(): void {
    add_options_page('Enterprise Connected Site', 'Enterprise Connected Site', 'manage_options', 'ecs-connected-site', [$this, 'render_settings']);
  }

  public function render_settings(): void {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    $settings = self::get_settings();
    if (isset($_GET['ecs_saved'])) echo '<div class="notice notice-success"><p>Connected Site settings saved.</p></div>';
    if (isset($_GET['ecs_pairing_result'])) {
      $pairing_type = sanitize_key((string)wp_unslash($_GET['ecs_pairing_result']));
      $pairing_message = sanitize_text_field((string)wp_unslash($_GET['ecs_pairing_message'] ?? ''));
      if ($pairing_message !== '') echo '<div class="notice ' . ($pairing_type === 'success' ? 'notice-success' : 'notice-error') . '"><p>' . esc_html($pairing_message) . '</p></div>';
    }
    $callback = $this->callback_url();
    $launch = $this->login_url(home_url('/'));
    $provision = rest_url('eum-connected-site/v1/provision');
    echo '<div class="wrap"><h1>Enterprise Connected Site</h1><p>This plugin receives provisioned accounts from the internal portal and signs those users in through its OpenID Connect provider. Use the pairing section below to send the site details automatically, or copy these values for manual setup.</p>';
    echo '<h2>Connection details</h2><table class="form-table"><tbody>';
    $this->copy_row('One-click sign-in URL', $launch, 'Sent automatically during pairing; for manual setup, use this as the connected site’s Launch URL in the internal Site Manager.');
    $this->copy_row('Provisioning API URL', $provision, 'Sent automatically during pairing; for manual setup, use this as the connected site’s Provisioning API URL.');
    $this->copy_row('OIDC callback URL', $callback, 'Added automatically to the internal allowed redirect URI list during pairing; for manual setup, add this exact URL there.');
    echo '</tbody></table>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ecs_save_settings">';
    wp_nonce_field('ecs_save_settings', 'ecs_settings_nonce');
    echo '<h2>Connection</h2><table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="ecs-internal-url">Internal site URL</label></th><td><input id="ecs-internal-url" type="url" class="regular-text code" name="internal_site_url" required value="' . esc_attr($settings['internal_site_url']) . '" placeholder="https://internal.example.com"><p class="description">Enter the internal WordPress site URL, without a trailing slash.</p></td></tr>';
    echo '<tr><th scope="row"><label for="ecs-client-id">OIDC Client ID</label></th><td><input id="ecs-client-id" type="text" class="regular-text code" name="client_id" required value="' . esc_attr($settings['client_id']) . '"></td></tr>';
    echo '<tr><th scope="row"><label for="ecs-client-secret">OIDC Client Secret</label></th><td><input id="ecs-client-secret" type="password" class="large-text code" name="client_secret" value="" autocomplete="new-password" placeholder="' . ($settings['client_secret'] !== '' ? 'Leave blank to keep the current secret' : 'Enter the secret from internal SSO / OIDC settings') . '"></td></tr>';
    echo '<tr><th scope="row"><label for="ecs-site-key">Site provisioning key</label></th><td><input id="ecs-site-key" type="password" class="large-text code" name="site_key" value="" autocomplete="new-password" placeholder="' . ($settings['site_key'] !== '' ? 'Leave blank to keep the current key' : 'Paste the key generated by the internal Site Manager') . '"><p class="description">The guided Connect button below saves this key automatically; this field is for manual setup or key replacement.</p></td></tr>';
    echo '</tbody></table><p class="submit"><button type="submit" class="button button-primary">Save settings</button></p></form>';
    echo '<hr><h2>Connect to the internal Site Manager Admin</h2><p>Save the internal site URL and OIDC credentials above first. Generate a one-time code in <strong>Integrations → Site Manager Admin</strong>, paste it here, and this button will send the site details and callback URL and save the generated provisioning key automatically. You will still assign managers and access limits in the internal Site Manager.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ecs_pair_internal">';
    wp_nonce_field('ecs_pair_internal', 'ecs_pair_nonce');
    echo '<p><label for="ecs-pairing-code"><strong>One-time connection code</strong></label><br><input id="ecs-pairing-code" name="pairing_code" type="text" class="regular-text code" required maxlength="48" pattern="[a-fA-F0-9]{48}" autocomplete="off" placeholder="Paste the 48-character code"></p><p><button type="submit" class="button button-primary">Connect to internal portal</button></p></form>';
    echo '<p>Provisioned accounts use the least-privileged WordPress <code>Subscriber</code> role. Existing local accounts can be linked when their email exactly matches a provisioned internal user, but only if the local account has no existing central identity link. Accounts already linked to another identity are never claimed by email.</p></div>';
  }

  private function copy_row(string $label, string $value, string $description): void {
    echo '<tr><th scope="row">' . esc_html($label) . '</th><td><input type="text" readonly class="large-text code" onclick="this.select()" value="' . esc_attr($value) . '"><p class="description">' . esc_html($description) . '</p></td></tr>';
  }

  public function save_settings(): void {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    if (!isset($_POST['ecs_settings_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['ecs_settings_nonce']), 'ecs_save_settings')) wp_die('Invalid request.');
    $settings = self::get_settings();
    $url = untrailingslashit(esc_url_raw(trim((string)wp_unslash($_POST['internal_site_url'] ?? ''))));
    if (!$this->is_https_url($url)) wp_die('The internal site URL must use HTTPS and must not contain login details.');
    $settings['internal_site_url'] = $url;
    $settings['client_id'] = sanitize_text_field((string)wp_unslash($_POST['client_id'] ?? ''));
    if ($settings['client_id'] === '') wp_die('Enter the OIDC client ID from the internal portal.');
    $client_secret = trim((string)wp_unslash($_POST['client_secret'] ?? ''));
    $site_key = trim((string)wp_unslash($_POST['site_key'] ?? ''));
    if ($client_secret !== '') $settings['client_secret'] = $client_secret;
    if ($settings['client_secret'] === '') wp_die('Enter the OIDC client secret from the internal portal.');
    if ($site_key !== '') $settings['site_key'] = $site_key;
    update_option(self::OPTION_SETTINGS, $settings, false);
    wp_safe_redirect(add_query_arg(['page' => 'ecs-connected-site', 'ecs_saved' => '1'], admin_url('options-general.php')));
    exit;
  }

  public function pair_with_internal_site(): void {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    if (!isset($_POST['ecs_pair_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['ecs_pair_nonce']), 'ecs_pair_internal')) wp_die('Invalid request.');
    $settings = self::get_settings();
    $pairing_code = strtolower(trim(sanitize_text_field((string)wp_unslash($_POST['pairing_code'] ?? ''))));
    if ($settings['internal_site_url'] === '' || $settings['client_id'] === '' || $settings['client_secret'] === '') {
      $this->redirect_pairing_result('error', 'Save the internal site URL and OIDC client credentials before connecting.');
    }
    if (!preg_match('/^[a-f0-9]{48}$/', $pairing_code)) {
      $this->redirect_pairing_result('error', 'Enter the 48-character one-time code from the internal Site Manager.');
    }

    $payload = [
      'pairing_code' => $pairing_code,
      'oidc_client_id' => $settings['client_id'],
      'oidc_client_secret' => $settings['client_secret'],
      'site_name' => sanitize_text_field((string)get_bloginfo('name')),
      'site_url' => home_url('/'),
      'launch_url' => $this->login_url(home_url('/')),
      'provision_url' => rest_url('eum-connected-site/v1/provision'),
      'callback_url' => $this->callback_url(),
    ];
    $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    $request_args = [
      'timeout' => 25,
      'redirection' => 0,
      'headers' => $headers,
      'body' => wp_json_encode($payload),
    ];
    $base_url = trailingslashit($settings['internal_site_url']);
    $connect_url = $base_url . 'wp-json/eum-site-manager/v1/connect';
    $response = wp_remote_post($connect_url, $request_args);
    if (!is_wp_error($response) && (int)wp_remote_retrieve_response_code($response) === 404) {
      $plain_url = add_query_arg('rest_route', '/eum-site-manager/v1/connect', $base_url);
      $response = wp_remote_post($plain_url, $request_args);
    }
    if (is_wp_error($response)) {
      $this->redirect_pairing_result('error', 'Could not reach the internal portal: ' . $response->get_error_message());
    }

    $status = (int)wp_remote_retrieve_response_code($response);
    $body = json_decode((string)wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || !is_array($body) || empty($body['connected'])) {
      $message = is_array($body) ? sanitize_text_field((string)($body['message'] ?? '')) : '';
      if ($message === '') $message = 'The internal portal returned HTTP ' . $status . '. Confirm the pairing code is current and both plugins are up to date.';
      $this->redirect_pairing_result('error', $message);
    }

    $site_key = sanitize_text_field((string)($body['site_key'] ?? ''));
    if ($site_key === '') $this->redirect_pairing_result('error', 'The internal portal connected but did not return a provisioning key. Generate a new code and try again.');
    $settings['site_key'] = $site_key;
    update_option(self::OPTION_SETTINGS, $settings, false);
    $this->redirect_pairing_result('success', 'Connected successfully. The provisioning key is saved. Assign managers and access limits in the internal Site Manager.');
  }

  private function redirect_pairing_result(string $type, string $message): void {
    $url = add_query_arg([
      'page' => 'ecs-connected-site',
      'ecs_pairing_result' => $type,
      'ecs_pairing_message' => $message,
    ], admin_url('options-general.php'));
    wp_safe_redirect($url);
    exit;
  }

  public function register_routes(): void {
    register_rest_route('eum-connected-site/v1', '/provision', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [$this, 'handle_provision_request'],
    ]);
    register_rest_route('eum-connected-site/v1', '/deprovision', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [$this, 'handle_deprovision_request'],
    ]);
    register_rest_route('eum-connected-site/v1', '/connection-test', [
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => [$this, 'handle_connection_test_request'],
    ]);
  }

  public function handle_connection_test_request(WP_REST_Request $request) {
    $settings = self::get_settings();
    $provided_key = trim((string)$request->get_header('x-eum-site-key'));
    if ($settings['site_key'] === '' || $provided_key === '' || !hash_equals($settings['site_key'], $provided_key)) {
      return new WP_Error('eum_site_unauthorized', 'The site provisioning key is invalid.', ['status' => 401]);
    }
    $oidc_ready = $this->validate_internal_oidc($settings);
    if (is_wp_error($oidc_ready)) return $oidc_ready;
    return rest_ensure_response(['connected' => true, 'oidc_ready' => true, 'deprovision_ready' => true]);
  }

  public function handle_provision_request(WP_REST_Request $request) {
    $settings = self::get_settings();
    $provided_key = trim((string)$request->get_header('x-eum-site-key'));
    if ($settings['site_key'] === '' || $provided_key === '' || !hash_equals($settings['site_key'], $provided_key)) {
      return new WP_Error('eum_site_unauthorized', 'The site provisioning key is invalid.', ['status' => 401]);
    }
    $oidc_ready = $this->validate_internal_oidc($settings);
    if (is_wp_error($oidc_ready)) return $oidc_ready;
    $payload = $request->get_json_params();
    if (!is_array($payload)) $payload = [];
    $reason = sanitize_textarea_field((string)($payload['reason'] ?? ''));
    $users = isset($payload['users']) && is_array($payload['users']) ? $payload['users'] : [];
    if ($reason === '' || empty($users) || count($users) > 500) {
      return new WP_Error('eum_site_invalid_provision_request', 'A reason and between 1 and 500 users are required.', ['status' => 400]);
    }
    foreach ($users as $user_data) {
      if (!is_array($user_data)) return new WP_Error('eum_site_invalid_provision_request', 'Each provisioned user must be a valid account record.', ['status' => 400]);
    }

    $issuer = $this->issuer_url($settings);
    $results = [];
    foreach ($users as $user_data) {
      $results[] = $this->provision_one_user($user_data, $issuer);
    }
    $this->record_provision_audit(
      (int)($payload['manager_user_id'] ?? 0),
      sanitize_text_field((string)($payload['manager_sub'] ?? '')),
      $reason,
      $results
    );
    return new WP_REST_Response(['results' => $results], 200);
  }

  public function handle_deprovision_request(WP_REST_Request $request) {
    $settings = self::get_settings();
    $provided_key = trim((string)$request->get_header('x-eum-site-key'));
    if ($settings['site_key'] === '' || $provided_key === '' || !hash_equals($settings['site_key'], $provided_key)) {
      return new WP_Error('eum_site_unauthorized', 'The site provisioning key is invalid.', ['status' => 401]);
    }
    $oidc_ready = $this->validate_internal_oidc($settings);
    if (is_wp_error($oidc_ready)) return $oidc_ready;
    $payload = $request->get_json_params();
    if (!is_array($payload)) $payload = [];
    $reason = sanitize_textarea_field((string)($payload['reason'] ?? ''));
    $central_ids = isset($payload['central_user_ids']) && is_array($payload['central_user_ids']) ? $payload['central_user_ids'] : [];
    if ($reason === '' || empty($central_ids) || count($central_ids) > 500) {
      return new WP_Error('eum_site_invalid_deprovision_request', 'A reason and between 1 and 500 central user IDs are required.', ['status' => 400]);
    }
    foreach ($central_ids as $central_id) {
      if (!is_numeric($central_id) || (int)$central_id <= 0) {
        return new WP_Error('eum_site_invalid_deprovision_request', 'Each central user ID must be a positive integer.', ['status' => 400]);
      }
    }

    $issuer = $this->issuer_url($settings);
    $results = [];
    foreach (array_values(array_unique(array_map('intval', $central_ids))) as $central_id) {
      $results[] = $this->deprovision_one_user($central_id, $issuer);
    }
    $this->record_provision_audit(
      (int)($payload['manager_user_id'] ?? 0),
      sanitize_text_field((string)($payload['manager_sub'] ?? '')),
      $reason,
      $results
    );
    return new WP_REST_Response(['results' => $results], 200);
  }

  private function deprovision_one_user(int $central_id, string $issuer): array {
    $local_ids = get_users([
      'fields' => 'ids',
      'number' => 2,
      'meta_query' => [
        'relation' => 'AND',
        ['key' => self::META_ISSUER, 'value' => $issuer, 'compare' => '='],
        ['key' => self::META_CENTRAL_ID, 'value' => $central_id, 'compare' => '='],
      ],
    ]);
    if (empty($local_ids)) return ['central_user_id' => $central_id, 'status' => 'not_found'];
    if (count((array)$local_ids) !== 1) {
      return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'More than one local account is linked to this identity; an administrator must resolve the duplicate.'];
    }

    $local_id = (int)$local_ids[0];
    $local_user = get_user_by('id', $local_id);
    if (!$local_user instanceof WP_User) return ['central_user_id' => $central_id, 'status' => 'not_found'];
    if (in_array(strtolower((string)$local_user->user_login), ['superadmin', 'josephsimpson'], true)) {
      return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'This protected account cannot be removed automatically.'];
    }
    if (is_multisite()) {
      if (is_super_admin($local_id)) {
        return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'A network super administrator cannot be removed automatically.'];
      }
      $blog_id = get_current_blog_id();
      if (!is_user_member_of_blog($local_id, $blog_id)) return ['central_user_id' => $central_id, 'status' => 'not_found'];
      $admins = get_users(['role' => 'administrator', 'fields' => 'ids', 'number' => 1, 'exclude' => [$local_id], 'blog_id' => $blog_id]);
      $reassign_id = !empty($admins) ? (int)$admins[0] : 0;
      if ($reassign_id <= 0) {
        return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'No other site administrator is available to receive this user’s content.'];
      }
      $removed = remove_user_from_blog($local_id, $blog_id, $reassign_id);
      if (is_wp_error($removed) || !$removed) {
        $message = is_wp_error($removed) ? $removed->get_error_message() : 'WordPress could not remove the user from this site.';
        return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => sanitize_text_field($message)];
      }
      return ['central_user_id' => $central_id, 'status' => 'deleted'];
    }

    if (user_can($local_id, 'manage_options')) {
      return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'A local site administrator cannot be deleted automatically.'];
    }
    $admins = get_users(['role' => 'administrator', 'fields' => 'ids', 'number' => 1, 'exclude' => [$local_id], 'blog_id' => get_current_blog_id()]);
    $reassign_id = !empty($admins) ? (int)$admins[0] : 0;
    if ($reassign_id <= 0) {
      return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'No other site administrator is available to receive this user’s content.'];
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    if (!function_exists('wp_delete_user') || !wp_delete_user($local_id, $reassign_id)) {
      return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'WordPress could not delete the linked user account.'];
    }
    return ['central_user_id' => $central_id, 'status' => 'deleted'];
  }

  private function provision_one_user(array $data, string $issuer): array {
    $central_id = (int)($data['central_user_id'] ?? 0);
    $subject = sanitize_text_field((string)($data['central_sub'] ?? ''));
    $incoming_issuer = esc_url_raw((string)($data['central_issuer'] ?? ''));
    $login = sanitize_user((string)($data['user_login'] ?? ''), true);
    if ($login === '' && $central_id > 0) $login = 'eum-user-' . $central_id;
    $email = sanitize_email((string)($data['user_email'] ?? ''));
    $display_name = sanitize_text_field((string)($data['display_name'] ?? ''));
    $first_name = sanitize_text_field((string)($data['first_name'] ?? ''));
    $last_name = sanitize_text_field((string)($data['last_name'] ?? ''));
    $nickname = sanitize_text_field((string)($data['nickname'] ?? ''));
    $description = sanitize_textarea_field((string)($data['description'] ?? ''));
    $user_url = esc_url_raw((string)($data['user_url'] ?? ''));
    $account_status = sanitize_key((string)($data['account_status'] ?? 'active'));
    $site_role = sanitize_key((string)($data['site_role'] ?? 'subscriber'));
    if (!in_array($site_role, ['subscriber', 'editor'], true)) {
      return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'The requested site role is not permitted.'];
    }
    if (!in_array($account_status, ['active', 'inactive'], true)) {
      return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'The internal account status is invalid.'];
    }
    if ($central_id <= 0 || $subject === '' || $incoming_issuer !== $issuer || $login === '' || ($email !== '' && !is_email($email))) {
      return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'The internal account data was incomplete or did not match the configured issuer.'];
    }

    $existing_ids = get_users([
      'fields' => 'ids',
      'number' => 2,
      'meta_query' => [
        'relation' => 'AND',
        ['key' => self::META_ISSUER, 'value' => $issuer, 'compare' => '='],
        ['key' => self::META_SUBJECT, 'value' => $subject, 'compare' => '='],
      ],
    ]);
    // The portal can be configured to use a different OIDC subject source later.
    // A matching issuer + central user ID safely lets us refresh that link.
    if (empty($existing_ids)) {
      $existing_ids = get_users([
        'fields' => 'ids',
        'number' => 2,
        'meta_query' => [
          'relation' => 'AND',
          ['key' => self::META_ISSUER, 'value' => $issuer, 'compare' => '='],
          ['key' => self::META_CENTRAL_ID, 'value' => $central_id, 'compare' => '='],
        ],
      ]);
    }
    $existing_id = !empty($existing_ids) ? (int)$existing_ids[0] : 0;
    if (count((array)$existing_ids) > 1) {
      return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'More than one local account is linked to this identity; an administrator must resolve the duplicate.'];
    }

    $mapped_by_email = false;
    if ($existing_id <= 0 && $email !== '') {
      $email_owner_id = (int)email_exists($email);
      if ($email_owner_id > 0) {
        $linked_issuer = get_user_meta($email_owner_id, self::META_ISSUER, true);
        $linked_subject = get_user_meta($email_owner_id, self::META_SUBJECT, true);
        $linked_central_id = (int)get_user_meta($email_owner_id, self::META_CENTRAL_ID, true);
        if (trim(is_scalar($linked_issuer) ? (string)$linked_issuer : '') !== ''
          || trim(is_scalar($linked_subject) ? (string)$linked_subject : '') !== ''
          || $linked_central_id > 0) {
          return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'The matching email belongs to a local account already linked to a central identity.'];
        }
        $existing_id = $email_owner_id;
        $mapped_by_email = true;
      }
    }

    if ($existing_id > 0) {
      $linked_central_id = (int)get_user_meta($existing_id, self::META_CENTRAL_ID, true);
      if ($linked_central_id > 0 && $linked_central_id !== $central_id) {
        return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'This local account is linked to a different internal account and must be reviewed by a site administrator.'];
      }
      if ($email !== '') {
        $email_owner = email_exists($email);
        if ($email_owner && (int)$email_owner !== $existing_id) {
          return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'The email address is already assigned to a different local account.'];
        }
      }
      $update = [
        'ID' => $existing_id,
        'display_name' => $display_name !== '' ? $display_name : $login,
        'nickname' => $nickname,
        'description' => $description,
        'user_url' => $user_url,
      ];
      if ($email !== '') $update['user_email'] = $email;
      $update['role'] = $site_role;
      $existing_user = get_user_by('id', $existing_id);
      if ($existing_user instanceof WP_User && !empty(array_diff((array)$existing_user->roles, ['subscriber', 'editor']))) {
        unset($update['role']);
      }
      $updated = wp_update_user($update);
      if (is_wp_error($updated)) return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => $updated->get_error_message()];
      update_user_meta($existing_id, self::META_CENTRAL_ID, $central_id);
      update_user_meta($existing_id, self::META_ISSUER, $issuer);
      update_user_meta($existing_id, self::META_SUBJECT, $subject);
      $this->set_account_status($existing_id, $account_status);
      update_user_meta($existing_id, 'first_name', $first_name);
      update_user_meta($existing_id, 'last_name', $last_name);
      $this->sync_profile_image_url($existing_id, $data, $issuer);
      $this->sync_profile_data($existing_id, $data);
      $this->sync_public_meta($existing_id, $data);
      if (isset($update['role'])) update_user_meta($existing_id, '_ecs_site_role', $site_role);
      return [
        'central_user_id' => $central_id,
        'local_user_id' => $existing_id,
        'status' => $mapped_by_email ? 'linked' : 'updated',
        'message' => $mapped_by_email ? 'Existing account linked by email and updated.' : 'Account updated.',
      ];
    }

    if ($email !== '' && email_exists($email)) {
      return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'A local account now uses this email but could not be safely linked. Review the account and try again.'];
    }
    $local_login = $login;
    if (username_exists($local_login)) {
      $local_login = sanitize_user(substr($login, 0, 45) . '-' . $central_id, true);
      if ($local_login === '' || username_exists($local_login)) {
        return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => 'The username conflicts with an existing local account.'];
      }
    }
    $new_user_id = wp_insert_user([
      'user_login' => $local_login,
      'user_pass' => wp_generate_password(64, true, true),
      'user_email' => $email,
      'display_name' => $display_name !== '' ? $display_name : $local_login,
      'nickname' => $nickname,
      'description' => $description,
      'user_url' => $user_url,
      'first_name' => $first_name,
      'last_name' => $last_name,
      'role' => $site_role,
    ]);
    if (is_wp_error($new_user_id)) return ['central_user_id' => $central_id, 'status' => 'failed', 'message' => $new_user_id->get_error_message()];

    update_user_meta((int)$new_user_id, self::META_ISSUER, $issuer);
    update_user_meta((int)$new_user_id, self::META_SUBJECT, $subject);
    update_user_meta((int)$new_user_id, self::META_CENTRAL_ID, $central_id);
    $this->set_account_status((int)$new_user_id, $account_status);
    update_user_meta((int)$new_user_id, '_ecs_site_role', $site_role);
    $this->sync_profile_image_url((int)$new_user_id, $data, $issuer);
    $this->sync_profile_data((int)$new_user_id, $data);
    $this->sync_public_meta((int)$new_user_id, $data);
    return ['central_user_id' => $central_id, 'local_user_id' => (int)$new_user_id, 'status' => 'created', 'message' => 'Account created.'];
  }

  private function sync_public_meta(int $user_id, array $data): void {
    if (!array_key_exists('public_meta', $data) || !is_array($data['public_meta'])) return;
    $allowed = ['public_nickname', 'public_jobtitle', 'public_division', 'public_labels'];
    foreach ($allowed as $key) {
      if (!array_key_exists($key, $data['public_meta']) || !is_scalar($data['public_meta'][$key])) continue;
      $value = sanitize_text_field((string)$data['public_meta'][$key]);
      if ($key === 'public_labels') {
        $labels = [];
        foreach (explode(',', $value) as $label) {
          $label = trim(sanitize_text_field($label));
          if ($label !== '') $labels[] = $label;
        }
        $value = implode(', ', array_values(array_unique($labels)));
      }
      if (function_exists('mb_substr')) $value = mb_substr($value, 0, 250);
      else $value = substr($value, 0, 250);
      if ($value === '') delete_user_meta($user_id, $key);
      else update_user_meta($user_id, $key, $value);
    }
  }

  private function sync_profile_data(int $user_id, array $data): void {
    if (!array_key_exists('profile_data', $data)) return;
    $incoming = is_array($data['profile_data']) ? $data['profile_data'] : [];
    $profile_data = [];
    foreach (array_slice($incoming, 0, 100, true) as $field_id => $value) {
      $field_id = strtolower((string)preg_replace('/[^a-zA-Z0-9:_-]/', '', (string)$field_id));
      if ($field_id === '' || !is_scalar($value)) continue;
      $value = sanitize_textarea_field((string)$value);
      if (function_exists('mb_substr')) $value = mb_substr($value, 0, 2000);
      else $value = substr($value, 0, 2000);
      if ($value !== '') $profile_data[$field_id] = $value;
    }
    update_user_meta($user_id, self::META_PROFILE_DATA, $profile_data);
  }

  private function set_account_status(int $user_id, string $status): void {
    $previous_status = (string)get_user_meta($user_id, self::META_STATUS, true);
    update_user_meta($user_id, self::META_STATUS, $status);
    if ($status === 'inactive' && $previous_status !== 'inactive' && function_exists('wp_destroy_all_sessions')) {
      wp_destroy_all_sessions($user_id);
    }
  }

  public function reject_inactive_linked_user($user, $password = '') {
    if (!$user instanceof WP_User) return $user;
    $issuer = trim((string)get_user_meta((int)$user->ID, self::META_ISSUER, true));
    if ($issuer !== '' && (string)get_user_meta((int)$user->ID, self::META_STATUS, true) !== 'active') {
      return new WP_Error('ecs_inactive_account', 'This account is inactive on the internal portal.');
    }
    return $user;
  }

  private function sync_profile_image_url(int $user_id, array $data, string $issuer): void {
    if (!array_key_exists('profile_image_url', $data)) return;
    $image_url = esc_url_raw((string)$data['profile_image_url']);
    $image_host = strtolower((string)wp_parse_url($image_url, PHP_URL_HOST));
    $issuer_host = strtolower((string)wp_parse_url($issuer, PHP_URL_HOST));
    $scheme = strtolower((string)wp_parse_url($image_url, PHP_URL_SCHEME));
    $has_credentials = wp_parse_url($image_url, PHP_URL_USER) !== null || wp_parse_url($image_url, PHP_URL_PASS) !== null;
    if ($image_url === '' || $scheme !== 'https' || $image_host === '' || $image_host !== $issuer_host || $has_credentials) {
      delete_user_meta($user_id, self::META_PROFILE_IMAGE_URL);
      return;
    }
    update_user_meta($user_id, self::META_PROFILE_IMAGE_URL, $image_url);
  }

  public function filter_avatar_url(string $url, $id_or_email, array $args): string {
    $profile_image_url = $this->synced_profile_image_url($id_or_email);
    return $profile_image_url !== '' ? $profile_image_url : $url;
  }

  public function filter_avatar_data(array $args, $id_or_email): array {
    $profile_image_url = $this->synced_profile_image_url($id_or_email);
    if ($profile_image_url !== '') {
      $args['url'] = $profile_image_url;
      $args['found_avatar'] = true;
    }
    return $args;
  }

  private function synced_profile_image_url($id_or_email): string {
    $user_id = 0;
    if ($id_or_email instanceof WP_User) {
      $user_id = (int)$id_or_email->ID;
    } elseif ($id_or_email instanceof WP_Comment) {
      $user_id = (int)$id_or_email->user_id;
      if ($user_id <= 0 && is_email((string)$id_or_email->comment_author_email)) {
        $user_id = (int)email_exists((string)$id_or_email->comment_author_email);
      }
    } elseif ($id_or_email instanceof WP_Post) {
      $user_id = (int)$id_or_email->post_author;
    } elseif (is_numeric($id_or_email)) {
      $user_id = absint($id_or_email);
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
      $user_id = (int)email_exists($id_or_email);
    }

    if ($user_id <= 0) return '';
    return esc_url((string)get_user_meta($user_id, self::META_PROFILE_IMAGE_URL, true));
  }

  private function record_provision_audit(int $manager_id, string $manager_sub, string $reason, array $results): void {
    $audit = get_option(self::OPTION_AUDIT, []);
    if (!is_array($audit)) $audit = [];
    $audit[] = [
      'time' => current_time('mysql'),
      'manager_user_id' => max(0, $manager_id),
      'manager_sub' => $manager_sub,
      'reason' => $reason,
      'results' => $results,
    ];
    update_option(self::OPTION_AUDIT, array_slice($audit, -300), false);
  }

  public function start_sso(): void {
    $settings = self::get_settings();
    if (!$this->settings_ready($settings)) wp_die('The connected site has not been configured for internal sign-in.');
    $discovery = $this->discovery_document($settings);
    if (is_wp_error($discovery)) wp_die('The internal sign-in provider is unavailable: ' . esc_html($discovery->get_error_message()));
    $state = $this->random_token(32);
    $nonce = $this->random_token(32);
    $verifier = $this->random_token(48);
    $redirect_to = isset($_REQUEST['redirect_to']) ? wp_validate_redirect((string)wp_unslash($_REQUEST['redirect_to']), home_url('/')) : home_url('/');
    if (wp_parse_url($redirect_to, PHP_URL_HOST) !== wp_parse_url(home_url('/'), PHP_URL_HOST)) $redirect_to = home_url('/');
    set_transient(self::STATE_PREFIX . $state, [
      'nonce' => $nonce,
      'verifier' => $verifier,
      'redirect_to' => $redirect_to,
      'created_at' => time(),
    ], 600);

    $authorize_url = add_query_arg([
      'eum_oidc' => 'authorize',
      'client_id' => $settings['client_id'],
      'redirect_uri' => $this->callback_url(),
      'response_type' => 'code',
      'scope' => 'openid profile email',
      'state' => $state,
      'nonce' => $nonce,
      'code_challenge' => $this->base64url_encode(hash('sha256', $verifier, true)),
      'code_challenge_method' => 'S256',
    ], (string)$discovery['authorization_endpoint']);
    // The discovery document was fetched over HTTPS and every endpoint was
    // verified to use the configured internal site's host.
    wp_redirect($authorize_url);
    exit;
  }

  public function finish_sso(): void {
    $state = isset($_GET['state']) ? sanitize_text_field((string)wp_unslash($_GET['state'])) : '';
    $payload = $state !== '' ? get_transient(self::STATE_PREFIX . $state) : false;
    if (!is_array($payload) || empty($payload['verifier']) || empty($payload['nonce'])) wp_die('The sign-in request expired or could not be verified. Please try again.');
    delete_transient(self::STATE_PREFIX . $state);
    if (!empty($_GET['error'])) wp_die('The internal sign-in was not completed: ' . esc_html(sanitize_text_field((string)wp_unslash($_GET['error']))));
    $code = isset($_GET['code']) ? sanitize_text_field((string)wp_unslash($_GET['code'])) : '';
    if ($code === '') wp_die('The internal site did not return a sign-in code.');

    $settings = self::get_settings();
    $discovery = $this->discovery_document($settings);
    if (is_wp_error($discovery)) wp_die('The internal sign-in provider is unavailable: ' . esc_html($discovery->get_error_message()));
    $token_response = wp_remote_post((string)$discovery['token_endpoint'], [
      'timeout' => 15,
      'redirection' => 0,
      'sslverify' => true,
      'headers' => [
        'Authorization' => 'Basic ' . base64_encode($settings['client_id'] . ':' . $settings['client_secret']),
        'Accept' => 'application/json',
      ],
      'body' => [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $this->callback_url(),
        'code_verifier' => (string)$payload['verifier'],
      ],
    ]);
    if (is_wp_error($token_response)) wp_die('The internal sign-in could not be reached. Please try again.');
    $token_data = json_decode((string)wp_remote_retrieve_body($token_response), true);
    if ((int)wp_remote_retrieve_response_code($token_response) !== 200 || !is_array($token_data) || empty($token_data['id_token'])) wp_die('The internal site returned an invalid sign-in response.');

    $claims = $this->verify_id_token((string)$token_data['id_token'], (string)$payload['nonce'], $settings, $discovery);
    if (is_wp_error($claims)) wp_die('The internal sign-in could not be verified: ' . esc_html($claims->get_error_message()));
    $issuer = (string)$discovery['issuer'];
    $user = $this->find_linked_user($issuer, (string)($claims['sub'] ?? ''));
    if (!$user instanceof WP_User || (string)get_user_meta((int)$user->ID, self::META_STATUS, true) !== 'active') {
      wp_die('This account has not been provisioned for this site. Ask a site manager to provision it first.', 'Site access not provisioned', ['response' => 403]);
    }

    wp_set_current_user((int)$user->ID);
    wp_set_auth_cookie((int)$user->ID, true, is_ssl());
    do_action('wp_login', (string)$user->user_login, $user);
    $redirect_to = isset($payload['redirect_to']) ? wp_validate_redirect((string)$payload['redirect_to'], home_url('/')) : home_url('/');
    if (wp_parse_url($redirect_to, PHP_URL_HOST) !== wp_parse_url(home_url('/'), PHP_URL_HOST)) $redirect_to = home_url('/');
    wp_safe_redirect($redirect_to);
    exit;
  }

  private function find_linked_user(string $issuer, string $subject) {
    if ($subject === '') return null;
    $ids = get_users([
      'fields' => 'ids',
      'number' => 2,
      'meta_query' => [
        'relation' => 'AND',
        ['key' => self::META_ISSUER, 'value' => $issuer, 'compare' => '='],
        ['key' => self::META_SUBJECT, 'value' => $subject, 'compare' => '='],
      ],
    ]);
    if (count((array)$ids) !== 1) return null;
    return get_user_by('id', (int)$ids[0]);
  }

  private function verify_id_token(string $jwt, string $expected_nonce, array $settings, array $discovery) {
    $segments = explode('.', $jwt);
    if (count($segments) !== 3) return new WP_Error('ecs_invalid_token', 'The identity token format is invalid.');
    $header = json_decode($this->base64url_decode($segments[0]), true);
    $claims = json_decode($this->base64url_decode($segments[1]), true);
    $signature = $this->base64url_decode($segments[2]);
    if (!is_array($header) || !is_array($claims) || $signature === '' || ($header['alg'] ?? '') !== 'RS256') return new WP_Error('ecs_invalid_token', 'The identity token could not be read.');
    if ((string)($claims['iss'] ?? '') !== (string)$discovery['issuer']) return new WP_Error('ecs_invalid_issuer', 'The identity token is from a different internal site.');
    $audience = $claims['aud'] ?? [];
    if (!is_array($audience)) $audience = [$audience];
    if (!in_array($settings['client_id'], array_map('strval', $audience), true)) return new WP_Error('ecs_invalid_audience', 'The identity token was issued for a different client.');
    if (!hash_equals($expected_nonce, (string)($claims['nonce'] ?? ''))) return new WP_Error('ecs_invalid_nonce', 'The sign-in nonce did not match.');
    $now = time();
    if ((int)($claims['exp'] ?? 0) < $now || (int)($claims['iat'] ?? 0) > $now + 60 || (int)($claims['iat'] ?? 0) <= 0 || (isset($claims['nbf']) && (int)$claims['nbf'] > $now + 60)) return new WP_Error('ecs_expired_token', 'The identity token has expired or has an invalid issue time.');
    if (count($audience) > 1 && (string)($claims['azp'] ?? '') !== $settings['client_id']) return new WP_Error('ecs_invalid_audience', 'The identity token authorized a different client.');
    if (empty($claims['sub']) || !is_string($claims['sub'])) return new WP_Error('ecs_missing_subject', 'The identity token has no subject.');

    $jwks_response = wp_remote_get((string)$discovery['jwks_uri'], ['timeout' => 12, 'redirection' => 0, 'sslverify' => true, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($jwks_response) || (int)wp_remote_retrieve_response_code($jwks_response) !== 200) return new WP_Error('ecs_jwks_error', 'The internal signing keys could not be fetched.');
    $jwks = json_decode((string)wp_remote_retrieve_body($jwks_response), true);
    if (!is_array($jwks) || empty($jwks['keys']) || !is_array($jwks['keys'])) return new WP_Error('ecs_jwks_error', 'The internal signing keys response is invalid.');
    $key = null;
    foreach ($jwks['keys'] as $candidate) {
      if (is_array($candidate) && (string)($candidate['kid'] ?? '') === (string)($header['kid'] ?? '') && ($candidate['kty'] ?? '') === 'RSA') {
        $key = $candidate;
        break;
      }
    }
    if (!$key || empty($key['n']) || empty($key['e'])) return new WP_Error('ecs_signing_key_missing', 'The matching internal signing key was not found.');
    $public_key = $this->jwk_rsa_public_key((string)$key['n'], (string)$key['e']);
    if ($public_key === '' || !function_exists('openssl_verify')) return new WP_Error('ecs_crypto_unavailable', 'OpenSSL is required to verify internal sign-in.');
    $verified = openssl_verify($segments[0] . '.' . $segments[1], $signature, $public_key, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) return new WP_Error('ecs_invalid_signature', 'The identity token signature is invalid.');
    return $claims;
  }

  private function jwk_rsa_public_key(string $modulus, string $exponent): string {
    $modulus = $this->base64url_decode($modulus);
    $exponent = $this->base64url_decode($exponent);
    if ($modulus === '' || $exponent === '') return '';
    $rsa = $this->der_sequence($this->der_integer($modulus) . $this->der_integer($exponent));
    $algorithm = $this->der_sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00");
    $subject_key = "\x03" . $this->der_length(strlen($rsa) + 1) . "\x00" . $rsa;
    $spki = $this->der_sequence($algorithm . $subject_key);
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
  }

  private function der_sequence(string $value): string {
    return "\x30" . $this->der_length(strlen($value)) . $value;
  }

  private function der_integer(string $value): string {
    $value = ltrim($value, "\x00");
    if ($value === '') $value = "\x00";
    if ((ord($value[0]) & 0x80) !== 0) $value = "\x00" . $value;
    return "\x02" . $this->der_length(strlen($value)) . $value;
  }

  private function der_length(int $length): string {
    if ($length < 128) return chr($length);
    $bytes = '';
    while ($length > 0) {
      $bytes = chr($length & 0xff) . $bytes;
      $length >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
  }

  public function render_login_link(): void {
    $settings = self::get_settings();
    if (!$this->settings_ready($settings)) return;
    $redirect = isset($_REQUEST['redirect_to']) ? wp_validate_redirect((string)wp_unslash($_REQUEST['redirect_to']), home_url('/')) : home_url('/');
    echo '<p style="text-align:center"><a class="button button-secondary" style="width:100%" href="' . esc_url($this->login_url($redirect)) . '">Sign in with the internal portal</a></p>';
  }

  private function login_url(string $redirect_to): string {
    return add_query_arg([
      'action' => 'ecs_sso_login',
      'redirect_to' => $redirect_to,
    ], admin_url('admin-post.php'));
  }

  private function callback_url(): string {
    return admin_url('admin-post.php?action=ecs_sso_callback');
  }

  private function issuer_url(array $settings): string {
    return add_query_arg('eum_oidc', 'issuer', trailingslashit((string)$settings['internal_site_url']));
  }

  private function validate_internal_oidc(array $settings) {
    if (!$this->settings_ready($settings)) {
      return new WP_Error('ecs_connection_incomplete', 'The internal site URL and OIDC client credentials must be saved before provisioning can work.', ['status' => 409]);
    }
    return $this->discovery_document($settings);
  }

  private function discovery_document(array $settings) {
    $discovery_url = add_query_arg('eum_oidc', 'discovery', trailingslashit((string)$settings['internal_site_url']));
    $response = wp_remote_get($discovery_url, ['timeout' => 12, 'redirection' => 0, 'sslverify' => true, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($response)) return new WP_Error('ecs_discovery_failed', 'Could not contact the internal site.');
    if ((int)wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('ecs_discovery_failed', 'The internal site did not return OIDC discovery data.');
    $document = json_decode((string)wp_remote_retrieve_body($response), true);
    if (!is_array($document)) return new WP_Error('ecs_discovery_invalid', 'The internal OIDC discovery response is invalid.');
    foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
      if (empty($document[$key]) || !$this->is_https_url((string)$document[$key])) return new WP_Error('ecs_discovery_invalid', 'The internal OIDC discovery response is missing secure endpoints.');
      $internal_parts = wp_parse_url((string)$settings['internal_site_url']);
      $endpoint_parts = wp_parse_url((string)$document[$key]);
      $internal_host = strtolower((string)($internal_parts['host'] ?? ''));
      $endpoint_host = strtolower((string)($endpoint_parts['host'] ?? ''));
      $internal_port = (int)($internal_parts['port'] ?? 443);
      $endpoint_port = (int)($endpoint_parts['port'] ?? 443);
      if ($internal_host === '' || $endpoint_host !== $internal_host || $endpoint_port !== $internal_port) return new WP_Error('ecs_discovery_invalid', 'An OIDC endpoint does not belong to the configured internal site.');
    }
    if ((string)$document['issuer'] !== $this->issuer_url($settings)) return new WP_Error('ecs_discovery_invalid', 'The OIDC issuer does not match the configured internal site.');
    return $document;
  }

  private function settings_ready(array $settings): bool {
    return $this->is_https_url($settings['internal_site_url']) && $settings['client_id'] !== '' && $settings['client_secret'] !== '';
  }

  private function is_https_url(string $url): bool {
    $parts = wp_parse_url($url);
    return is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https' && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass']);
  }

  private function random_token(int $bytes): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
  }

  private function base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  private function base64url_decode(string $value): string {
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    return $decoded === false ? '' : $decoded;
  }
}
