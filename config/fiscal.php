<?php

return [
    'pac' => [
        /*
         * Desactivado de forma predeterminada.
         *
         * Habilitarlo no elimina las restricciones
         * de sandbox de SwSapienStampingGateway.
         */
        'dispatch_enabled' => env(
            'FISCAL_PAC_DISPATCH_ENABLED',
            false
        ),
    ],
];
