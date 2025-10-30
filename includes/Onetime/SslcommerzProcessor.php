<?php

namespace SslcommerzFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use SslcommerzFluentCart\API\SslcommerzAPI;
use SslcommerzFluentCart\Settings\SslcommerzSettingsBase;

class SslcommerzProcessor
{
    /**
     * Handle single payment
     */
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;
        $billingAddress = $paymentInstance->order->billing_address;

        $settings = new SslcommerzSettingsBase();
        $keys = $settings->getApiKeys();

        if (empty($keys['store_id']) || empty($keys['store_password'])) {
            return new \WP_Error(
                'sslcommerz_config_error',
                __('SSL Commerz payment gateway is not properly configured.', 'sslcommerz-for-fluent-cart')
            );
        }

        // Prepare webhook URL
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=sslcommerz');

        // Prepare payment data for SSL Commerz
        $paymentData = [
            'total_amount'      => $this->formatAmount($transaction->total),
            'currency'          => strtoupper($transaction->currency),
            'tran_id'           => $transaction->id,
            'product_category'  => 'FluentCart Order',
            'product_profile'   => 'general',
            'product_name'      => $this->getProductName($order),
            'cus_name'          => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            'cus_email'         => $fcCustomer->email,
            'cus_phone'         => $fcCustomer->phone ?: 'Not provided',
            'cus_add1'          => $billingAddress->address_1 ?: 'Not provided',
            'cus_city'          => $billingAddress->city ?: 'Not provided',
            'cus_country'       => $billingAddress->country ?: 'BD',
            'cus_postcode'      => $billingAddress->zip ?: '0000',
            'success_url'       => Arr::get($paymentArgs, 'success_url'),
            'fail_url'          => Arr::get($paymentArgs, 'cancel_url'),
            'cancel_url'        => Arr::get($paymentArgs, 'cancel_url'),
            'ipn_url'           => $webhook_url,
            'shipping_method'   => 'NO',
        ];

        // Apply filters for customization
        $paymentData = apply_filters('sslcommerz_fc/payment_args', $paymentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        // Initialize payment with SSL Commerz
        $api = new SslcommerzAPI();
        $keys['api_path'] = $keys['api_path'] . '/gwprocess/v4/api.php';
        
        $response = $api->makeApiCall($keys, $paymentData, 'POST');

        if (is_wp_error($response)) {
            return $response;
        }

        // Check for failed response
        if (Arr::get($response, 'status') === 'FAILED') {
            return new \WP_Error(
                'sslcommerz_init_error',
                Arr::get($response, 'failedreason', __('Failed to initialize payment', 'sslcommerz-for-fluent-cart'))
            );
        }

        // Get the gateway URL
        $gatewayUrl = Arr::get($response, 'GatewayPageURL') ?: Arr::get($response, 'redirectGatewayURL');
        
        if (!$gatewayUrl) {
            return new \WP_Error(
                'sslcommerz_url_error',
                __('Unable to get payment URL from SSL Commerz', 'sslcommerz-for-fluent-cart')
            );
        }

        // Store session key if available
        $sessionKey = Arr::get($response, 'sessionkey');
        if ($sessionKey) {
            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], [
                    'sslcommerz_session_key' => $sessionKey
                ])
            ]);
        }

        $checkoutType = (new SslcommerzSettingsBase())->get('checkout_type');

        return [
            'status'       => 'success',
            'nextAction'   => 'sslcommerz',
            'actionName'   => $checkoutType === 'modal' ? 'modal' : 'redirect',
            'message'      => __('Redirecting to SSL Commerz payment page...', 'sslcommerz-for-fluent-cart'),
            'payment_args' => array_merge($paymentArgs, [
                'checkout_url'  => $gatewayUrl,
                'checkout_type' => $checkoutType,
                'session_key'  => $sessionKey
            ])
        ];
    }

    /**
     * Format amount for SSL Commerz (from cents to decimal)
     */
    private function formatAmount($amount)
    {
        return number_format($amount / 100, 2, '.', '');
    }

    /**
     * Get product name from order items
     */
    private function getProductName($order)
    {
        if ($order->order_items->isEmpty()) {
            return 'FluentCart Order #' . $order->id;
        }

        $itemNames = [];
        foreach ($order->order_items as $item) {
            $itemNames[] = $item->title;
        }

        $productName = implode(', ', array_slice($itemNames, 0, 3));
        
        if (count($itemNames) > 3) {
            $productName .= ' + ' . (count($itemNames) - 3) . ' more';
        }

        return $productName;
    }
}

