# RamyMaak — PWA (React)

Application unique installable (§9.5 de la [spécification](../docs/specification-technique-v1.0.md)) : modules client, régional, ADV et Admin chargés à la demande selon le rôle.

## Démarrage local

```bash
npm ci
npm run dev        # http://localhost:5173, /api et /sanctum relayés vers http://localhost:8000
```

Le back-end doit tourner en parallèle (`composer dev` dans `../backend`). Autre adresse : `VITE_API_TARGET=http://… npm run dev`.

## Contrôles

```bash
npm run lint       # oxlint
npm run typecheck  # TypeScript (application, service worker, configuration)
npm test           # Vitest + Testing Library
npm run build      # build de production dans dist/
```

## Repères

| Élément | Emplacement |
|---|---|
| Modules par rôle | `src/modules/{client,regional,adv,admin}` |
| Accès API, session, gardes de route | `src/shared/api`, `src/shared/auth` |
| Libellés (français ; arabe en V1.1) | `src/shared/i18n/fr.ts` |
| Service worker | `src/sw.ts` |
| Manifeste, icônes | `vite.config.ts`, `public/icons` |

**Back-office hors du téléphone client** : tout fichier JavaScript contenant du code ADV ou Admin est nommé `backoffice-*`, exclu du pré-cache du service worker, et le build échoue si le reste de l'application l'importe statiquement (plugin `backOfficeIsolation` de `vite.config.ts`).

**Charte Ramy** (lot 7) : rouge `#E30613` mesuré sur le logo, neutres et couleurs d'état de [DESIGN.md](../Design.md), police Rubik auto-hébergée (`src/index.css`, `src/main.tsx`). Logo, logo blanc et photo de la page de connexion dans `public/brand`, icônes dans `public/icons`. Le logo blanc et les icônes sont dérivés du logo fourni : à remplacer par les fichiers officiels de Ramy dès qu'ils sont disponibles.
