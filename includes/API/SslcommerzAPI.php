<?php

namespace SslcommerzFluentCart\API;

use FluentCart\Framework\Support\Arr;
use SslcommerzFluentCart\Settings\SslcommerzSettingsBase;

class SslcommerzAPI
{
    private $settings;

    public function __construct()
    {
        $this->settings = new SslcommerzSettingsBase();
    }

    /**
     * Validate payment via SSL Commerz validation API
     */
    public function validation($payId, $mode = 'live')
    {
        $keys = $this->settings->getApiKeys();

        $validationApi = 'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php';

        if ($mode !== 'live') {
            // API Endpoint (Sandbox/Test Environment):
            $validationApi = 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php';
        }

        $args = [
            'val_id' => $payId,
        ];

        $keys['api_path'] = $validationApi;

        return $this->makeApiCall($keys, $args, 'GET');
    }

    /**
     * Make API call to SSL Commerz
     */
    public function makeApiCall($keys, $args, $method = 'GET')
    {
        $args['store_id'] = $keys['store_id'];
        $args['store_passwd'] = $keys['store_password'];

        $requestArgs = [
            'method'  => $method,
            'timeout' => 30,
            'body'    => $args
        ];

        if ($method === 'POST') {
            $response = wp_remote_post($keys['api_path'], $requestArgs);
        } else {
            $response = wp_remote_get($keys['api_path'], $requestArgs);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        
        if ($statusCode !== 200) {
            return new \WP_Error(
                'sslcommerz_api_error',
                sprintf(__('SSL Commerz API error: HTTP %s', 'sslcommerz-for-fluent-cart'), $statusCode),
                ['status' => $statusCode]
            );
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(
                'sslcommerz_json_error',
                __('Invalid JSON response from SSL Commerz', 'sslcommerz-for-fluent-cart')
            );
        }

        return $decoded;
    }

    /**
     * Initialize transaction
     */
    public function initializeTransaction($data)
    {
        $keys = $this->settings->getApiKeys();
        $keys['api_path'] = $keys['api_path'] . '/gwprocess/v4/api.php';
        
        return $this->makeApiCall($keys, $data, 'POST');
    }
}

