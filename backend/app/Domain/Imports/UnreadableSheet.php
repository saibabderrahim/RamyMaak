<?php

namespace App\Domain\Imports;

use RuntimeException;

/**
 * Fichier qui ne peut pas être analysé ligne à ligne (classeur illisible,
 * trop de lignes) : erreur de niveau fichier du rapport, pas un incident
 * technique.
 */
final class UnreadableSheet extends RuntimeException {}
