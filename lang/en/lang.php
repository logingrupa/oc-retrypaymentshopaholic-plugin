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
        'error_not_retryable' => 'This order cannot be retried for payment.',
        'error_no_gateway' => 'The selected payment method does not support online payment.',
    ],
];
