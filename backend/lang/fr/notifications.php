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
        'body' => [
            'DISTRIBUTION' => 'Répartition jusqu\'à :time.',
            'ORDERING' => 'Commande jusqu\'à :time.',
        ],
    ],
];
