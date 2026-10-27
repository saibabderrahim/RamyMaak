<?php

// Notifications push (lot 8, contrat §2) : abonnements et push groupés
// (EF-NOT-02), sans donnée commerciale (EF-NOT-03).
return [
    'grouped' => [
        // N-05 regroupées (I-109).
        'order_submitted' => [
            'title' => '{1} :count nouvelle commande à valider|[2,*] :count nouvelles commandes à valider',
            'body' => 'Ouvrez les commandes reçues.',
        ],
        // Autres groupes : le texte est le titre de la dernière notification.
        'default' => [
            'title' => '{1} :count nouvelle notification|[2,*] :count nouvelles notifications',
        ],
    ],
    'endpoint_host' => 'Le champ :attribute doit désigner un service push reconnu (adresse https).',
    'key_length' => 'Le champ :attribute doit être une clé base64url de :bytes octets.',
    'attributes' => [
        'endpoint' => 'adresse d\'abonnement',
        'keys' => 'clés de l\'abonnement',
        'keys.p256dh' => 'clé publique de l\'appareil',
        'keys.auth' => 'secret d\'authentification',
        'content_encoding' => 'encodage du contenu',
    ],
];
