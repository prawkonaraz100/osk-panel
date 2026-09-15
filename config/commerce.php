<?php

$pricingJson = env('COMMERCE_ORDER_CREATE_PRICING_JSON');
$pricing = null;

if (is_string($pricingJson) && trim($pricingJson) !== '') {
    $decoded = json_decode($pricingJson, true);
    if (is_array($decoded)) {
        $pricing = $decoded;
    }
}

return [
    'order_create' => [
        'pricing_by_catalog_code' => $pricing,
        'internal_exam_catalog_code' => env('COMMERCE_ORDER_CREATE_INTERNAL_EXAM_CATALOG_CODE'),
    ],
];
