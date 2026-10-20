<?php

namespace App\Console\Commands;

use App\Domain\Quotas\QuotaLedger;
use App\Models\BusinessDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Réconciliation nocturne des quotas (§5.8, §9.7, I-93) : chaque compteur
 * V(r,p) (`region_quotas.validated_qty`) comparé à la somme des quantités
 * validées des commandes Validées et suivantes. Rapport seul : un écart est
 * journalisé en erreur (`quota.reconciliation.mismatch`) et fait sortir en
 * code 1, jamais corrigé automatiquement. Les dépassements d'invariants (I-1,
 * I-2) sont journalisés en avertissement et ne changent pas le code de
 * sortie. Lancée chaque nuit à 02:10 (heure d'Alger) par le planificateur ;
 * par défaut, les 7 dernières journées ouvertes jusqu'à aujourd'hui.
 */
class ReconcileQuotas extends Command
{
    /** Journées examinées par défaut. */
    public const DEFAULT_DAYS = 7;

    /** Lignes détaillées au plus par entrée de journal (le total est toujours donné). */
    private const LOGGED = 100;

    protected $signature = 'ramymaak:reconcile-quotas
        {--from= : Première journée examinée (AAAA-MM-JJ), par défaut la 7e dernière journée ouverte}
        {--to= : Dernière journée examinée (AAAA-MM-JJ), par défaut aujourd\'hui}';

    protected $description = 'Compare les compteurs de quotas validés aux quantités validées des commandes (rapport seul)';

    public function handle(QuotaLedger $ledger): int
    {
        foreach (['from', 'to'] as $option) {
            $value = $this->option($option);
            if (filled($value) && BusinessClock::parseDay((string) $value) === null) {
                $this->error("--{$option} : date invalide « {$value} » (AAAA-MM-JJ attendu).");

                return self::INVALID;
            }
        }
        $to = filled($this->option('to')) ? BusinessClock::parseDay((string) $this->option('to')) : BusinessClock::today();
        $from = filled($this->option('from')) ? BusinessClock::parseDay((string) $this->option('from')) : $this->defaultFrom($to);
        if ($from->greaterThan($to)) {
            $this->error('La première journée est postérieure à la dernière.');

            return self::INVALID;
        }

        $range = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
        $mismatches = $ledger->reconcile($from, $to);
        $breaches = $ledger->breaches($from, $to);

        if ($breaches !== []) {
            Log::warning('quota.reconciliation.breach', [
                ...$range,
                'count' => count($breaches),
                'breaches' => array_slice($breaches, 0, self::LOGGED),
            ]);
            $this->warn(count($breaches).' dépassement(s) d\'invariant (I-1, I-2) : voir le journal.');
        }

        if ($mismatches === []) {
            $this->info("Quotas réconciliés du {$range['from']} au {$range['to']} : aucun écart.");

            return self::SUCCESS;
        }

        Log::error('quota.reconciliation.mismatch', [
            ...$range,
            'count' => count($mismatches),
            'mismatches' => array_slice($mismatches, 0, self::LOGGED),
        ]);
        $this->error(count($mismatches)." écart(s) entre les compteurs et les quantités validées du {$range['from']} au {$range['to']} (aucune correction appliquée).");
        $this->table(
            ['Journée', 'Région', 'Produit', 'Compteur', 'Recalculé'],
            array_map(fn (array $row) => [$row['business_day'], $row['region_id'], $row['product_id'], $row['counter'] ?? 'absent', $row['recomputed']], array_slice($mismatches, 0, self::LOGGED)),
        );

        return self::FAILURE;
    }

    /**
     * Première des 7 dernières journées ouvertes jusqu'à `$to` (les journées
     * d'ouverture sont les jours ouvrés, H-10) ; `$to` lui-même sans journée.
     */
    private function defaultFrom(CarbonImmutable $to): CarbonImmutable
    {
        $oldest = BusinessDay::query()
            ->where('day', '<=', $to->format('Y-m-d'))
            ->orderByDesc('day')
            ->limit(self::DEFAULT_DAYS)
            ->get(['id', 'day'])
            ->last();

        return $oldest?->day ?? $to;
    }
}
