<?php
/**
 * Plugin Name: KashPay Gateway for WooCommerce (Custom)
 * Description: Pasarela KashPay/Onsigna para WooCommerce usando OrderReceiver (Link de Pago).
 * Version: 0.1.0
 * Author: Coco Cabaret
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