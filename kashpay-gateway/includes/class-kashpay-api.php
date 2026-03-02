<?php
if (!defined('ABSPATH')) exit;

class KashPay_API {
  private string $base_url;
  private string $bearer_token;
  private string $entity_i;
  private bool $debug;

  public function __construct(string $base_url, string $bearer_token, string $entity_i = 'com.onsigna', bool $debug = false) {
    $this->base_url     = rtrim($base_url, '/');
    $this->bearer_token = trim($bearer_token);
    $this->entity_i     = trim($entity_i);
    $this->debug        = $debug;
  }

  private function headers(): array {
    return [
      'Authorization' => 'Bearer ' . $this->bearer_token,
      'Entity-i'      => $this->entity_i,
      'Content-Type'  => 'application/json',
      'Accept'        => 'application/json',
    ];
  }

  private function request(string $method, string $path, ?array $body = null): array {
    $url = $this->base_url . $path;

    $args = [
      'method'  => $method,
      'timeout' => 25,
      'headers' => $this->headers(),
    ];

    if ($body !== null) {
      $args['body'] = wp_json_encode($body);
    }

    $res = wp_remote_request($url, $args);

    if (is_wp_error($res)) {
      return ['ok' => false, 'error' => $res->get_error_message(), 'status' => 0, 'data' => null];
    }

    $status = (int) wp_remote_retrieve_response_code($res);
    $raw    = (string) wp_remote_retrieve_body($res);
    $data   = null;

    if ($raw !== '') {
      $decoded = json_decode($raw, true);
      $data = (json_last_error() === JSON_ERROR_NONE) ? $decoded : ['raw' => $raw];
    }

    if ($this->debug) {
      error_log('[KashPay_API] ' . $method . ' ' . $url . ' status=' . $status . ' body=' . substr($raw, 0, 1000));
    }

    $ok = ($status >= 200 && $status < 300);
    return ['ok' => $ok, 'error' => $ok ? null : ('HTTP ' . $status), 'status' => $status, 'data' => $data];
  }

  /**
   * Crea una orden/link de pago.
   * POST /OrderReceiver/api/v1/order
   */
  public function create_order(array $payload): array {
    return $this->request('POST', '/OrderReceiver/api/v1/order', $payload);
  }

  /**
   * Obtiene detalle de una orden.
   * GET /OrderReceiver/api/v1/order/{id}
   */
  public function get_order(string $order_id): array {
    $order_id = sanitize_text_field($order_id);
    return $this->request('GET', '/OrderReceiver/api/v1/order/' . rawurlencode($order_id), null);
  }

  /**
   * (Opcional) Actualiza/cancela una orden.
   * PUT /OrderReceiver/api/v1/order
   */
  public function update_order(array $payload): array {
    return $this->request('PUT', '/OrderReceiver/api/v1/order', $payload);
  }
}