# RamyMaak — API (Laravel)

API REST de RamyMaak (§9 et §11 de la [spécification](../docs/specification-technique-v1.0.md)).

## Démarrage local

```bash
composer setup            # dépendances, .env, clé, migrations, comptes de démo
composer dev              # http://localhost:8000
```

Sans MySQL local, mettre `DB_CONNECTION=sqlite` dans `.env` (fichier `database/database.sqlite`).

Comptes de démonstration (environnements `local`, `testing`, `recette`) — mot de passe `RamyMaak2026demo` :
`admin`, `adv`, `regional`, `c0012` (client).

En production, le premier Admin se crée avec :

```bash
php artisan ramymaak:create-admin a.benali --name="Amina Benali"
```

## Contrôles

```bash
composer lint             # style (Pint)
composer test             # tests Pest (SQLite en mémoire)
```

Pour exécuter les tests sur MySQL : `DB_CONNECTION=mysql DB_DATABASE=ramymaak_test … composer test`.

## Organisation

| Dossier | Contenu |
|---|---|
| `app/Domain` | Règles métier (accès, authentification, paramètres) |
| `app/Http/Controllers/Api/V1` | Points d'accès `/api/v1` |
| `app/Http/Problems` | Erreurs au format « Problem Details » (RFC 9457) et codes métier (§11.4) |
| `app/Policies` | Droits et périmètres (RG-S02) |
| `lang/fr` | Messages en français |
