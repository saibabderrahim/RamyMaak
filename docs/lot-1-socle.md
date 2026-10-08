# Lot 1 — Socle : note de livraison

Périmètre (§16) : dépôt Git, chaîne de livraison, conteneurs, authentification, rôles, comptes, audit, coquille PWA installable.
Critère de fin : **connexion par rôle sur l'environnement de recette**.

Ce lot ne dépend d'aucun point ouvert du §18 : il a pu démarrer avant la clôture du lot 0. Les choix ci-dessous suivent les décisions proposées du §17 et restent à valider avec elles.

## Contenu livré

| Exigence | Réalisation |
|---|---|
| EF-AUTH-01 | Connexion par identifiant (code client ou login nominatif), insensible à la casse |
| EF-AUTH-02 | Changement de mot de passe imposé à la première connexion et après réinitialisation : toute autre route renvoie `PASSWORD_CHANGE_REQUIRED` |
| EF-AUTH-03 | 8 caractères (12 pour Admin et ADV), lettres et chiffres, refus des mots de passe courants et de ceux qui contiennent l'identifiant ; longueurs paramétrables |
| EF-AUTH-04 | Verrouillage 15 min après 5 échecs consécutifs, 10 tentatives par minute et par adresse IP, réponses identiques pour un identifiant inconnu |
| EF-AUTH-05 | Réinitialisation par l'Admin : mot de passe temporaire à usage unique, sessions révoquées |
| EF-AUTH-06 | « Rester connecté » 30 jours pour clients et régionaux ; 12 h maximum et 2 h d'inactivité pour Admin et ADV |
| EF-AUTH-07 | « Me déconnecter de tous mes appareils » ; révocation immédiate à la désactivation |
| EF-AUTH-08 | Journal des connexions (succès, échecs, motif, adresse IP, appareil) |
| §3.2 | Les 25 permissions de la matrice des droits, regroupées en 4 rôles, sont semées une fois puis ajustables sans modifier le code |
| EF-ADM-01 | Comptes : liste filtrée, création, modification, rôle, activation et désactivation, déverrouillage, réinitialisation |
| EF-ADM-04 | Paramètres généraux, avec les valeurs par défaut de la spécification |
| EF-ADM-06 | Journal d'audit : filtres par utilisateur, objet, action et période ; export CSV |
| §11.1, §11.4 | API `/api/v1`, cookie de session Sanctum (mode SPA), erreurs « Problem Details » avec code métier, limitation de débit, pagination, filtres et tris |
| §12 | Argon2id, nouvel identifiant de session à chaque connexion, cookies HttpOnly et SameSite, en-têtes de sécurité (CSP stricte), journal non modifiable, neutralisation des formules à l'export |
| §9.5 | PWA installable : manifeste, service worker (pré-cache de la coquille), bandeau de mise à jour, aide à l'installation, bandeau hors ligne |
| §14.2 – §14.4 | Images Docker, `docker-compose.yml` (6 services, limites mémoire, vérifications de santé), frontal nginx de l'hôte, script de déploiement, CI |

## Vérifications

- **API** : 139 tests Pest, exécutés sur SQLite et sur MySQL ; la CI les exécute sur MySQL 8.4.
- **PWA** : 23 tests Vitest ; lint, types et build sans avertissement.
- **Parcours réels dans Chromium** (Playwright), sur l'API réelle et sur le build de production servi par l'image web :
  connexion de chaque rôle (téléphone, tablette, PC), refus d'un mauvais mot de passe, création d'un compte et connexion avec son mot de passe temporaire, refus d'un mot de passe courant, changement imposé, modification des paramètres puis trace dans le journal, accès refusé hors de son rôle, verrouillage après 5 échecs.
- **Service worker** : actif, 19 fichiers pré-cachés, aucun fichier du back-office, aucune violation de la CSP, coquille chargée sans réseau.
- **Isolation du back-office** : le build échoue si le code ADV ou Admin est importé par le reste de l'application (essai d'une violation volontaire : build refusé).
- **Images** : l'image web et les configurations nginx (interne et hôte) ont été construites et validées. L'image PHP n'a pas pu être construite dans l'environnement de développement (dépôts Debian inaccessibles) : la CI la construit et démarre la pile complète.

## Décisions d'implémentation à valider

| # | Décision | Raison |
|---|---|---|
| I-01 | Trois codes d'erreur ajoutés au §11.4 : `INVALID_CREDENTIALS` (401), `ACCOUNT_DISABLED` (403), `PASSWORD_CHANGE_REQUIRED` (403) | Le catalogue ne couvrait pas ces cas ; l'application doit les distinguer d'une session expirée |
| I-02 | Un identifiant inconnu est compté et verrouillé comme un compte existant | Concilie le message générique d'EF-AUTH-04 et la réponse `ACCOUNT_LOCKED` : aucune réponse ne révèle si un identifiant existe |
| I-03 | Compte désactivé : `ACCOUNT_DISABLED` seulement si le mot de passe est correct | L'utilisateur sait pourquoi il ne peut pas entrer, sans fuite d'information pour un tiers |
| I-04 | Argon2id avec les paramètres OWASP (19 Mio, 2 itérations) | Limite la mémoire consommée lors des pics de connexion (conteneur app limité à 1 Go) |
| I-05 | Mots de passe courants : liste locale (`backend/resources/security/common-passwords.txt`) | Pas d'appel à un service externe ; liste à enrichir |
| I-06 | Mot de passe temporaire : 10 caractères sans caractères ambigus (0/O, 1/l/I), affiché une seule fois | Recopié depuis une fiche remise en main propre (EF-ADM-02) |
| I-07 | La plateforme garde toujours au moins un Admin actif ; un Admin ne peut ni se désactiver ni se retirer le rôle | Évite de perdre l'accès à l'administration |
| I-08 | La synchronisation des rôles ajoute les permissions nouvelles mais n'en retire jamais | Les ajustements faits par l'Admin survivent aux déploiements |
| I-09 | Journal d'audit conservé 3 ans (purge chaque nuit à 02:30) | §12 « Conservation 3 ans (à valider) » |
| I-10 | Couleur `#0B6E4F` et monogramme « RM » provisoires | La charte Ramy n'est pas fournie (§9.5 : `#RRGGBB`) |

## Reporté, avec la dépendance qui le justifie

| Élément | Lot |
|---|---|
| Rattachement obligatoire d'un compte client au référentiel clients (clé étrangère `users.client_id`) | 2 — référentiel clients |
| Réinitialisation par le régional des comptes clients de son périmètre ; périmètre dans `/auth/me` | 2 — affectations aux régions |
| Création en masse des comptes clients et fiche d'accès PDF (EF-ADM-02, S) | 2 |
| Notification N-15 (mot de passe réinitialisé) | 5 — notifications |
| Profil et données consultables hors ligne (aujourd'hui la coquille s'ouvre hors ligne mais indique « Connexion impossible ») | 7 — PWA avancée |
| Réinitialisation en libre-service par e-mail (S), double authentification TOTP (C) | À planifier |
| Sauvegardes (§14.5) et supervision (§14.6) sur le serveur | 8 — mise en service |

## Observations sur la spécification (pour le lot 0)

1. **Prolongation de l'heure limite de validation** : RG-A05 autorise l'ADV à prolonger « une heure limite » et RG-V08 permet de rendre la validation bloquante, mais `region_days` (§10.2) n'a pas de champ `validation_cutoff_override_at`. À ajouter si la validation peut devenir bloquante.
2. **Gestion des rôles** : §3.2 prévoit qu'un rôle s'ajuste sans modifier le code, mais aucun point d'accès du §11.2 ne le permet. L'ajustement se fait aujourd'hui en base ; un écran Admin est à décider.
3. **Catalogue d'erreurs** : compléter le §11.4 avec les codes de I-01.
4. **Charte graphique** : couleur principale et icônes de l'application à fournir.
