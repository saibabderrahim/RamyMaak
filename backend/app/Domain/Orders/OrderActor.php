<?php

namespace App\Domain\Orders;

/** Acteur d'une transition de commande (§6.3). */
enum OrderActor: string
{
    case Client = 'CLIENT';
    case Regional = 'REGIONAL';
    case Adv = 'ADV';
    case System = 'SYSTEM';
}
