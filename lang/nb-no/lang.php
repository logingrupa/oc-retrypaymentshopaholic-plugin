<?php return [
    'plugin' => [
        'name' => 'RetrypaymentShopaholic',
        'description' => 'Prøv betaling på nytt for mislykkede eller kansellerte bestillinger',
    ],
    'component' => [
        'name' => 'Prøv betaling på nytt',
        'description' => 'Lar kunder prøve betaling på nytt for mislykkede bestillinger',
        'heading' => 'Prøv betaling på nytt',
        'description_text' => 'Betalingen din ble ikke fullført. Du kan prøve igjen med samme eller en annen betalingsmetode.',
        'button' => 'Prøv betaling på nytt',
        'loading' => 'Vennligst vent mens betalingen din behandles...',
        'error_not_retryable' => 'Denne bestillingen kan ikke betales på nytt.',
        'error_no_gateway' => 'Den valgte betalingsmetoden støtter ikke nettbetaling.',
        'success' => 'Betaling mottatt, takk.',
        'switched_to_offline' => 'Betalingsmåten er endret. Vi har sendt detaljene på e-post.',
        'canceled' => 'Bestillingen er kansellert.',
        'error_not_cancelable' => 'Denne bestillingen kan ikke lenger kanselleres.',
        'error_payment_method' => 'Denne betalingsmåten er ikke tilgjengelig.',
        'cancel_button' => 'Kanseller bestillingen',
        'cancel_confirm' => 'Ja, kanseller bestillingen',
    ],
];
