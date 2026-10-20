# RamyMaak — notes pour l'assistant

## Mode de travail de l'utilisateur

- **Pas de poste de développement séparé : l'utilisateur travaille directement sur le VPS** (dépôt cloné dans `/opt/ramymaak`). Toutes les commandes à lui donner (étiquetage, déploiement, diagnostic) s'exécutent **sur le VPS**, dans `/opt/ramymaak` ; ne jamais parler de « poste de développement ».
- Sur le VPS, après un déploiement, `HEAD` est détaché sur l'étiquette déployée : un `git tag` sans commit explicite étiquetterait l'ancienne version. Toujours étiqueter `origin/<branche>` après `git fetch origin`, puis vérifier avec `git show --no-patch --oneline <étiquette>` (incident de la v0.3.0, posée sur le commit de la v0.2.0).
- Réponses en français.

## Livraison

- Le travail est poussé sur la branche `claude/new-session-lhmuq1` ; `main` est en retard.
- Procédure : [docs/deploiement.md](docs/deploiement.md), « Livraisons suivantes » ; une note de livraison par lot dans `docs/lot-N-*.md`, un contrat d'API et d'écrans par lot dans `docs/lot-N-contrat-api.md`.


## UI Engineering

For any UI implementation, redesign or frontend refactoring:

- Read `DESIGN.md`.
- Apply the `/erp-ui-design` skill when relevant.
- Prefer domain-specific layouts over generic dashboard templates.
- Never claim visual verification without inspecting the rendered page.
- Preserve existing business logic and functionality.
