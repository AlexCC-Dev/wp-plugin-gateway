<?php
/**
 * Plugin Name: PosPago Gateway for WooCommerce
 * Description: Pasarela PosPago para WooCommerce usando OrderReceiver.
 * Version: 1.0.0
 * Author: PosPago
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

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