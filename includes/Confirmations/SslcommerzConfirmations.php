<?php

namespace SslcommerzFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use SslcommerzFluentCart\API\SslcommerzAPI;
use SslcommerzFluentCart\Settings\SslcommerzSettingsBase;

class SslcommerzConfirmations
{
    public function init()
    {
        add_action('fluent_cart/before_render_redirect_page', [$this, 'maybeConfirmPayment'], 10, 1);
    }

    /**
     * Confirm payment on redirect page
     */
    public function maybeConfirmPayment($data)
    {
        $isReceipt = Arr::get($data, 'is_receipt', false);
        $method = Arr::get($data, 'method', '');

        if ($isReceipt || $method !== 'sslcommerz') {
            return;
        }

        $transactionHash = Arr::get($data, 'trx_hash', '');

        // Check if payment was successful from SSL Commerz redirect
        $status = Arr::get($_POST, 'status');
        $valId = Arr::get($_POST, 'val_id');

        if (!$status || !$transactionHash) {
            return;
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'sslcommerz')
            ->first();

        if (!$transaction || $transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        // If status is success and we have validation ID, verify with SSL Commerz
        if (($status === 'VALID' || $status === 'VALIDATED') && $valId) {
            $this->verifyAndConfirmPayment($transaction, $valId);
        }

        fluent_cart_add_log('SSL Commerz Payment Return', 'Customer returned from SSL Commerz. Status: ' . $status, 'info', [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);
    }

    /**
     * Verify and confirm payment
     */
    private function verifyAndConfirmPayment($transaction, $valId)
    {
        $settings = new SslcommerzSettingsBase();
        $mode = $settings->getMode();

        $api = new SslcommerzAPI();
        $vendorTransaction = $api->validation($valId, $mode);

        if (is_wp_error($vendorTransaction)) {
            return;
        }

        $status = Arr::get($vendorTransaction, 'status');
        
        if ($status !== 'VALID' && $status !== 'VALIDATED') {
            return;
        }

        // Update transaction
        $updateData = [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id' => $valId,
            'total'            => $this->convertToCents(
                Arr::get($vendorTransaction, 'currency_amount', 0),
                Arr::get($vendorTransaction, 'currency_type')
            ),
            'card_brand'       => Arr::get($vendorTransaction, 'card_brand'),
        ];

        $cardNo = Arr::get($vendorTransaction, 'card_no');
        if ($cardNo) {
            $updateData['card_last_4'] = substr($cardNo, -4);
        }

        $transaction->update($updateData);

        // Sync order status
        if ($transaction->order) {
            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
        }

        fluent_cart_add_log('SSL Commerz Payment Confirmation', 'Payment confirmed on return. Val ID: ' . $valId, 'info', [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);
    }

    /**
     * Convert amount to cents
     */
    private function convertToCents($amount, $currency)
    {
        $zeroDecimalCurrencies = ['JPY'];
        
        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return (int) round(floatval($amount));
        }

        return (int) round(floatval($amount) * 100);
    }
}

