<?php
if (!defined('ABSPATH')) {
    exit;
}

use Finansfatura\Client;
use Finansfatura\FinansfaturaException;

/**
 * WordPress adapter around the finansfatura/finansfatura package.
 *
 * The package owns the endpoints, the payload casing and the error taxonomy;
 * this class only does what WordPress insists on:
 *   - HTTP through wp_remote_request (proxies, SSL config, WP HTTP filters),
 *   - auth from the settings screen (API key or OAuth access token),
 *   - exceptions turned into WP_Error, carrying `retryable` so the caller
 *     knows whether a retry is worth scheduling.
 */
class FF_Api_Client
{
    private ?Client $client = null;
    private ?WP_Error $init_error = null;

    public function __construct()
    {
        try {
            $this->client = self::build();
        } catch (\Throwable $e) {
            $this->init_error = new WP_Error('ff_not_configured', $e->getMessage());
        }
    }

    public function is_configured(): bool
    {
        return $this->client !== null;
    }

    /**
     * Step 1 — the sale. Feeds turnover, current account and stock, and
     * survives a failed invoice attempt, so it always runs first.
     *
     * @return array|WP_Error
     */
    public function create_sale(array $order)
    {
        return $this->call(fn(Client $c) => $c->createOrder($order));
    }

    /**
     * Step 2 — the document. `$payload` carries the sale's transaction id so
     * the invoice is attached to it.
     *
     * @return array|WP_Error
     */
    public function issue(array $payload, string $idempotency_key)
    {
        return $this->call(fn(Client $c) => $c->issueInvoice($payload, $idempotency_key));
    }

    /**
     * Bulk status by our own order ids. This is where the invoice NUMBER
     * shows up — the issue response never carries it.
     *
     * @param array<int,string> $external_ids
     * @return array|WP_Error
     */
    public function order_status(array $external_ids)
    {
        return $this->call(fn(Client $c) => $c->orderStatus(FF_Invoice_Mapper::PROVIDER, $external_ids));
    }

    /** @return string|WP_Error raw document bytes */
    public function download(string $invoice_id, string $format = 'pdf')
    {
        return $this->call(fn(Client $c) => $c->download($invoice_id, $format));
    }

    /**
     * Cheapest call that proves key + scope + reachability, used by the
     * "Bağlantıyı test et" button.
     *
     * @return array|WP_Error
     */
    public function ping()
    {
        return $this->call(fn(Client $c) => $c->listInvoices(1, 1));
    }

    /**
     * @param callable(Client):mixed $fn
     * @return mixed|WP_Error
     */
    private function call(callable $fn)
    {
        if ($this->client === null) {
            return $this->init_error ?? new WP_Error('ff_not_configured', __('Finansfatura bağlantısı ayarlı değil.', 'finansfatura-woo'));
        }
        try {
            return $fn($this->client);
        } catch (FinansfaturaException $e) {
            return new WP_Error('ff_api_error', self::message($e), [
                'status'    => $e->status,
                'retryable' => $e->retryable,
                'body'      => $e->body,
            ]);
        } catch (\Throwable $e) {
            return new WP_Error('ff_api_error', $e->getMessage(), ['retryable' => false]);
        }
    }

    /** Prefer the API's own message; fall back to the exception text. */
    private static function message(FinansfaturaException $e): string
    {
        if (is_array($e->body)) {
            foreach (['message', 'error_description', 'error'] as $k) {
                if (!empty($e->body[$k]) && is_string($e->body[$k])) {
                    return $e->body[$k];
                }
            }
        }
        return $e->getMessage();
    }

    private static function build(): Client
    {
        $base = rtrim(FF_Settings::get('base_url', Client::DEFAULT_BASE_URL), '/');

        // OAuth mode refreshes the token here when it is about to expire; the
        // exception it throws lands in init_error like a missing API key.
        $auth = FF_Settings::get('auth_mode', 'api_key') === 'oauth'
            ? ['accessToken' => FF_OAuth::access_token()]
            : ['apiKey' => trim(FF_Settings::get('api_key'))];
        if (current($auth) === '') {
            throw new \RuntimeException(__('Finansfatura API anahtarı girilmedi.', 'finansfatura-woo'));
        }

        return new Client($auth + [
            'baseUrl'   => $base,
            'transport' => self::transport(),
        ]);
    }

    /**
     * WP HTTP API as the package's transport. WordPress routes proxies, pinned
     * CAs and request filters through wp_remote_*; the package's own curl
     * handle would miss all of it.
     *
     * @return callable(string,string,array<string,string>,?string):array{status:int,body:string}
     */
    public static function transport(): callable
    {
        return function (string $method, string $url, array $headers, ?string $body): array {
            $resp = wp_remote_request($url, [
                'method'  => $method,
                'timeout' => 30,
                'headers' => $headers,
                'body'    => $body,
            ]);
            if (is_wp_error($resp)) {
                // ProviderException is the package's retryable transport error.
                throw new \Finansfatura\ProviderException(0, null, $resp->get_error_message());
            }
            return [
                'status' => (int) wp_remote_retrieve_response_code($resp),
                'body'   => (string) wp_remote_retrieve_body($resp),
            ];
        };
    }
}
