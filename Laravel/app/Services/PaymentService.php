<?php

namespace App\Services;

use App\ObjectsPurchases;
use App\PaymentStatus;
use App\PayoneCreditcard;
use App\PayoneDebitor;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    function __construct()
    {
    }

    public function getItemsForNextInvoice($customerId)
    {
        return ObjectsPurchases::select('id', 'object_type', 'title', 'price', 'user_id', 'created_at')
            ->where('customer_id', '=', $customerId)
            ->where('payment_status_id', '=', PaymentStatus::TO_BILL)
            ->orderBy('created_at', 'asc')
            ->with(['user' => function($q)
            {
                $q->select('id', 'name', 'email');
            }])
            ->get();
    }

    public static function sumObjectPurchases($purchases)
    {
        $sum = 0;
        foreach ($purchases as $object){
            $sum += $object->price;
        }

        return $sum;
    }

    public function getSumOfNextInvoice($customerId)
    {
        return ObjectsPurchases::where('customer_id', '=', $customerId)
            ->where('payment_status_id', '=', PaymentStatus::TO_BILL)
            ->sum('price');
    }

    public function getActiveBillingInformation($customerId)
    {
        $cc = [];
        $debitor = [];

        $existingCreditcardResult = PayoneCreditcard::where('customer_id', $customerId)->get();
        if (!$existingCreditcardResult->isEmpty()){
            $cc = $existingCreditcardResult->first();
        }

        $existingDebitorResult = PayoneDebitor::where('customer_id', $customerId)->get();
        if (!$existingDebitorResult->isEmpty()){
            $debitor = $existingDebitorResult->first();
        };

        return ['cc' => $cc,
                'billingAddress' => $debitor];
    }

}