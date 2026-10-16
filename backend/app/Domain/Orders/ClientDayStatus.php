<?php

namespace App\Domain\Orders;

/**
 * État de la prise de commande pour un client et une journée (§11.3,
 * EF-CLT-01), par ordre de priorité : journée clôturée, heure limite
 * passée, répartition envoyée, sinon en attente des quotas.
 */
enum ClientDayStatus: string
{
    case WaitingQuotas = 'WAITING_QUOTAS';
    case OrderingOpen = 'ORDERING_OPEN';
    case OrderingClosed = 'ORDERING_CLOSED';
    case DayClosed = 'DAY_CLOSED';
}
