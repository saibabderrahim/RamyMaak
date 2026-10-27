<?php

// Centre de notifications (§7.8, EF-NOT-01) : contenu minimal, sans donnée
// commerciale (EF-NOT-03).
return [
    // N-01
    'quotas_published' => [
        'title' => 'Quotas du :day reçus — région :region',
        'body' => 'À répartir avant :time.',
    ],
    // N-02
    'quotas_updated' => [
        'title' => 'Quotas du :day modifiés — région :region',
        'body' => '{0} L\'ADV a mis à jour les quotas.|{1} L\'ADV a modifié :count quota.|[2,*] L\'ADV a modifié :count quotas.',
    ],
    // RG-A05
    'cutoff_extended' => [
        'title' => 'Heure limite prolongée — région :region',
        'validation_title' => 'Heure limite de validation prolongée — région :region',
        'body' => [
            'DISTRIBUTION' => 'Répartition jusqu\'à :time.',
            'ORDERING' => 'Commande jusqu\'à :time.',
            'VALIDATION' => 'Validez jusqu\'à :time.',
        ],
    ],
    // N-03 (clients)
    'distribution_published' => [
        'title' => 'Quotas du :day disponibles',
        'body' => 'Vos quotas du :day sont disponibles. Commandez avant :time.',
    ],
    // N-04 (clients impactés)
    'limits_updated' => [
        'title' => 'Quotas du :day mis à jour',
        'body' => 'Vos quotas du jour ont été mis à jour.',
    ],
    // RG-A05 (clients actifs de la région, répartition envoyée)
    'cutoff_extended_client' => [
        'title' => 'Heure limite de commande prolongée',
        'body' => 'Commandez jusqu\'à :time.',
    ],
    // N-05 (régionaux)
    'order_submitted' => [
        'title' => 'Nouvelle commande — :code :name',
        'body' => ':number à valider.',
    ],
    // N-06 (régionaux)
    'order_changed' => [
        'title' => [
            'updated' => 'Commande :number modifiée',
            'cancelled' => 'Commande :number annulée',
        ],
        'body' => [
            'updated' => ':name a modifié sa commande.',
            'cancelled' => ':name a annulé sa commande.',
        ],
    ],
    // N-07 (clients, décisions du lot 6)
    'order_decided' => [
        'title' => [
            'VALIDATED' => 'Commande :number validée',
            'REJECTED' => 'Commande :number rejetée',
            'CANCELLED' => 'Commande :number annulée',
        ],
        'body' => [
            'VALIDATED' => 'Votre commande :number est validée.',
            'ADJUSTED' => '{1} Votre commande :number est validée (1 ligne ajustée).|[2,*] Votre commande :number est validée (:count lignes ajustées).',
            'REJECTED' => 'Votre commande :number est rejetée.',
            'CANCELLED' => 'Votre commande :number est annulée.',
        ],
    ],
    // N-09 (clients, lot 6)
    'order_shipped' => [
        'title' => 'Commande :number expédiée',
        'body' => 'Votre commande :number a été expédiée.',
    ],
    // N-14 (clients)
    'order_expired' => [
        'title' => 'Commande :number expirée',
        'body' => 'Elle n\'a pas été validée avant la clôture de la journée.',
    ],
    // N-10 (régionaux, rappel du planificateur, lot 8)
    'distribution_reminder' => [
        'title' => 'Rappel : répartition du :day — :region',
        'body' => 'Envoyez la répartition avant :time.',
    ],
    // N-11 (clients sans commande, lot 8)
    'ordering_reminder' => [
        'title' => 'Rappel : commande avant :time',
        'body' => 'Vous n\'avez pas encore commandé pour le :day.',
    ],
    // N-12 (régionaux, lot 8)
    'validation_reminder' => [
        'title' => '{1} Rappel : :count commande à valider|[2,*] Rappel : :count commandes à valider',
        'body' => ':region : validez avant :time.',
    ],
    // N-13 (ADV et Admin, lot 8)
    'distribution_late' => [
        'title' => 'Répartition en retard — :region',
        'body' => 'La répartition du :day n\'est pas envoyée (limite :time).',
    ],
    // N-15 (compte concerné, lot 8)
    'account_security' => [
        'password_reset' => [
            'title' => 'Mot de passe réinitialisé',
            'body' => [
                'console' => 'Votre mot de passe a été réinitialisé le :date à :time. Si vous n\'êtes pas à l\'origine de cette demande, prévenez l\'administrateur.',
                'admin' => 'Votre mot de passe a été réinitialisé par l\'administrateur le :date à :time. Si vous n\'en avez pas fait la demande, prévenez-le.',
                'regional' => 'Votre mot de passe a été réinitialisé par votre régional le :date à :time. Si vous n\'en avez pas fait la demande, prévenez l\'administrateur.',
            ],
        ],
    ],
];
