<?php
/**
 * Plugin Name: Enterprise Connected Site
 * Description: Provisions WordPress accounts from the internal portal and provides single sign-on through its OpenID Connect provider.
 * Version: 1.0.2
 * Author: JAS Enterprises
 */

if (!defined('ABSPATH')) exit;

define('ECS_VERSION', '1.0.2');
define('ECS_PLUGIN_FILE', __FILE__);
define('ECS_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once ECS_PLUGIN_DIR . 'includes/update-checker-bootstrap.php';

require_once ECS_PLUGIN_DIR . 'includes/class-ecs-plugin.php';

function ecs_boot(): void {
  (new ECS_Plugin())->init();
}
add_action('plugins_loaded', 'ecs_boot');
