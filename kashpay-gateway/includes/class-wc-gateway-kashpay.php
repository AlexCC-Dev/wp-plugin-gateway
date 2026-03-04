<?php
if (!defined('ABSPATH')) exit;

class WC_Gateway_KashPay extends WC_Payment_Gateway {

  public function __construct() {
    $this->id                 = 'kashpay';
    $this->method_title       = 'KashPay';
    $this->method_description = 'Paga con KashPay (Link de pago) usando OrderReceiver.';
    $this->has_fields         = false;

    $this->supports = ['products'];

    $this->init_form_fields();
    $this->init_settings();

    $this->title       = $this->get_option('title');
    $this->description = $this->get_option('description');
    $this->enabled     = $this->get_option('enabled');

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

    add_action('woocommerce_api_wc_kashpay_return', [$this, 'handle_return']);
    add_action('woocommerce_api_wc_kashpay_webhook', [$this, 'handle_webhook']);
  }

  public function init_form_fields() {
    $this->form_fields = [
      'enabled' => [
        'title'   => 'Habilitar/Deshabilitar',
        'type'    => 'checkbox',
        'label'   => 'Habilitar KashPay',
        'default' => 'no',
      ],
      'title' => [
        'title'   => 'Título',
        'type'    => 'text',
        'default' => 'KashPay',
      ],
      'description' => [
        'title'   => 'Descripción',
        'type'    => 'textarea',
        'default' => 'Paga de forma segura con KashPay.',
      ],
      'base_url' => [
        'title'       => 'Base URL API',
        'type'        => 'text',
        'description' => 'Ej sandbox: https://sdbx-antares.kashplataforma.com',
        'default'     => 'https://sdbx-antares.kashplataforma.com',
      ],
      'sandbox' => [
        'title'       => 'Sandbox',
        'type'        => 'checkbox',
        'label'       => 'Modo sandbox (desactiva verificación SSL SOLO para pruebas)',
        'default'     => 'yes',
        'description' => 'En IONOS puede fallar SSL en sandbox. En producción debe estar en NO.',
      ],
      'bearer_token' => [
        'title'       => 'Bearer Token',
        'type'        => 'password',
        'description' => 'Token Bearer de KashPay/Onsigna',
        'default'     => '',
      ],
      'entity_i' => [
        'title'   => 'Entity-i',
        'type'    => 'text',
        'default' => 'com.onsigna',
      ],
      'sirio_id' => [
        'title'   => 'sirioID (comercio)',
        'type'    => 'text',
        'default' => '',
      ],
      'cashier_user' => [
        'title'   => 'user (cajero)',
        'type'    => 'text',
        'default' => '',
      ],
      'currency_code' => [
        'title'       => 'Currency (ISO num)',
        'type'        => 'text',
        'description' => 'MXN = 484',
        'default'     => '484',
      ],
      'payment_type' => [
        'title'   => 'paymentType',
        'type'    => 'select',
        'default' => '1',
        'options' => [
          '1' => '1 - COMPLETO',
          '2' => '2 - DIVIDIDO',
          '3' => '3 - MIXTO',
        ],
      ],
      'payment_method_id' => [
        'title'       => 'paymentMethodID',
        'type'        => 'text',
        'description' => 'Según tu comercio. En ejemplos aparece 5 (Tarjeta).',
        'default'     => '5',
      ],
      'expiration_minutes' => [
        'title'   => 'Expiración (minutos)',
        'type'    => 'number',
        'default' => 60,
      ],
      'debug' => [
        'title'   => 'Debug',
        'type'    => 'checkbox',
        'label'   => 'Escribir logs en WooCommerce Logs',
        'default' => 'no',
      ],
    ];
  }

  private function api(): KashPay_API {
    $debug   = ($this->get_option('debug') === 'yes');
    $sandbox = ($this->get_option('sandbox') === 'yes');

    return new KashPay_API(
      (string) $this->get_option('base_url'),
      (string) $this->get_option('bearer_token'),
      (string) $this->get_option('entity_i'),
      $debug,
      $sandbox
    );
  }

  private function log_info(string $msg): void {
    if ($this->get_option('debug') === 'yes' && function_exists('wc_get_logger')) {
      wc_get_logger()->info($msg, ['source' => 'kashpay']);
    }
  }

  private function log_error(string $msg): void {
    if ($this->get_option('debug') === 'yes' && function_exists('wc_get_logger')) {
      wc_get_logger()->error($msg, ['source' => 'kashpay']);
    }
  }

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
      wc_add_notice('No se pudo iniciar el pago (pedido inválido).', 'error');
      return ['result' => 'failure'];
    }

    $sirio_id = trim((string) $this->get_option('sirio_id'));
    $cashier  = trim((string) $this->get_option('cashier_user'));
    $token    = trim((string) $this->get_option('bearer_token'));

    if ($sirio_id === '' || $cashier === '' || $token === '') {
      wc_add_notice('KashPay no está configurado (sirioID/user/token).', 'error');
      return ['result' => 'failure'];
    }

    $existing = (string) $order->get_meta('_kashpay_order_id');
    if ($existing !== '') {
      $this->log_info('Existing _kashpay_order_id=' . $existing . ' - validating via GET');

      $check = $this->api()->get_order($existing);
      $this->log_info('Response get_order(existing): ' . wp_json_encode($check));

      if (!empty($check['ok']) && !empty($check['data']['success'])) {
        $pay_url = (string) $order->get_meta('_kashpay_pay_url');
        if ($pay_url !== '') {
          return ['result' => 'success', 'redirect' => $pay_url];
        }
      }

      $order->delete_meta_data('_kashpay_order_id');
      $order->delete_meta_data('_kashpay_pay_url');
      $order->delete_meta_data('_kashpay_status_id');
      $order->save();

      $this->log_info('Existing KashPay reference invalid. Meta cleared to regenerate.');
    }

    $total = (float) $order->get_total();
    if ($total <= 0) {
      wc_add_notice('Monto inválido para KashPay.', 'error');
      return ['result' => 'failure'];
    }

    $expiration_minutes = max(5, (int) $this->get_option('expiration_minutes'));
    $expiration_iso     = gmdate('Y-m-d\TH:i:s', time() + ($expiration_minutes * 60)); // UTC

    $return_url = add_query_arg([
      'wc-api'   => 'wc_kashpay_return',
      'order_id' => $order->get_id(),
      'key'      => $order->get_order_key(),
    ], home_url('/'));

    $payload = [
      'user' => $cashier,
      'amount' => round($total, 2),
      'sirioID' => $sirio_id,
      'paymentType' => (int) $this->get_option('payment_type'),
      'paymentMethod' => [
        'paymentMethodID' => (int) $this->get_option('payment_method_id'),
      ],
      'currency' => (string) $this->get_option('currency_code'),
      'retrievalReferenceCode' => $this->build_rrc($order),
      'orderType' => ['id' => 2],
      'notificationType' => ['notificationTypeID' => 1],
      'products' => $this->build_products($order),
      'customerInfo' => [
        'firstName'  => $order->get_billing_first_name() ?: 'Cliente',
        'lastName'   => $order->get_billing_last_name() ?: 'WooCommerce',
        'middleName' => '',
        'email'      => $order->get_billing_email() ?: '',
        'phone1'     => $order->get_billing_phone() ?: '',
      ],
      'payInfo' => [
        'unique'      => true,
        'reference'   => 'WC-' . $order->get_id(),
        'description' => 'Pedido WooCommerce #' . $order->get_id(),
        'expiration'  => $expiration_iso,
        'urlCallback' => $return_url,
        'urlImage'    => '',
      ],
      'otherAmount' => 0.0,
      'msi' => false,
      'tip' => false,
    ];

    $this->log_info('Payload create_order: ' . wp_json_encode($payload));

    $res = $this->api()->create_order($payload);

    $this->log_info('Response create_order: ' . wp_json_encode($res));

    if (!$res['ok'] || empty($res['data']['success'])) {
      $msg = 'No se pudo crear el link de pago.';

      if (!empty($res['error']) && stripos($res['error'], 'cURL error 60') !== false) {
        $msg = 'No se pudo conectar con KashPay (error SSL del servidor).';
      }

      if (!empty($res['data']['error'])) {
        $msg .= ' ' . wp_strip_all_tags(print_r($res['data']['error'], true));
      } elseif (!empty($res['data']['raw'])) {
        $msg .= ' Respuesta: ' . wp_strip_all_tags(substr((string)$res['data']['raw'], 0, 180));
      }

      $order->add_order_note('[KashPay] Error creando orden: ' . $msg);
      wc_add_notice($msg, 'error');
      return ['result' => 'failure'];
    }

    $data = $res['data'];

    $kash_order_id = $data['payOrderResponse']['order']['id'] ?? '';
    $pay_url       = $data['payOrderResponse']['payOrder'] ?? '';
    $form_url      = $data['payOrderResponse']['formUrl'] ?? '';

    if (!$kash_order_id || (!$pay_url && !$form_url)) {
      $order->add_order_note('[KashPay] Respuesta inesperada al crear orden.');
      wc_add_notice('Respuesta inesperada de KashPay (sin URL de pago).', 'error');
      return ['result' => 'failure'];
    }

    $redirect = $form_url ?: $pay_url;

    $order->update_meta_data('_kashpay_order_id', $kash_order_id);
    $order->update_meta_data('_kashpay_pay_url', $redirect);
    $order->update_meta_data('_kashpay_status_id', 13);
    $order->save();

    $order->update_status('pending', '[KashPay] Link de pago generado. Redirigiendo a KashPay.');

    return [
      'result'   => 'success',
      'redirect' => $redirect,
    ];
  }

  private function build_rrc(WC_Order $order): string {
    $base = 'WC' . $order->get_id() . '-' . time();
    $base = preg_replace('/[^A-Za-z0-9\-]/', '', $base);
    return substr($base, 0, 40);
  }

  private function build_products(WC_Order $order): array {
    $items = [];
    foreach ($order->get_items() as $item) {
      $name  = $item->get_name();
      $qty   = (int) $item->get_quantity();
      $total = (float) $item->get_total();
      $unit  = $qty > 0 ? round($total / $qty, 2) : round($total, 2);

      $items[] = [
        'description' => mb_substr($name, 0, 100),
        'category'    => 'woocommerce',
        'count'       => $qty,
        'price'       => $unit,
        'tax'         => (float) $item->get_total_tax(),
      ];
    }

    if (empty($items)) {
      $items[] = [
        'description' => 'Pedido WooCommerce',
        'category'    => 'woocommerce',
        'count'       => 1,
        'price'       => round((float) $order->get_total(), 2),
        'tax'         => (float) $order->get_total_tax(),
      ];
    }

    return $items;
  }

  public function handle_return() {
    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $key      = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';

    if (!$order_id) {
      wp_safe_redirect(wc_get_checkout_url());
      exit;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
      wp_safe_redirect(wc_get_checkout_url());
      exit;
    }

    if ($key && $order->get_order_key() !== $key) {
      $order->add_order_note('[KashPay] Return con order key inválida.');
      wp_safe_redirect(wc_get_checkout_url());
      exit;
    }

    $this->sync_order_status_from_kashpay($order);

    wp_safe_redirect($order->get_checkout_order_received_url());
    exit;
  }

  public function handle_webhook() {
    $raw  = file_get_contents('php://input');
    $json = json_decode($raw, true);

    $kash_order_id = '';
    if (is_array($json)) {
      $kash_order_id = $json['orderId'] ?? ($json['id'] ?? '');
    }

    if (!$kash_order_id) {
      status_header(200);
      echo 'ok';
      exit;
    }

    $orders = wc_get_orders([
      'limit'      => 1,
      'meta_key'   => '_kashpay_order_id',
      'meta_value' => sanitize_text_field($kash_order_id),
      'orderby'    => 'date',
      'order'      => 'DESC',
    ]);

    if (!empty($orders)) {
      $order = $orders[0];
      $this->sync_order_status_from_kashpay($order);
    }

    status_header(200);
    echo 'ok';
    exit;
  }

private function sync_order_status_from_kashpay(WC_Order $order): void {

    $kash_order_id = (string) $order->get_meta('_kashpay_order_id');
    if (!$kash_order_id) {
        $order->add_order_note('[KashPay] No hay _kashpay_order_id.');
        return;
    }

    if ($order->is_paid()) {
        return;
    }

    $attempts = 0;
    $status_id = 0;

    while ($attempts < 3) {

        $res = $this->api()->get_order($kash_order_id);

        if (!empty($res['ok']) && !empty($res['data']['success'])) {
            $remote = $res['data']['order'] ?? [];
            $status_id = (int) ($remote['status']['statusID'] ?? 0);

            if ($status_id === 14) {
                break;
            }
        }

        sleep(2);
        $attempts++;
    }

    if ($status_id === 14) {
        $order->payment_complete();
        $order->add_order_note('[KashPay] Pago confirmado (statusID=14).');

        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        return;
    }

    if ($status_id === 15) {
        $order->update_status('failed', '[KashPay] Orden expirada (statusID=15).');
        return;
    }

    if ($status_id === 17) {
        $order->update_status('on-hold', '[KashPay] Pago parcial (statusID=17).');
        return;
    }

    $order->add_order_note('[KashPay] Estado actual: ' . $status_id);
}
}