<?php

namespace SslcommerzFluentCart;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;

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

        // SSL Commerz requires manual refund processing through their dashboard
        return new \WP_Error(
            'sslcommerz_refund_manual',
            __('SSL Commerz refunds must be processed manually through the SSL Commerz dashboard.', 'sslcommerz-for-fluent-cart')
        );
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

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('sslcommerz', new self());
    }
}

