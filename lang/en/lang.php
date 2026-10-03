<?php return [
    'plugin' => [
        'name' => 'RetrypaymentShopaholic',
        'description' => 'Retry payment for failed or cancelled orders',
    ],
    'component' => [
        'name' => 'Retry Payment',
        'description' => 'Allows customers to retry payment on failed orders',
        'heading' => 'Retry payment',
        'description_text' => 'Your payment was not completed. You can try again with the same or a different payment method.',
        'button' => 'Retry payment',
        'loading' => 'Please wait while your payment is being processed...',
        'error_not_retryable' => 'This order cannot be retried for payment.',
        'error_no_gateway' => 'The selected payment method does not support online payment.',
        'success' => 'Payment received, thank you.',
        'switched_to_offline' => 'Payment method changed. We sent the details to your email.',
        'canceled' => 'Your order is canceled.',
        'error_not_cancelable' => 'This order can no longer be canceled.',
        'error_payment_method' => 'This payment method is not available.',
        'cancel_button' => 'Cancel the order',
        'cancel_confirm' => 'Yes, cancel the order',
    ],
];
