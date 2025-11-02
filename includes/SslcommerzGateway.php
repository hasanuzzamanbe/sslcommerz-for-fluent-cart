<?php

namespace SslcommerzFluentCart;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use SslcommerzFluentCart\API\SslcommerzAPI;

class SslcommerzGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'sslcommerz';

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook'
    ];

    public function __construct()
    {
        parent::__construct(
            new Settings\SslcommerzSettingsBase(), 
            null // No subscription support
        );
    }

    public function meta(): array
    {
        $logo = SSLCOMMERZ_FC_PLUGIN_URL . 'assets/images/sslcommerz-logo.svg';
        
        return [
            'title'              => __('SSL Commerz', 'sslcommerz-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'SSL Commerz',
            'admin_title'        => 'SSL Commerz',
            'description'        => __('Pay securely with SSL Commerz - Card, Mobile Banking, Internet Banking, and more', 'sslcommerz-for-fluent-cart'),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#0B9E48',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        // Initialize IPN handler
        (new Webhook\SslcommerzWebhook())->init();
        
        add_filter('fluent_cart/payment_methods/sslcommerz_settings', [$this, 'getSettings'], 10, 2);

        (new Confirmations\SslcommerzConfirmations())->init();

        // Register AJAX endpoint for modal checkout initialization
        add_action('wp_ajax_sslcommerz_init_modal', [$this, 'initModalPayment']);
        add_action('wp_ajax_nopriv_sslcommerz_init_modal', [$this, 'initModalPayment']);
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $paymentArgs = [
            'success_url' => $this->getSuccessUrl($paymentInstance->transaction),
            'cancel_url'  => $this->getCancelUrl(),
        ];

        return (new Onetime\SslcommerzProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo($data)
    {
        $this->checkCurrencySupport();

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'sslcommerz-for-fluent-cart'),
            'data'         => [],
            'payment_args' => [
                'checkout_type' => $this->settings->get('checkout_type')
            ],
        ], 200);
    }

    public function checkCurrencySupport()
    {
        $currency = CurrencySettings::get('currency');

        if (!in_array(strtoupper($currency), self::getSslcommerzSupportedCurrency())) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('SSL Commerz does not support the currency you are using!', 'sslcommerz-for-fluent-cart')
            ], 422);
        }
    }

    public static function getSslcommerzSupportedCurrency(): array
    {
        return [
            'BDT', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 
            'SGD', 'MYR', 'INR', 'JPY', 'CNY'
        ];
    }

    public function handleIPN(): void
    {
        (new Webhook\SslcommerzWebhook())->verifyAndProcess();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'sslcommerz-fluent-cart-checkout-handler',
                'src'    => SSLCOMMERZ_FC_PLUGIN_URL . 'assets/sslcommerz-checkout.js',
                'version' => SSLCOMMERZ_FC_VERSION
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_sslcommerz_data' => [
                'checkout_type' => $this->settings->get('checkout_type'),
                'translations' => [
                    'Processing payment...' => __('Processing payment...', 'sslcommerz-for-fluent-cart'),
                    'Pay Now' => __('Pay Now', 'sslcommerz-for-fluent-cart'),
                    'Place Order' => __('Place Order', 'sslcommerz-for-fluent-cart'),
                    'Redirecting to SSL Commerz...' => __('Redirecting to SSL Commerz...', 'sslcommerz-for-fluent-cart'),
                ]
            ]
        ];
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function getTransactionUrl($url, $data): string
    {
        $transaction = Arr::get($data, 'transaction', null);
        if (!$transaction) {
            return $this->settings->getMode() === 'live' 
                ? 'https://securepay.sslcommerz.com/manage/' 
                : 'https://sandbox.sslcommerz.com/manage/';
        }

        $baseUrl = $this->settings->getMode() === 'live' 
            ? 'https://securepay.sslcommerz.com/manage/' 
            : 'https://sandbox.sslcommerz.com/manage/';

        if ($transaction->status === 'refunded') {
            $parentTransaction = OrderTransaction::query()
                ->where('id', Arr::get($transaction->meta, 'parent_id'))
                ->first();
            if ($parentTransaction) {
                return $baseUrl;
            }
        }

        return $baseUrl;
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'sslcommerz_refund_error',
                __('Refund amount is required.', 'sslcommerz-for-fluent-cart')
            );
        }

        // Get vendor charge ID (val_id)
        $valId = $transaction->vendor_charge_id;
        if (!$valId) {
            return new \WP_Error(
                'sslcommerz_refund_error',
                __('Transaction validation ID is missing.', 'sslcommerz-for-fluent-cart')
            );
        }

        // Get payment mode
        $mode = $this->settings->getMode();
        
        // Initialize API instance
        $api = new SslcommerzAPI();

        // Get bank transaction ID from meta
        $sslcommerzResponse = Arr::get($transaction->meta, 'sslcommerz_response', []);
        $bankTranId = Arr::get($sslcommerzResponse, 'bank_tran_id');

        // If bank_tran_id is not in meta, fetch it via validation API
        if (!$bankTranId) {
            $validationResponse = $api->validation($valId, $mode);

            if (is_wp_error($validationResponse)) {
                return new \WP_Error(
                    'sslcommerz_refund_error',
                    sprintf(__('Failed to fetch transaction details: %s', 'sslcommerz-for-fluent-cart'), $validationResponse->get_error_message())
                );
            }

            $bankTranId = Arr::get($validationResponse, 'bank_tran_id');
            
            if (!$bankTranId) {
                return new \WP_Error(
                    'sslcommerz_refund_error',
                    __('Bank transaction ID not found. Unable to process refund.', 'sslcommerz-for-fluent-cart')
                );
            }
        }

        // Generate unique refund transaction ID
        $refundTransId = 'REF_' . $transaction->id . '_' . time();

        // Convert amount from cents to decimal
        $currency = $transaction->currency ?? 'BDT';
        $zeroDecimalCurrencies = ['JPY'];
        
        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            $refundAmountDecimal = floatval($amount);
        } else {
            $refundAmountDecimal = floatval($amount) / 100;
        }

        // Get refund remarks from args, with fallback
        $refundRemarks = Arr::get($args, 'reason', 'Refunded on Customer Request');
        if (empty($refundRemarks)) {
            $refundRemarks = 'Refunded on Customer Request';
        }

        // Limit remarks to 255 characters as per API requirement
        $refundRemarks = substr($refundRemarks, 0, 255);

        // Optional reference ID
        $refeId = Arr::get($args, 'refe_id', '');

        // Call refund API
        $refundResponse = $api->initiateRefund(
            $bankTranId,
            $refundTransId,
            $refundAmountDecimal,
            $refundRemarks,
            $mode,
            $refeId
        );

        if (is_wp_error($refundResponse)) {
            return $refundResponse;
        }

        // Check API connection status
        $apiConnect = Arr::get($refundResponse, 'APIConnect', '');
        
        if ($apiConnect !== 'DONE') {
            $errorReason = Arr::get($refundResponse, 'errorReason', '');
            $errorMessage = sprintf(
                __('SSL Commerz API connection failed: %s', 'sslcommerz-for-fluent-cart'),
                $apiConnect
            );
            
            if ($errorReason) {
                $errorMessage .= ' - ' . $errorReason;
            }
            
            return new \WP_Error(
                'sslcommerz_refund_error',
                $errorMessage
            );
        }

        // Check refund status
        $refundStatus = Arr::get($refundResponse, 'status', '');
        $refundRefId = Arr::get($refundResponse, 'refund_ref_id', '');

        if ($refundStatus === 'success' && $refundRefId) {
            // Refund initiated successfully
            return $refundRefId;
        } elseif ($refundStatus === 'processing') {
            // Refund already initiated - return the refund_ref_id if available
            if ($refundRefId) {
                return $refundRefId;
            }
            return new \WP_Error(
                'sslcommerz_refund_processing',
                __('Refund is already being processed.', 'sslcommerz-for-fluent-cart')
            );
        } else {
            // Refund failed
            $errorReason = Arr::get($refundResponse, 'errorReason', __('Refund request failed to initiate.', 'sslcommerz-for-fluent-cart'));
            return new \WP_Error(
                'sslcommerz_refund_failed',
                sprintf(__('Refund failed: %s', 'sslcommerz-for-fluent-cart'), $errorReason)
            );
        }
    }

    public function fields(): array
    {
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=sslcommerz');
        
        return [
            'notice' => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'sslcommerz-for-fluent-cart'),
                'type'  => 'notice'
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'sslcommerz-for-fluent-cart'),
                        'value'  => 'live',
                        'schema' => [
                            'live_store_id' => [
                                'value'       => '',
                                'label'       => __('Live Store ID', 'sslcommerz-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('Your live store ID', 'sslcommerz-for-fluent-cart'),
                            ],
                            'live_store_secret' => [
                                'value'       => '',
                                'label'       => __('Live Store Secret Key', 'sslcommerz-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('Your live store secret key', 'sslcommerz-for-fluent-cart'),
                            ],
                        ]
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'sslcommerz-for-fluent-cart'),
                        'value'  => 'test',
                        'schema' => [
                            'test_store_id' => [
                                'value'       => '',
                                'label'       => __('Test Store ID', 'sslcommerz-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('Your test store ID', 'sslcommerz-for-fluent-cart'),
                            ],
                            'test_store_secret' => [
                                'value'       => '',
                                'label'       => __('Test Store Password', 'sslcommerz-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('Your test store password', 'sslcommerz-for-fluent-cart'),
                            ],
                        ],
                    ],
                ]
            ],
            'checkout_type' => [
                'value'   => 'hosted',
                'label'   => __('Checkout Type', 'sslcommerz-for-fluent-cart'),
                'type'    => 'radio',
                'options' => [
                    'hosted' => __('Hosted Checkout (Redirect)', 'sslcommerz-for-fluent-cart'),
                    'modal'  => __('Modal Checkout (Popup)', 'sslcommerz-for-fluent-cart')
                ],
                'tooltip' => __('Choose how customers will complete their payment.', 'sslcommerz-for-fluent-cart')
            ],
            'webhook_info' => [
                'value' => sprintf(
                    '<div><p><b>%s</b><code class="copyable-content">%s</code></p><p>%s</p></div>',
                    __('IPN/Webhook URL: ', 'sslcommerz-for-fluent-cart'),
                    $webhook_url,
                    __('Configure this IPN URL in your SSL Commerz Dashboard to receive payment notifications.', 'sslcommerz-for-fluent-cart')
                ),
                'label' => __('IPN Configuration', 'sslcommerz-for-fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }

    public static function validateSettings($data): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        if ($mode == 'test') {
            if (empty(Arr::get($data, 'test_store_id')) || empty(Arr::get($data, 'test_store_secret'))) {
                return [
                    'test_store_id' => __('Please provide Test Store ID and Test Store Secret Key', 'sslcommerz-for-fluent-cart')
                ];
            }
        }

        if ($mode == 'live') {
            if (empty(Arr::get($data, 'live_store_id')) || empty(Arr::get($data, 'live_store_secret'))) {
                return [
                    'live_store_id' => __('Please provide Live Store ID and Live Store Password', 'sslcommerz-for-fluent-cart')
                ];
            }
        }

        return [];
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        if ($mode == 'test') {
            $data['test_store_secret'] = Helper::encryptKey($data['test_store_secret']);
        } else {
            $data['live_store_secret'] = Helper::encryptKey($data['live_store_secret']);
        }

        return $data;
    }

    /**
     * Initialize modal payment for SSL Commerz popup checkout
     * This endpoint is called by the SSL Commerz embed script
     */
    public function initModalPayment()
    {
        // Get order hash or transaction ID from request
        $orderHash = Arr::get($_REQUEST, 'order_hash', '');
        $transactionId = Arr::get($_REQUEST, 'transaction_id', '');
        
        if (!$orderHash && !$transactionId) {
            wp_send_json([
                'status' => 'fail',
                'data' => null,
                'message' => __('Order information not found.', 'sslcommerz-for-fluent-cart')
            ]);
        }

        // Get order by hash or transaction
        $order = null;
        $transaction = null;

        if ($orderHash) {
            $order = \FluentCart\App\Models\Order::query()
                ->where('uuid', $orderHash)
                ->first();
        }

        if ($transactionId) {
            $transaction = OrderTransaction::query()
                ->where('id', $transactionId)
                ->where('payment_method', 'sslcommerz')
                ->first();
            
            if ($transaction && !$order) {
                $order = $transaction->order;
            }
        }

        if (!$order || !$transaction) {
            wp_send_json([
                'status' => 'fail',
                'data' => null,
                'message' => __('Order or transaction not found.', 'sslcommerz-for-fluent-cart')
            ]);
        }

        // Use the processor to initiate payment
        $paymentInstance = new \FluentCart\App\Services\Payments\PaymentInstance($order);
        $paymentInstance->setTransaction($transaction);
        
        $processor = new Onetime\SslcommerzProcessor();
        $result = $processor->handleSinglePayment($paymentInstance, [
            'success_url' => $this->getSuccessUrl($transaction),
            'cancel_url' => $this->getCancelUrl(),
        ]);

        if (is_wp_error($result)) {
            wp_send_json([
                'status' => 'fail',
                'data' => null,
                'message' => $result->get_error_message()
            ]);
        }

        $gatewayUrl = Arr::get($result, 'payment_args.checkout_url');
        $storeLogo = Arr::get($result, 'payment_args.logo', '');

        if (!$gatewayUrl) {
            wp_send_json([
                'status' => 'fail',
                'data' => null,
                'message' => __('Failed to get payment URL.', 'sslcommerz-for-fluent-cart')
            ]);
        }

        // Return in format expected by SSL Commerz embed script
        wp_send_json([
            'status' => 'success',
            'data' => $gatewayUrl,
            'logo' => $storeLogo
        ]);
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('sslcommerz', new self());
    }
}

