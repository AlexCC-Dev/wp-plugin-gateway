<?php
if (!defined('ABSPATH')) exit;

class KashPay_API {

  // URLs del API (OrderReceiver)
  private const URL_PRODUCTION = 'https://api-antares.kashplataforma.com';
  private const URL_SANDBOX    = 'https://sdbx-antares.kashplataforma.com';

  // URLs de autenticación (AuthenticationService)
  private const AUTH_URL_PRODUCTION = 'https://polaris.kashplataforma.com/AuthenticationService/authenticate';
  private const AUTH_URL_SANDBOX    = 'http://sdbx-polaris.kashplataforma.com/AuthenticationService/authenticate';

  // Margen de seguridad: renovar el token 10 minutos antes de que expire
  private const TOKEN_RENEW_MARGIN = 600;

  private string $base_url;
  private string $bearer_token;
  private string $entity_i;
  private bool $debug;
  private bool $sandbox;

  // Credenciales de autenticación (solo producción)
  private string $auth_user;
  private string $auth_password;

  public function __construct(
    string $bearer_token,
    string $entity_i = 'com.onsigna',
    bool $debug = false,
    bool $sandbox = false,
    string $auth_user = '',
    string $auth_password = ''
  ) {
    $this->base_url      = $sandbox ? self::URL_SANDBOX : self::URL_PRODUCTION;
    $this->bearer_token  = trim($bearer_token);
    $this->entity_i      = trim($entity_i);
    $this->debug         = $debug;
    $this->sandbox       = $sandbox;
    $this->auth_user     = trim($auth_user);
    $this->auth_password = trim($auth_password);
  }

  public function get_base_url(): string {
    return $this->base_url;
  }

  public function is_sandbox(): bool {
    return $this->sandbox;
  }

  /**
   * Obtiene el Bearer Token vigente.
   * En producción: usa auto-generación si hay credenciales configuradas.
   * En sandbox: usa el token manual.
   */
  private function get_valid_token(): string {
    // En sandbox, o si no hay credenciales de auth, usar el token manual
    if ($this->sandbox || $this->auth_user === '' || $this->auth_password === '') {
      return $this->bearer_token;
    }

    // En producción con credenciales: verificar token cacheado
    $cached_token   = get_transient('kashpay_bearer_token');
    $cached_expires = (int) get_transient('kashpay_bearer_token_expires');

    // Si el token existe y aún no necesita renovación
    if ($cached_token && $cached_expires > (time() + self::TOKEN_RENEW_MARGIN)) {
      return $cached_token;
    }

    // Generar nuevo token
    $new_token = $this->authenticate();
    if ($new_token !== '') {
      return $new_token;
    }

    // Si falla la auto-generación, intentar con el token manual como respaldo
    $this->log_debug('[Auth] Auto-generación falló. Usando token manual como respaldo.');
    return $this->bearer_token;
  }

  /**
   * Llama al servicio AuthenticationService para obtener un nuevo token.
   */
  private function authenticate(): string {
    $auth_url = $this->sandbox ? self::AUTH_URL_SANDBOX : self::AUTH_URL_PRODUCTION;

    $body = [
      'user'     => $this->auth_user,
      'password' => $this->auth_password,
      'entity'   => $this->entity_i,
    ];

    $this->log_debug('[Auth] Solicitando nuevo token a ' . $auth_url);

    $res = wp_remote_post($auth_url, [
      'timeout'   => 15,
      'headers'   => ['Content-Type' => 'application/json'],
      'body'      => wp_json_encode($body),
      'sslverify' => !$this->sandbox,
    ]);

    if (is_wp_error($res)) {
      $this->log_debug('[Auth] Error WP: ' . $res->get_error_message());
      return '';
    }

    $status = (int) wp_remote_retrieve_response_code($res);
    $raw    = (string) wp_remote_retrieve_body($res);
    $data   = json_decode($raw, true);

    if ($status !== 200 || empty($data['success'])) {
      $this->log_debug('[Auth] Falló. status=' . $status . ' body=' . substr($raw, 0, 500));
      return '';
    }

    $token      = $data['authenticationResponse']['token'] ?? '';
    $expires_ms = (int) ($data['authenticationResponse']['expires'] ?? 0);

    if ($token === '') {
      $this->log_debug('[Auth] Token vacío en respuesta.');
      return '';
    }

    // Guardar token en transient de WordPress
    // expires_ms está en milisegundos, convertir a segundos
    $expires_seconds = max(300, (int) ($expires_ms / 1000));

    set_transient('kashpay_bearer_token', $token, $expires_seconds);
    set_transient('kashpay_bearer_token_expires', time() + $expires_seconds, $expires_seconds + 60);

    $this->log_debug('[Auth] Token generado OK. Expira en ' . $expires_seconds . ' segundos (' . round($expires_seconds / 3600, 1) . ' horas).');

    return $token;
  }

  private function headers(): array {
    return [
      'Authorization' => 'Bearer ' . $this->get_valid_token(),
      'Entity-i'      => $this->entity_i,
      'Content-Type'  => 'application/json',
      'Accept'        => 'application/json',
    ];
  }

  private function request(string $method, string $path, ?array $body = null): array {
    $url = $this->base_url . $path;

    $args = [
      'method'    => $method,
      'timeout'   => 25,
      'headers'   => $this->headers(),
      'sslverify' => !$this->sandbox,
    ];

    if ($body !== null) {
      $args['body'] = wp_json_encode($body);
    }

    $res = wp_remote_request($url, $args);

    if (is_wp_error($res)) {
      $this->log_debug('[KashPay_API] WP_Error ' . $method . ' ' . $url . ' msg=' . $res->get_error_message());
      return ['ok' => false, 'error' => $res->get_error_message(), 'status' => 0, 'data' => null];
    }

    $status = (int) wp_remote_retrieve_response_code($res);
    $raw    = (string) wp_remote_retrieve_body($res);

    $data = null;
    if ($raw !== '') {
      $decoded = json_decode($raw, true);
      $data = (json_last_error() === JSON_ERROR_NONE) ? $decoded : ['raw' => $raw];
    }

    // Si recibimos 401/403, el token puede haber expirado. Limpiar cache e intentar una vez más.
    if (($status === 401 || $status === 403) && !$this->sandbox && $this->auth_user !== '') {
      $this->log_debug('[KashPay_API] Token rechazado (' . $status . '). Renovando y reintentando...');

      delete_transient('kashpay_bearer_token');
      delete_transient('kashpay_bearer_token_expires');

      // Actualizar headers con nuevo token
      $args['headers'] = $this->headers();
      $res = wp_remote_request($url, $args);

      if (!is_wp_error($res)) {
        $status = (int) wp_remote_retrieve_response_code($res);
        $raw    = (string) wp_remote_retrieve_body($res);
        $decoded = json_decode($raw, true);
        $data = (json_last_error() === JSON_ERROR_NONE) ? $decoded : ['raw' => $raw];
      }
    }

    $this->log_debug('[KashPay_API] ' . $method . ' ' . $url . ' status=' . $status . ' body=' . substr($raw, 0, 2000));

    $ok = ($status >= 200 && $status < 300);
    return ['ok' => $ok, 'error' => $ok ? null : ('HTTP ' . $status), 'status' => $status, 'data' => $data];
  }

  private function log_debug(string $msg): void {
    if ($this->debug && function_exists('wc_get_logger')) {
      wc_get_logger()->info($msg, ['source' => 'kashpay']);
    }
  }

  /** POST /OrderReceiver/api/v1/order */
  public function create_order(array $payload): array {
    return $this->request('POST', '/OrderReceiver/api/v1/order', $payload);
  }

  /** GET /OrderReceiver/api/v1/order/{id} */
  public function get_order(string $order_id): array {
    $order_id = sanitize_text_field($order_id);
    return $this->request('GET', '/OrderReceiver/api/v1/order/' . rawurlencode($order_id), null);
  }

  /** PUT /OrderReceiver/api/v1/order */
  public function update_order(array $payload): array {
    return $this->request('PUT', '/OrderReceiver/api/v1/order', $payload);
  }
}