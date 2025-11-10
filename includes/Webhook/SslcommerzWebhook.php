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

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            $this->sendErrorResponse('Invalid data');
        }

        // Get required POST parameters
        $valId = Arr::get($data, 'val_id');
        $tranId = Arr::get($data, 'tran_id');
        $status = Arr::get($data, 'status');
        $amount = Arr::get($data, 'amount');
        $currency = Arr::get($data, 'currency');

        // Handle test/verification requests (when SSL Commerz tests the endpoint)
        // If no POST data at all, return 200 to indicate endpoint is reachable
        if (empty($data) || (!$valId && !$tranId && !$status)) {
            fluent_cart_add_log('SSL Commerz IPN Test', 'IPN endpoint test/verification request received', 'info');
            http_response_code(200);
            exit('SSL Commerz IPN endpoint is active');
        }
        

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
            ->where('uuid', $tranId)
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
            $this->sendErrorResponse('Transaction not found - endpoint is active');
        }

        // Get payment mode
        $settings = new SslcommerzSettingsBase();
        $mode = $settings->getMode();

        // Validate the transaction with SSL Commerz validation API
        $api = new SslcommerzAPI();
        $vendorTransaction = $api->validation($valId, $mode);

        if (is_wp_error($vendorTransaction)) {
            $this->sendErrorResponse($vendorTransaction->get_error_message());
        }

        if (empty($vendorTransaction)) {
            $this->sendErrorResponse('Validation failed');
        }

        // Security checks - validate transaction ID matches
        $validationTranId = Arr::get($vendorTransaction, 'tran_id');
        if ($validationTranId != $tranId) {
           $this->sendErrorResponse('Transaction id mismatch');
        }

        // Security check - validate amount (convert to cents for comparison)
        $validationAmount = Arr::get($vendorTransaction, 'currency_amount', 0);
        $validationCurrency = Arr::get($vendorTransaction, 'currency_type', '');
        
        $validationAmountCents = $this->convertToCents($validationAmount, $validationCurrency);
        
        // Allow small rounding differences (within 1 cent)
        $amountDifference = abs($transaction->total - $validationAmountCents);
        if ($amountDifference > 1) {
           $this->sendErrorResponse('Amount mismatch');
        }

        $this->handleStatus($transaction, $vendorTransaction, $data);

        $this->sendResponse();
    }

    /**
     * Handle transaction status from SSL Commerz
     */
    private function handleStatus($transaction, $vendorTransaction, $postData = [])
    {

        $status = Arr::get($vendorTransaction, 'status');
        
        $fluentCartStatus = $this->mapStatus($status);

        if ($fluentCartStatus === Status::TRANSACTION_SUCCEEDED) {
            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                $this->sendErrorResponse('Transaction already processed');
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

            $cardNo = Arr::get($vendorTransaction, 'card_no');
            if ($cardNo) {
                $updateData['card_last_4'] = substr($cardNo, -4);
            }

            $updateData['meta'] = array_merge($transaction->meta ?? [], [
                'sslcommerz_response' => $vendorTransaction,
                'sslcommerz_ipn_post' => $postData // Store original POST data for debugging
            ]);

            $transaction->update($updateData);

            
            fluent_cart_add_log('SSL Commerz Payment Success', 'Payment confirmed via IPN. Val ID: ' . $updateData['vendor_charge_id'], 'info', [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]);

            // Sync order status
            if ($transaction->order) {
                (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
            }
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

    public function sendResponse()
    {
        http_response_code(200);
        exit('IPN processed successfully');
    }

    public function sendErrorResponse($message)
    {
        http_response_code(400);
        exit($message);
    }
}

