<?php

namespace SslcommerzFluentCart\Settings;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class SslcommerzSettingsBase extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_sslcommerz';

    public function __construct()
    {
        parent::__construct();
        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        $this->settings = apply_filters('sslcommerz_fc/sslcommerz_settings', $settings);
    }

    public static function getDefaults()
    {
        return [
            'is_active'           => 'no',
            'test_store_id'       => '',
            'test_store_secret' => '',
            'live_store_id'       => '',
            'live_store_secret' => '',
            'payment_mode'        => 'test',
            'checkout_type'       => 'hosted',
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        $settings = $this->settings;

        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $settings;
    }

    public function getMode()
    {
        // return store mode
        return (new StoreSettings)->get('order_mode');
    }

    public function getStorePassword($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            $password = $this->get('test_store_secret');
        } else {
            $password = $this->get('live_store_secret');
        }

        return Helper::decryptKey($password);
    }

    public function getStoreId($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return $this->get('test_store_id');
        } else {
            return $this->get('live_store_id');
        }
    }

    public function getApiKeys()
    {
        $mode = $this->getMode();
        
        if ($mode === 'test') {
            $apiPath = 'https://sandbox.sslcommerz.com';
        } else {
            $apiPath = 'https://securepay.sslcommerz.com';
        }

        return [
            'store_id'       => $this->getStoreId(),
            'store_password' => $this->getStorePassword(),
            'api_path'       => $apiPath
        ];
    }
}

