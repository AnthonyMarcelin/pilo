# Pilo

**Pilo** est une application web auto-hébergée pour visualiser et suivre un traitement médicamenteux au quotidien. Elle présente le traitement sous la forme d'un « pilulier numérique » : la journée découpée en moments (matin, midi, soir, coucher), plus une section « au besoin ». Les ordonnances peuvent être saisies à la main ou photographiées, avec une lecture assistée par une IA qui tourne **entièrement en local**.

> **Avertissement médical.** Pilo est un **aide-mémoire**, pas un dispositif médical. L'application ne calcule pas de doses, ne détecte pas d'interactions et ne remplace en aucun cas l'ordonnance d'un médecin ni les conseils d'un pharmacien. Toute information affichée doit être vérifiée par rapport à l'ordonnance d'origine.

---

## Fonctionnalités

- **Vue du jour** : les prises regroupées par moment (matin / midi / soir / coucher), une section « au besoin » distincte, et les prises particulières en texte libre.
- **Saisie d'ordonnance** : à la main, ou par photo avec extraction automatique (médicaments, posologie, durée) — toujours soumise à validation humaine avant enregistrement.
- **Trois types de prise** : régulière (grille horaire), « au besoin » (avec condition et dose max), et irrégulière (texte libre).
- **Posologie dégressive** : gestion de paliers successifs (ex. sevrage corticoïdes) avec affichage « jour X/Y » et alerte de changement de dose.
- **Alertes utiles** : renouvellement à venir (estimation de stock), fin de traitement, signalement de doublon lors d'une saisie.
- **Historique** : toutes les ordonnances sont conservées et consultables, image d'origine incluse.
- **PWA** : installable sur l'écran d'accueil (iPhone, iPad, Android, bureau), consultation du traitement courant hors-ligne.

## Confidentialité

Les données de santé ne quittent jamais le serveur. La lecture des ordonnances est réalisée par des modèles open source exécutés **localement** (aucun appel à une API externe). Pilo est conçu pour être auto-hébergé sur votre propre machine.

## Architecture

- **Backend** : Laravel (PHP 8.4+), SQLite (WAL), Redis (file d'attente).
- **Frontend** : Inertia.js + Vue 3 + Tailwind CSS, PWA (vite-plugin-pwa).
- **Build** : assets Vite buildés dans l'image Docker (stage Node multi-stage) — aucune dépendance Node sur le serveur.
- **IA locale (à la demande)** :
  - [PaddleOCR-VL](https://github.com/PaddlePaddle/PaddleOCR) — image d'ordonnance → texte + structure spatiale.
  - [Ollama](https://ollama.com) avec Qwen2.5 3B — texte structuré → JSON normalisé.
- Le tout orchestré via Docker Compose.

---

## Prérequis

- **Docker** + **Docker Compose** (v2)
- Aucun Node.js ni PHP requis sur l'hôte — tout est dans les conteneurs.

### Architecture CPU

Pilo vise du **matériel modeste, CPU uniquement** (pas de GPU nécessaire). Les services IA ne démarrent que le temps d'un scan, puis s'arrêtent automatiquement.

### Compatibilité ARM vs x86_64

| Fonctionnalité | ARM64 | x86_64 |
|---|:---:|:---:|
| Application complète (pilulier, alertes, historique) | ✅ | ✅ |
| **Saisie manuelle d'ordonnance** | ✅ | ✅ |
| Import BDPM, référentiel médicaments | ✅ | ✅ |
| PWA hors-ligne | ✅ | ✅ |
| Normalisation Ollama (qwen2.5:3b) | ✅ | ✅ |
| **Scan OCR automatique** | ✗ | ✅ |

> **ARM64 (Apple Silicon, Graviton…)** : l'application est pleinement utilisable via la **saisie manuelle**, chemin nominal conçu dès le départ. Seul le scan automatique est limité par l'absence d'image ARM64 pour PaddleOCR-VL.

### RAM serveur

| Mode | RAM estimée |
|---|---|
| Au repos (app + web + redis + queue) | ~1–1,5 Go |
| Pic pendant un scan (Ollama 3B + llama-server VL + paddleocr-vl) | ~4,5–5 Go |

VM recommandée : **6 Go de RAM minimum**. Le pic est transitoire (durée du scan) ; la RAM est relâchée ensuite.

---

## Premier déploiement

### 1. Cloner et configurer

```bash
git clone <url-du-repo> pilo && cd pilo
cp .env.example .env
```

Éditez `.env` — variables **obligatoires** avant le premier démarrage :

| Variable | Description |
|---|---|
| `APP_URL` | URL publique exacte, ex. `https://pilo.example.com` |
| `APP_KEY` | Généré à l'étape suivante (`php artisan key:generate`) |
| `OWNER_EMAIL` | Adresse e-mail du compte propriétaire |
| `OWNER_PASSWORD` | Mot de passe du compte propriétaire (**changer le placeholder**) |
| `TRUSTED_PROXIES` | `*` si derrière Traefik/Nginx/Caddy, vide sinon |

Les autres variables (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, etc.) sont correctement renseignées par défaut.

### 2. Démarrer les services de base

```bash
docker compose up -d --build app web redis queue
```

Le build télécharge les images, compile les assets Vite dans Docker (stage Node) et installe les dépendances PHP. Première exécution : 5–10 minutes selon la connexion.

### 3. Initialiser l'application

```bash
# Générer la clé d'application (remplit APP_KEY dans .env)
docker compose exec app php artisan key:generate

# Créer le schéma de base de données
docker compose exec app php artisan migrate

# Créer le compte propriétaire (utilise OWNER_EMAIL / OWNER_PASSWORD du .env)
docker compose exec app php artisan db:seed --class=OwnerSeeder

# ⚠️  NE PAS utiliser --seed en production (insèrerait des données de démo)

# Optionnel : importer le référentiel BDPM (médicaments France)
# Télécharger d'abord les 3 fichiers depuis base-donnees-publique.medicaments.gouv.fr
# puis les placer dans le répertoire courant, et lancer :
docker compose exec app php artisan pilo:import-bdpm
```

L'application est disponible sur le port `8080` de l'hôte (à exposer via votre reverse proxy).

### 4. Préparer le scan OCR — x86_64 uniquement

```bash
# Modèles VL dans le volume (~1,8 Go)
docker run --rm -v pilo_vl_models:/models alpine sh -c "
  apk add --no-cache wget &&
  wget -q -O /models/PaddleOCR-VL-1.6-GGUF.gguf \
    'https://huggingface.co/PaddlePaddle/PaddleOCR-VL-1.6-GGUF/resolve/main/PaddleOCR-VL-1.6-GGUF.gguf' &&
  wget -q -O /models/PaddleOCR-VL-1.6-GGUF-mmproj.gguf \
    'https://huggingface.co/PaddlePaddle/PaddleOCR-VL-1.6-GGUF/resolve/main/PaddleOCR-VL-1.6-GGUF-mmproj.gguf'
"

# Modèle Ollama de structuration JSON (~1,9 Go)
docker compose --profile ai up -d ollama
docker compose exec ollama ollama pull qwen2.5:3b-instruct
docker compose --profile ai stop ollama
```

Les services IA s'allument et s'éteignent automatiquement à chaque scan.

---

## Mise à jour

```bash
# 1. Récupérer les nouvelles versions
git pull

# 2. Reconstruire les images (inclut le nouveau build Vite et les dépendances)
docker compose up -d --build app web queue

# 3. Appliquer les migrations de base de données
docker compose exec app php artisan migrate

# 4. Vider les caches applicatifs
docker compose exec app php artisan optimize:clear
```

> **Important :** `migrate` uniquement — ne jamais relancer `db:seed` en production (écrase les données existantes).

---

## Déploiement derrière un reverse proxy

Pilo est conçu pour tourner derrière un reverse proxy avec TLS. Le conteneur `web` (Nginx) écoute sur le port `8080` de l'hôte.

### TrustProxies

Pour que Laravel génère des liens `https://` corrects et lise l'IP réelle du client, la variable `TRUSTED_PROXIES` doit être définie dans `.env` :

```env
TRUSTED_PROXIES=*          # tous les proxies (Traefik, Nginx interne, etc.)
# ou
TRUSTED_PROXIES=10.0.0.0/8  # CIDR du réseau du proxy
```

Sans cette variable, les liens générés seront en `http://` même derrière un proxy HTTPS, provoquant du contenu mixte et une page blanche.

### Exemple : Traefik (labels Docker)

```yaml
# Dans docker-compose.yml, ajouter au service web :
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.pilo.rule=Host(`pilo.example.com`)"
  - "traefik.http.routers.pilo.entrypoints=websecure"
  - "traefik.http.routers.pilo.tls.certresolver=letsencrypt"
  - "traefik.http.services.pilo.loadbalancer.server.port=80"
```

### Exemple : Nginx en reverse proxy

```nginx
server {
    listen 443 ssl http2;
    server_name pilo.example.com;

    ssl_certificate     /etc/ssl/certs/pilo.crt;
    ssl_certificate_key /etc/ssl/private/pilo.key;
    client_max_body_size 20m;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 300s;
    }
}

server {
    listen 80;
    server_name pilo.example.com;
    return 301 https://$host$request_uri;
}
```

### Exemple : Caddy (HTTPS automatique)

```caddyfile
pilo.example.com {
    reverse_proxy localhost:8080 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-Proto {scheme}
    }
}
```

---

## Référentiel médicaments (BDPM)

Le calcul d'estimation de stock s'appuie sur la **Base de données publique des médicaments** (ANSM / Ministère chargé de la santé), disponible sur <https://base-donnees-publique.medicaments.gouv.fr> sous licence ouverte (Etalab / Open Data). Ces données sont importées localement et ne confèrent aucun caractère officiel à leur réutilisation par Pilo.

La commande `php artisan pilo:import-bdpm` charge les fichiers `CIS_CIP_bdpm.txt`, `CIS_HAS_SMR_bdpm.txt` et `CIS_GENER_bdpm.txt` depuis le répertoire courant. Un cron mensuel (1er du mois, 3h) maintient le référentiel à jour.

---

## Licence

MIT — voir [LICENSE](LICENSE).
