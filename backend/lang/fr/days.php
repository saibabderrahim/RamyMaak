<?php

// Journées commerciales (§4, §6.1, EF-ADV-01).
return [
    'already_open' => 'La journée du :day est déjà ouverte.',
    'closed' => 'La journée du :day est clôturée : elle n\'est plus modifiable.',
    'region_closed' => 'La journée de la région :region est clôturée.',
    'cutoff_order' => 'Les heures limites doivent se suivre : répartition, commande, validation, puis clôture.',
    'closing_in_past' => 'L\'heure de clôture doit être à venir.',
    'override_after_closing' => 'Une prolongation accordée à une région dépasse cette heure de clôture.',
    'not_extendable' => 'Seules les heures limites de répartition et de commande se prolongent par région.',
    'extension_not_later' => 'La prolongation doit être postérieure à l\'heure limite actuelle (:current).',
    'extension_after_closing' => 'La prolongation ne peut pas dépasser l\'heure de clôture (:closing).',
    'audit' => [
        'opened' => 'Journée ouverte',
        'schedule' => 'Heures limites de la journée modifiées',
        'extended' => 'Heure limite prolongée',
        'closed' => 'Journée clôturée',
    ],
];
