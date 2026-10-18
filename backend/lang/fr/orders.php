<?php

// Commandes client (lot 5, §5.4, §6.2).
return [
    'client_blocked' => 'Votre compte client est bloqué : commande impossible. Contactez votre commercial régional.',
    'not_open' => 'La prise de commande n\'est pas encore ouverte pour cette journée.',
    'day_closed' => 'Cette journée est clôturée : commande impossible.',
    'cutoff_passed' => 'L\'heure limite de commande (:time) est dépassée.',
    'max_orders' => '{1} Vous avez déjà envoyé votre commande pour cette journée.|[2,*] Vous avez déjà envoyé :count commandes pour cette journée, le maximum.',
    'product_unavailable' => 'Produit indisponible pour vous ce jour-là.',
    'products_unavailable' => '{1} Un produit n\'est pas disponible pour vous ce jour-là.|[2,*] :count produits ne sont pas disponibles pour vous ce jour-là.',
    'limit_exceeded_one' => '« :product » : maximum :max.',
    'limit_exceeded_unknown' => '« :product » : quantité trop élevée, réduisez-la.',
    'limits_exceeded' => ':count lignes dépassent la quantité disponible.',
    'below_minimum' => 'Montant minimum de commande : :min DA HT (commande : :total DA HT).',
    'version_conflict' => 'La commande a été modifiée entre-temps : rechargez-la.',
    'invalid_transition' => 'Commande « :from » : impossible de passer à « :to ».',
    'not_found' => 'Commande introuvable.',
    'audit' => [
        'submitted' => 'Commande envoyée',
        'updated' => 'Commande modifiée par le client',
        'cancelled' => 'Commande annulée par le client',
        'expired' => 'Commandes expirées à la clôture',
    ],
    // Points d'accès du client (contrat du lot 5, §3).
    'client' => [
        'attributes' => [
            'day' => 'journée',
            'client_request_id' => 'identifiant d\'envoi',
            'comment' => 'commentaire',
            'version' => 'version',
            'lines' => 'lignes',
            'lines.*' => 'ligne',
            'lines.*.product_id' => 'produit',
            'lines.*.quantity' => 'quantité',
        ],
        'day_format' => 'Journée attendue au format AAAA-MM-JJ.',
        'quantity_format' => 'La quantité doit être un nombre entier de 1 à :max.',
        'duplicate_line' => 'Ce produit figure déjà dans la commande.',
        'unknown_status' => 'Statut de commande inconnu.',
    ],
];
