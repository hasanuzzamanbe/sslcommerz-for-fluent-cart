<?php

namespace SslcommerzFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use SslcommerzFluentCart\API\SslcommerzAPI;
use SslcommerzFluentCart\Settings\SslcommerzSettingsBase;

class SslcommerzWebhook
{
    public function init()
    {
        // Add any webhook initialization logic here
    }

    /**
     * Verify and process SSL Commerz IPN
     */
    public function verifyAndProcess()
    {
        $payId = Arr::get($_POST, 'val_id');
        
        if (!$payId) {
            fluent_cart_add_log('SSL Commerz IPN Error', 'No validation ID received', 'error');
            http_response_code(400);
            exit('No validation ID');
        }

        // Get payment mode
        $settings = new SslcommerzSettingsBase();
        $mode = $settings->getMode();

        // Validate the transaction with SSL Commerz
        $api = new SslcommerzAPI();
        $vendorTransaction = $api->validation($payId, $mode);

        if (is_wp_error($vendorTransaction)) {
            fluent_cart_add_log('SSL Commerz Validation Error', $vendorTransaction->get_error_message(), 'error');
            http_response_code(400);
            exit('Validation failed');
        }

        if (empty($vendorTransaction)) {
            http_response_code(400);
            exit('Empty validation response');
        }

        // Get the transaction reference (transaction ID)
        $reference = Arr::get($vendorTransaction, 'tran_id');
        
        if (!$reference) {
            http_response_code(400);
            exit('No transaction reference');
        }

        // Find the transaction in our database
        $transaction = OrderTransaction::query()
            ->where('id', $reference)
            ->where('payment_method', 'sslcommerz')
            ->first();

        if (!$transaction) {
            fluent_cart_add_log('SSL Commerz IPN', 'Transaction not found: ' . $reference, 'error');
            http_response_code(404);
            exit('Transaction not found');
        }

        // Process the transaction
        $this->handleStatus($transaction, $vendorTransaction);

        http_response_code(200);
        exit('IPN processed');
    }

    /**
     * Handle transaction status from SSL Commerz
     */
    private function handleStatus($transaction, $vendorTransaction)
    {
        // Check if already paid
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $status = Arr::get($vendorTransaction, 'status');
        
        // Map SSL Commerz status to FluentCart status
        $fluentCartStatus = $this->mapStatus($status);

        if ($fluentCartStatus !== Status::TRANSACTION_SUCCEEDED) {
            // Payment failed or invalid
            fluent_cart_add_log('SSL Commerz Payment Failed', 'Payment status: ' . $status, 'info', [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]);
            return;
        }

        // Payment successful - update transaction
        $updateData = [
            'status'           => $fluentCartStatus,
            'vendor_charge_id' => Arr::get($vendorTransaction, 'val_id'),
            'total'            => $this->convertToCents(
                Arr::get($vendorTransaction, 'currency_amount', 0),
                Arr::get($vendorTransaction, 'currency_type')
            ),
            'card_brand'       => Arr::get($vendorTransaction, 'card_brand'),
        ];

        // Add card last 4 digits if available
        $cardNo = Arr::get($vendorTransaction, 'card_no');
        if ($cardNo) {
            $updateData['card_last_4'] = substr($cardNo, -4);
        }

        // Store full vendor response in meta
        $updateData['meta'] = array_merge($transaction->meta ?? [], [
            'sslcommerz_response' => $vendorTransaction
        ]);

        $transaction->update($updateData);

        // Sync order status
        if ($transaction->order) {
            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
        }

        fluent_cart_add_log('SSL Commerz Payment Success', 'Payment confirmed via IPN. Val ID: ' . $updateData['vendor_charge_id'], 'info', [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);
    }

    /**
     * Map SSL Commerz status to FluentCart status
     */
    private function mapStatus($sslcommerzStatus)
    {
        $statusMap = [
            'VALID'     => Status::TRANSACTION_SUCCEEDED,
            'VALIDATED' => Status::TRANSACTION_SUCCEEDED,
            'FAILED'    => Status::TRANSACTION_FAILED,
            'CANCELLED' => Status::TRANSACTION_FAILED,
        ];

        return $statusMap[$sslcommerzStatus] ?? Status::TRANSACTION_FAILED;
    }

    /**
     * Convert amount to cents
     */
    private function convertToCents($amount, $currency)
    {
        // Zero decimal currencies (if any for SSL Commerz)
        $zeroDecimalCurrencies = ['JPY'];
        
        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return (int) round(floatval($amount));
        }

        return (int) round(floatval($amount) * 100);
    }
}

