<?php
if (!defined('ABSPATH')) exit;

function ecs_register_plugin_update_checker(): void {
  $config = require ECS_PLUGIN_DIR . 'includes/update-checker-config.php';
  if (!is_array($config)) return;
  $config = apply_filters('ecs_update_checker_config', $config, ECS_PLUGIN_FILE);
  $repository = trim((string)($config['repository'] ?? ''));
  if ($repository === '') return;

  $factory = 'YahnisElsts\\PluginUpdateChecker\\v6\\PucFactory';
  if (!class_exists($factory, false)) {
    require_once ECS_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
  }
  if (!class_exists($factory, false)) return;

  $checker = $factory::buildUpdateChecker($repository, ECS_PLUGIN_FILE, 'enterprise-connected-site');
  $branch = trim((string)($config['branch'] ?? ''));
  if ($branch !== '') $checker->setBranch($branch);

  $token_constant = (string)($config['token_constant'] ?? '');
  $token = $token_constant !== '' && defined($token_constant) ? (string)constant($token_constant) : '';
  $token = (string)apply_filters('ecs_update_checker_github_token', $token, ECS_PLUGIN_FILE);
  if ($token !== '') $checker->setAuthentication($token);
}

add_action('plugins_loaded', 'ecs_register_plugin_update_checker', 1);
