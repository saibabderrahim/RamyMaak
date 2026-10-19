# Déploiement — guide d'exploitation

Procédure pour installer RamyMaak sur un serveur, puis livrer les versions suivantes (§14). Elle vaut pour la **recette** (première livraison, critère du lot 1) comme pour la **production**.

## 0. Prérequis à décider

| Élément | Recommandation (§14.1) |
|---|---|
| Serveur | VPS Debian 12 dédié à RamyMaak : 4 vCPU, 8 Go de RAM, 80 Go de SSD |
| Nom de domaine | Ex. `recette.ramymaak.<domaine>` ; enregistrement DNS **A** vers l'adresse IP du serveur, créé avant l'étape 5 |
| Accès | Un compte d'administration système avec `sudo`, connexion SSH par clé |

## 1. Préparer la version

Le script de déploiement installe une **étiquette** Git.

1. Fusionner le travail dans la branche principale (pull request).
2. Étiqueter la livraison et la publier :

   ```bash
   git tag v0.1.0
   git push origin v0.1.0
   ```

## 2. Préparer le serveur (une seule fois)

```bash
sudo apt update && sudo apt install -y git nginx certbot python3-certbot-nginx ufw
curl -fsSL https://get.docker.com | sudo sh          # Docker Engine + Docker Compose
sudo usermod -aG docker "$USER"                      # se déconnecter puis se reconnecter

# Fichier d'échange de 4 Go (§14.1)
sudo fallocate -l 4G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# Pare-feu : SSH, HTTP et HTTPS seulement (§12)
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

Dans `/etc/ssh/sshd_config`, mettre `PasswordAuthentication no`, puis `sudo systemctl reload ssh`.

### Accès au dépôt (dépôt privé)

```bash
ssh-keygen -t ed25519 -f ~/.ssh/ramymaak_deploy -N ""
cat ~/.ssh/ramymaak_deploy.pub
```

Sur GitHub : dépôt → **Settings → Deploy keys → Add deploy key**, coller la clé, **sans** cocher « Allow write access ». Puis :

```bash
sudo mkdir -p /opt/ramymaak && sudo chown "$USER" /opt/ramymaak
GIT_SSH_COMMAND="ssh -i ~/.ssh/ramymaak_deploy" git clone git@github.com:saibabderrahim/RamyMaak.git /opt/ramymaak
cd /opt/ramymaak
git config core.sshCommand "ssh -i ~/.ssh/ramymaak_deploy"
```

## 3. Configurer l'application (une seule fois)

```bash
cd /opt/ramymaak
cp .env.example .env && chmod 600 .env

DOMAINE=recette.ramymaak.example.dz        # à remplacer
sed -i \
  -e "s|^APP_ENV=.*|APP_ENV=recette|" \
  -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
  -e "s|^APP_URL=.*|APP_URL=https://$DOMAINE|" \
  -e "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=$DOMAINE|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 24)|" \
  -e "s|^MYSQL_ROOT_PASSWORD=.*|MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)|" \
  -e "s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=$(openssl rand -hex 16)|" \
  -e "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=$(openssl rand -hex 16)|" \
  .env
```

- En production, laisser `APP_ENV=production`.
- **Conserver une copie de `.env` en lieu sûr.** `APP_KEY` et les mots de passe de la base ne doivent plus changer : une nouvelle clé déconnecte tout le monde, et la base ne s'ouvre qu'avec les mots de passe de sa création.
- Les notifications par e-mail sont facultatives : `MAIL_MAILER=log` convient tant qu'aucun serveur SMTP n'est fourni.

## 4. Premier déploiement

```bash
cd /opt/ramymaak
FORCE_DAYTIME=1 scripts/deploy.sh v0.1.0      # 10 à 15 minutes la première fois
curl -fsS http://127.0.0.1:8090/up > /dev/null && echo "Application OK"
```

`FORCE_DAYTIME=1` lève l'interdiction de déployer entre 06:00 et 20:00, prévue pour la production (§14.4).

Création du premier compte Admin. Le mot de passe temporaire s'affiche une seule fois et sera changé à la première connexion :

```bash
docker compose run --rm --no-deps app php artisan ramymaak:create-admin a.benali --name="Amina Benali"
```

## 5. HTTPS (nginx de l'hôte et certbot)

L'enregistrement DNS doit déjà pointer vers le serveur.

```bash
DOMAINE=recette.ramymaak.example.dz        # le même qu'à l'étape 3
sudo certbot certonly --nginx -d "$DOMAINE" --deploy-hook "systemctl reload nginx"

sudo sed "s/ramymaak.example.dz/$DOMAINE/g" /opt/ramymaak/docker/nginx-host/ramymaak.conf \
  | sudo tee /etc/nginx/sites-available/ramymaak > /dev/null
sudo ln -sf /etc/nginx/sites-available/ramymaak /etc/nginx/sites-enabled/ramymaak
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

sudo certbot renew --dry-run                # vérifie le renouvellement automatique
```

## 6. Vérifications de recette

1. `https://<domaine>/up` répond, et le navigateur affiche un cadenas.
2. Se connecter avec le compte Admin : le changement de mot de passe est imposé.
3. Dans **Comptes → Nouveau compte**, créer un compte par rôle : ADV, Régional, et un Client dont l'identifiant est son code client. Remettre à chacun son mot de passe temporaire.
4. Se connecter avec chacun, dont le client depuis un téléphone, puis installer l'application depuis **Profil**. C'est le critère de fin du lot 1.

## Livraisons suivantes

Tout se fait sur le serveur, dans `/opt/ramymaak`, en production entre 20:00 et 06:00 (heure d'Alger) :

```bash
cd /opt/ramymaak
git fetch origin
git tag v0.4.0 origin/<branche livrée>      # le dernier commit publié, pas HEAD
git show --no-patch --oneline v0.4.0        # vérifier : le commit attendu, pas un ancien
git push origin v0.4.0                      # facultatif : garder l'étiquette sur GitHub
scripts/deploy.sh v0.4.0
```

Toujours étiqueter `origin/<branche>` après `git fetch`, jamais `HEAD` : après un déploiement, `HEAD` est l'ancienne version, qui serait livrée sous le nouveau numéro.

**Étiquette posée sur le mauvais commit** : la supprimer (sur GitHub aussi si elle y a été poussée), puis la recréer.

```bash
cd /opt/ramymaak
git push origin :refs/tags/v0.4.0           # si elle est sur GitHub
git tag -d v0.4.0
git fetch origin && git tag v0.4.0 origin/<branche livrée>
git show --no-patch --oneline v0.4.0
git push origin v0.4.0                      # facultatif
scripts/deploy.sh v0.4.0
```

Chaque note de livraison précise ce qui change et les actions éventuelles : [lot 2 — mise à jour depuis la v0.1.0](lot-2-referentiels.md#mettre-à-jour-la-production-v010--v020), [lot 3 — mise à jour depuis la v0.2.0](lot-3-quotas-adv.md#mettre-à-jour-la-production-v020--v030), [lot 4 — mise à jour depuis la v0.3.0](lot-4-repartition.md#mettre-à-jour-la-production-v030--v040), [lot 5 — mise à jour depuis la v0.4.0](lot-5-commandes-client.md#mettre-à-jour-la-production-v040--v050).

**Retour arrière** : `scripts/deploy.sh <étiquette précédente>`. Les migrations restent compatibles avec la version précédente.

## Commandes utiles

| Besoin | Commande (dans `/opt/ramymaak`) |
|---|---|
| État des services | `docker compose ps` |
| Journaux | `docker compose logs -f --tail=100 app worker` |
| Compte Admin supplémentaire | `docker compose run --rm --no-deps app php artisan ramymaak:create-admin <identifiant>` |
| Mot de passe perdu (Admin compris) : mot de passe temporaire, compte déverrouillé, sessions fermées | `docker compose run --rm --no-deps app php artisan ramymaak:reset-password <identifiant>` |
| Redémarrer | `docker compose restart` |

## Sauvegardes : à mettre en place avant toute donnée réelle

La politique complète (§14.5 : copies chiffrées hors site, restauration à un instant donné, tests trimestriels) est prévue au lot 8. D'ici là, au minimum, une copie quotidienne de la base :

```bash
sudo mkdir -p /var/backups/ramymaak && sudo chown "$USER" /var/backups/ramymaak
crontab -e
# ajouter :
30 2 * * * cd /opt/ramymaak && docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines "$MYSQL_DATABASE"' | gzip > /var/backups/ramymaak/ramymaak-$(date +\%F).sql.gz && find /var/backups/ramymaak -name '*.sql.gz' -mtime +30 -delete
```

Copier régulièrement `/var/backups/ramymaak` hors du serveur.
