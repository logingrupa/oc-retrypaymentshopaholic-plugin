<?php return [
    'plugin' => [
        'name' => 'RetrypaymentShopaholic',
        'description' => 'Atkārtot maksājumu neveiksmīgiem vai atceltiem pasūtījumiem',
    ],
    'component' => [
        'name' => 'Atkārtot maksājumu',
        'description' => 'Ļauj klientiem atkārtot maksājumu neveiksmīgiem pasūtījumiem',
        'heading' => 'Atkārtot maksājumu',
        'description_text' => 'Jūsu maksājums netika pabeigts. Jūs varat mēģināt vēlreiz ar to pašu vai citu maksājuma metodi.',
        'button' => 'Atkārtot maksājumu',
        'loading' => 'Lūdzu, uzgaidiet, kamēr tiek apstrādāts jūsu maksājums...',
        'error_not_retryable' => 'Šim pasūtījumam nevar atkārtot maksājumu.',
        'error_no_gateway' => 'Izvēlētajai maksājuma metodei nav pieejama tiešsaistes apmaksa.',
        'success' => 'Apmaksa saņemta, paldies.',
        'switched_to_offline' => 'Apmaksas veids nomainīts. Informāciju nosūtījām uz Jūsu e-pastu.',
        'canceled' => 'Pasūtījums atcelts.',
        'error_not_cancelable' => 'Šo pasūtījumu vairs nevar atcelt.',
        'error_payment_method' => 'Šis maksāšanas veids nav pieejams.',
        'cancel_button' => 'Atcelt pasūtījumu',
        'cancel_confirm' => 'Jā, atcelt pasūtījumu',
    ],
];
