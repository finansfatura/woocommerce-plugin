<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin HTTP client around wp_remote_post / wp_remote_get. Adds the
 * X-Api-Key + Idempotency-Key headers, JSON-encodes the body, and surfaces
 * upstream errors as WP_Error. Every issuance call also gets logged so a
 * merchant can debug failures without SSH access.
 */
class FF_Api_Client
{
    private string $base_url;
    private string $api_key;

    public function __construct()
    {
        $opts = get_option(FF_OPTION_KEY, []);
        $this->base_url = rtrim((string) ($opts['base_url'] ?? 'https://api.finansfatura.com'), '/');
        $this->api_key  = (string) ($opts['api_key'] ?? '');
    }

    public function is_configured(): bool
    {
        return $this->api_key !== '' && $this->base_url !== '';
    }

    public function health()
    {
        $url = $this->base_url . '/v1/invoicing/health';
        $resp = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => $this->default_headers(),
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('ff_health_failed', sprintf(__('Sağlık kontrolü %d döndü', 'finansfatura-woo'), $code));
        }
        return json_decode(wp_remote_retrieve_body($resp), true);
    }

    /**
     * Issue a single invoice. Returns the parsed JSON body on success, WP_Error
     * on failure. Caller is responsible for logging + retry scheduling.
     */
    public function issue(array $payload, string $idempotency_key)
    {
        if (!$this->is_configured()) {
            return new WP_Error('ff_not_configured', __('Finansfatura API anahtarı veya URL ayarlı değil.', 'finansfatura-woo'));
        }
        $url = $this->base_url . '/v1/invoicing/invoices';
        $body = wp_json_encode($payload);

        $resp = wp_remote_post($url, [
            'timeout' => 25,
            'headers' => array_merge($this->default_headers(), [
                'Content-Type'    => 'application/json',
                'Idempotency-Key' => $idempotency_key,
            ]),
            'body' => $body,
        ]);

        $log = [
            'payload'         => $body,
            'idempotency_key' => $idempotency_key,
        ];

        if (is_wp_error($resp)) {
            $log['error'] = $resp->get_error_message();
            FF_Logger::write($log);
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $log['status'] = $code;
        $log['body']   = $raw;

        if ($code < 200 || $code >= 300) {
            $msg = sprintf(__('Finansfatura %d döndü', 'finansfatura-woo'), $code);
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['message'])) {
                $msg .= ': ' . $decoded['message'];
            }
            FF_Logger::write($log);
            return new WP_Error('ff_issue_failed', $msg, ['status' => $code, 'response' => $raw]);
        }

        FF_Logger::write($log);
        return json_decode($raw, true);
    }

    /**
     * Download the rendered invoice (PDF/HTML/XML) for the given invoice id.
     * Returns ['body' => binary, 'content_type' => string, 'filename' => string]
     * on success or a WP_Error on failure.
     */
    public function download(string $invoice_id, string $format = 'pdf')
    {
        if (!$this->is_configured()) {
            return new WP_Error('ff_not_configured', __('Finansfatura API anahtarı veya URL ayarlı değil.', 'finansfatura-woo'));
        }
        $url = $this->base_url . '/v1/invoicing/invoices/' . rawurlencode($invoice_id) . '/download?format=' . rawurlencode($format);
        $resp = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => $this->default_headers(),
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('ff_download_failed', sprintf(__('Fatura indirilemedi (%d)', 'finansfatura-woo'), $code));
        }
        $disposition = (string) wp_remote_retrieve_header($resp, 'content-disposition');
        $filename = $invoice_id . '.' . $format;
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m)) {
            $filename = $m[1];
        }
        return [
            'body'         => wp_remote_retrieve_body($resp),
            'content_type' => (string) (wp_remote_retrieve_header($resp, 'content-type') ?: 'application/octet-stream'),
            'filename'     => $filename,
        ];
    }

    private function default_headers(): array
    {
        return [
            'Accept'    => 'application/json',
            'X-Api-Key' => $this->api_key,
            'User-Agent'=> 'finansfatura-woo/' . FF_PLUGIN_VERSION . '; ' . home_url(),
        ];
    }
}
