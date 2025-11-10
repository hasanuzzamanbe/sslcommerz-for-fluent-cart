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

        $this->checkCurrencySupport($transaction->currency);

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

        $category = $this->getProductCategory($order->order_items);

        // Prepare payment data for SSL Commerz
        $paymentData = [
            'total_amount'      => $this->formatAmount($transaction->total),
            'currency'          => strtoupper($transaction->currency),
            'tran_id'           => $transaction->uuid,
            'product_category'  => $category,
            'product_profile'   => 'general',
            'product_name'      => $this->getProductName($order),
            'cus_name'          => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            'cus_email'         => $fcCustomer->email,
            'cus_phone'         => $fcCustomer->phone ?: 'Not provided',
            'cus_add1'          => $billingAddress->address_1 ?: 'Not provided',
            'cus_city'          => $billingAddress->city ?: 'Not provided',
            'cus_country'       => $billingAddress->country ?: 'BD',
            'cus_postcode'      => $billingAddress->postcode ?: '0000',
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


        $api = new SslcommerzAPI();
        $keys['api_path'] = $keys['api_path'] . '/gwprocess/v4/api.php';
        
        $response = $api->makeApiCall($keys, $paymentData, 'POST');

        if (is_wp_error($response)) {
            return $response;
        }

        if (Arr::get($response, 'status') === 'FAILED') {
            return new \WP_Error(
                'sslcommerz_init_error',
                Arr::get($response, 'failedreason', __('Failed to initialize payment', 'sslcommerz-for-fluent-cart'))
            );
        }


        $gatewayUrl = Arr::get($response, 'GatewayPageURL') ?: Arr::get($response, 'redirectGatewayURL');
        
        if (!$gatewayUrl) {
            return new \WP_Error(
                'sslcommerz_url_error',
                __('Unable to get payment URL from SSL Commerz', 'sslcommerz-for-fluent-cart')
            );
        }

        $sessionKey = Arr::get($response, 'sessionkey');
        if ($sessionKey) {
            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], [
                    'sslcommerz_session_key' => $sessionKey
                ])
            ]);
        }


        $storeLogo = Arr::get($response, 'storeLogo', '');

        $checkoutType = (new SslcommerzSettingsBase())->get('checkout_type');
        $mode = (new SslcommerzSettingsBase())->getMode();

        return [
            'status'       => 'success',
            'nextAction'   => 'sslcommerz',
            'actionName'   => $checkoutType == 'modal' ? 'custom' : 'redirect',
            'message'      => __('Redirecting to SSL Commerz payment page...', 'sslcommerz-for-fluent-cart'),
            'payment_args' => array_merge($paymentArgs, [
                'checkout_url'    => $gatewayUrl,
                'checkout_type'   => $checkoutType,
                'payment_mode'    => $mode,
                'session_key'     => $sessionKey,
                'transaction_hash'  => $transaction->uuid,
                'order_hash'      => $order->uuid,
                'logo'            => $storeLogo
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

    public function checkCurrencySupport($currency)
    {

        if (!in_array(strtoupper($currency), self::getSslcommerzSupportedCurrency())) {
            return new \WP_Error(
                'sslcommerz_currency_error',
                __('SSL Commerz does not support the currency you are using!', 'sslcommerz-for-fluent-cart')
            );
        }

    }

    public static function getSslcommerzSupportedCurrency(): array
    {
        return ['BDT', 'USD', 'EUR', 'GBP', 'AUD', 'CAD'];
    }


    public function getProductCategory($orderItems)
    {

        $category = '';

        foreach ($orderItems as $item) {
            $categories = $item->product->categories;
            
            if (!empty($categories)) {
                return $categories[0]->name;
            }
        }

   

        return __('No specific Category', 'sslcommerz-for-fluent-cart');
    }
}

