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
        // Log incoming IPN for debugging
        fluent_cart_add_log('SSL Commerz IPN Received', 'IPN data received', 'info', [
            'post_data' => $_POST,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'
        ]);

        // Get required POST parameters
        $valId = Arr::get($_POST, 'val_id');
        $tranId = Arr::get($_POST, 'tran_id');
        $status = Arr::get($_POST, 'status');
        $amount = Arr::get($_POST, 'amount');
        $currency = Arr::get($_POST, 'currency');
        
        // Handle test/verification requests (when SSL Commerz tests the endpoint)
        // If no POST data at all, return 200 to indicate endpoint is reachable
        if (empty($_POST) || (!$valId && !$tranId && !$status)) {
            fluent_cart_add_log('SSL Commerz IPN Test', 'IPN endpoint test/verification request received', 'info');
            http_response_code(200);
            exit('SSL Commerz IPN endpoint is active');
        }
        
        // Validate required parameters from POST
        if (!$valId || !$tranId || !$status) {
            fluent_cart_add_log('SSL Commerz IPN Error', 'Missing required parameters (val_id, tran_id, or status)', 'error', [
                'received' => [
                    'val_id' => $valId,
                    'tran_id' => $tranId,
                    'status' => $status
                ]
            ]);
            http_response_code(400);
            exit('Missing required parameters');
        }

        // Find the transaction in our database first (security check)
        $transaction = OrderTransaction::query()
            ->where('id', $tranId)
            ->where('payment_method', 'sslcommerz')
            ->first();

        if (!$transaction) {
            fluent_cart_add_log('SSL Commerz IPN Warning', 'Transaction not found in database - may be a test request', 'warning', [
                'tran_id' => $tranId,
                'val_id' => $valId,
                'status' => $status
            ]);
            // Return 200 instead of 404 so SSL Commerz knows the endpoint is reachable
            // This handles test requests and invalid transaction IDs gracefully
            http_response_code(200);
            exit('Transaction not found - endpoint is active');
        }

        // Get payment mode
        $settings = new SslcommerzSettingsBase();
        $mode = $settings->getMode();

        // Validate the transaction with SSL Commerz validation API
        $api = new SslcommerzAPI();
        $vendorTransaction = $api->validation($valId, $mode);

        if (is_wp_error($vendorTransaction)) {
            fluent_cart_add_log('SSL Commerz Validation Error', $vendorTransaction->get_error_message(), 'error', [
                'transaction_id' => $tranId,
                'val_id' => $valId
            ]);
            http_response_code(400);
            exit('Validation failed');
        }

        if (empty($vendorTransaction)) {
            fluent_cart_add_log('SSL Commerz Validation Error', 'Empty validation response', 'error', [
                'transaction_id' => $tranId,
                'val_id' => $valId
            ]);
            http_response_code(400);
            exit('Empty validation response');
        }

        // Security checks - validate transaction ID matches
        $validationTranId = Arr::get($vendorTransaction, 'tran_id');
        if ($validationTranId != $tranId) {
            fluent_cart_add_log('SSL Commerz Security Error', 'Transaction ID mismatch', 'error', [
                'post_tran_id' => $tranId,
                'validation_tran_id' => $validationTranId
            ]);
            http_response_code(400);
            exit('Transaction ID mismatch');
        }

        // Security check - validate amount (convert to cents for comparison)
        $validationAmount = Arr::get($vendorTransaction, 'currency_amount', 0);
        $validationCurrency = Arr::get($vendorTransaction, 'currency_type', '');
        
        $validationAmountCents = $this->convertToCents($validationAmount, $validationCurrency);
        
        // Allow small rounding differences (within 1 cent)
        $amountDifference = abs($transaction->total - $validationAmountCents);
        if ($amountDifference > 1) {
            fluent_cart_add_log('SSL Commerz Security Error', 'Amount mismatch', 'error', [
                'transaction_amount' => $transaction->total,
                'validation_amount_cents' => $validationAmountCents,
                'difference' => $amountDifference
            ]);
            http_response_code(400);
            exit('Amount mismatch');
        }

        // Process the transaction with both POST data and validation response
        $this->handleStatus($transaction, $vendorTransaction, $_POST);

        http_response_code(200);
        exit('IPN processed successfully');
    }

    /**
     * Handle transaction status from SSL Commerz
     */
    private function handleStatus($transaction, $vendorTransaction, $postData = [])
    {
        // Get status from validation response (more reliable than POST)
        $status = Arr::get($vendorTransaction, 'status');
        
        // Map SSL Commerz status to FluentCart status
        $fluentCartStatus = $this->mapStatus($status);

        // Handle different statuses
        if ($fluentCartStatus === Status::TRANSACTION_SUCCEEDED) {
            // Check if already processed to avoid duplicate processing
            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                fluent_cart_add_log('SSL Commerz IPN', 'Transaction already processed', 'info', [
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

            // Store full vendor response and POST data in meta for reference
            $updateData['meta'] = array_merge($transaction->meta ?? [], [
                'sslcommerz_response' => $vendorTransaction,
                'sslcommerz_ipn_post' => $postData // Store original POST data for debugging
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
        } else {
            // Payment failed, cancelled, expired, or unattempted
            $statusText = $status ?: Arr::get($postData, 'status', 'UNKNOWN');
            
            // Only update if not already marked as failed
            if ($transaction->status !== Status::TRANSACTION_FAILED) {
                $transaction->update([
                    'status' => Status::TRANSACTION_FAILED,
                    'meta' => array_merge($transaction->meta ?? [], [
                        'sslcommerz_response' => $vendorTransaction,
                        'sslcommerz_ipn_post' => $postData,
                        'failure_reason' => $statusText
                    ])
                ]);
            }

            fluent_cart_add_log('SSL Commerz Payment Failed', 'Payment status: ' . $statusText, 'info', [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
                'status'      => $statusText
            ]);
        }
    }

    /**
     * Map SSL Commerz status to FluentCart status
     */
    private function mapStatus($sslcommerzStatus)
    {
        $statusMap = [
            'VALID'       => Status::TRANSACTION_SUCCEEDED,
            'VALIDATED'  => Status::TRANSACTION_SUCCEEDED,
            'FAILED'      => Status::TRANSACTION_FAILED,
            'CANCELLED'   => Status::TRANSACTION_FAILED,
            'EXPIRED'     => Status::TRANSACTION_FAILED,
            'UNATTEMPTED' => Status::TRANSACTION_FAILED,
            'INVALID_TRANSACTION' => Status::TRANSACTION_FAILED,
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

