<?php return [
    'plugin' => [
        'name' => 'RetrypaymentShopaholic',
        'description' => 'Pakartoti mokėjimą nepavykusiems arba atšauktiems užsakymams',
    ],
    'component' => [
        'name' => 'Pakartoti mokėjimą',
        'description' => 'Leidžia klientams pakartoti mokėjimą nepavykusiems užsakymams',
        'heading' => 'Pakartoti mokėjimą',
        'description_text' => 'Jūsų mokėjimas nebuvo užbaigtas. Galite bandyti dar kartą naudodami tą patį arba kitą mokėjimo būdą.',
        'button' => 'Pakartoti mokėjimą',
        'loading' => 'Prašome palaukti, kol jūsų mokėjimas apdorojamas...',
        'error_not_retryable' => 'Šio užsakymo mokėjimo pakartoti negalima.',
        'error_no_gateway' => 'Pasirinktas mokėjimo būdas nepalaiko internetinio mokėjimo.',
        'success' => 'Apmokėjimas gautas, ačiū.',
        'switched_to_offline' => 'Mokėjimo būdas pakeistas. Informaciją išsiuntėme jūsų el. paštu.',
        'canceled' => 'Užsakymas atšauktas.',
        'error_not_cancelable' => 'Šio užsakymo nebegalima atšaukti.',
        'error_payment_method' => 'Šis mokėjimo būdas neprieinamas.',
        'cancel_button' => 'Atšaukti užsakymą',
        'cancel_confirm' => 'Taip, atšaukti užsakymą',
    ],
];
