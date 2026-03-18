<?php
/**
 * Plugin Name: PosPago Gateway for WooCommerce
 * Description: Pasarela PosPago para WooCommerce usando OrderReceiver.
 * Version: 1.1.0
 * Author: PosPago
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

// Registrar intervalo de cron personalizado
add_filter('cron_schedules', function (array $schedules): array {
  if (!isset($schedules['every_five_minutes'])) {
    $schedules['every_five_minutes'] = [
      'interval' => 300,
      'display'  => 'Cada 5 minutos',
    ];
  }
  return $schedules;
});

// Activación: programar cron
register_activation_hook(__FILE__, function () {
  if (!wp_next_scheduled('kashpay_cron_check_pending_orders')) {
    wp_schedule_event(time(), 'every_five_minutes', 'kashpay_cron_check_pending_orders');
  }
});

// Desactivación: limpiar cron y token cacheado
register_deactivation_hook(__FILE__, function () {
  wp_clear_scheduled_hook('kashpay_cron_check_pending_orders');
  delete_transient('kashpay_bearer_token');
  delete_transient('kashpay_bearer_token_expires');
});

// Cargar el gateway
add_action('plugins_loaded', function () {
  if (!class_exists('WC_Payment_Gateway')) {
    return;
  }

  require_once __DIR__ . '/includes/class-kashpay-api.php';
  require_once __DIR__ . '/includes/class-wc-gateway-kashpay.php';

  add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Gateway_KashPay';
    return $gateways;
  });
});