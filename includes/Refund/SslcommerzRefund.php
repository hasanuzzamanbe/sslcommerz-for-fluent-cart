<?php

namespace SslcommerzFluentCart\Refund;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class SslcommerzRefund
{
    /**
     * Create or update refund from IPN/Webhook
     * 
     * @param array $refundData The refund data to create/update
     * @param OrderTransaction $parentTransaction The parent transaction
     * @return OrderTransaction|null
     */
    public static function createOrUpdateIpnRefund($refundData, $parentTransaction)
    {
        $allRefunds = OrderTransaction::query()
            ->where('order_id', $refundData['order_id'])
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        if ($allRefunds->isEmpty()) {
            // This is the first refund for this order
            $createdRefund = OrderTransaction::query()->create($refundData);
            PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);
            return $createdRefund instanceof OrderTransaction ? $createdRefund : null;
        }

        $currentRefundVendorId = Arr::get($refundData, 'vendor_charge_id', '');

        $existingLocalRefund = null;
        foreach ($allRefunds as $refund) {
            if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                if ($refund->total != $refundData['total']) {
                    $refund->fill($refundData);
                    $refund->save();
                }
                // This refund already exists
                return $refund;
            }

            if (!$refund->vendor_charge_id) { // This is a local refund without vendor charge id
                $refundSslcommerzId = Arr::get($refund->meta, 'sslcommerz_refund_id', '');
                $isRefundMatched = $refundSslcommerzId == $currentRefundVendorId;

                // This is a local refund without vendor charge id, we will update it
                if ($refund->total == $refundData['total'] && $isRefundMatched) {
                    $existingLocalRefund = $refund;
                }
            }
        }

        if ($existingLocalRefund) {
            $existingLocalRefund->fill($refundData);
            $existingLocalRefund->save();
            PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $existingLocalRefund->total);
            return $existingLocalRefund;
        }

        $createdRefund = OrderTransaction::query()->create($refundData);
        PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);

        return $createdRefund;
    }
}

