<?php

namespace App\Domain\Documents;

/**
 * Regroupement du bon de préparation (I-84) : par région (par défaut) ou
 * par dépôt actuel de la région.
 */
enum PickingBy: string
{
    case Region = 'region';
    case Depot = 'depot';

    public function label(): string
    {
        return (string) __('documents.picking.by.'.$this->value);
    }
}
