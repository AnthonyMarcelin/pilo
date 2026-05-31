# PaddleOCR-VL — pipeline Python (wrapper FastAPI seulement)
#
# Architecture deux conteneurs (décision architecturale, 2026-05-31) :
#   - Ce conteneur : PaddleOCR Python >= 3.4.1, PP-DocLayoutV2 layout local,
#     appelle llama-server via HTTP pour l'inférence VLM.
#   - Sidecar llama-server requis — voir docker-compose.yml profil "ai".
#
# Modèle VL : PaddleOCR-VL 1.6 Q8_0 (primaire) ou 1.5 Q8_0 (fallback documenté).
#   Les poids GGUF sont chargés par llama-server depuis le volume vl_models.
#   Ce conteneur ne charge aucun modèle VLM lui-même.
#
# Pourquoi >= 3.4.1 : le backend llama-cpp-server était silencieusement cassé en
#   3.4.0 (appels ignorés sans erreur). Ne jamais épingler sur "paddleocr" sans
#   version minimum explicite.
#
# CPU only — pas de section deploy/gpu.

FROM python:3.11-slim-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libglib2.0-0 libsm6 libxrender1 libxext6 libgomp1 libgl1 \
 && rm -rf /var/lib/apt/lists/*

# PaddleOCR >= 3.4.1 : requis pour le backend llama-cpp-server fonctionnel.
# paddlepaddle-cpu : runtime CPU (pas de CUDA).
# httpx : client HTTP async pour les appels vers llama-server.
RUN pip install --no-cache-dir \
    "paddlepaddle==2.6.2" \
    "paddleocr>=3.4.1" \
    "fastapi==0.115.6" \
    "uvicorn[standard]==0.32.1" \
    "python-multipart==0.0.20" \
    "Pillow==11.1.0" \
    "httpx==0.27.0"

WORKDIR /app

# Pré-téléchargement du modèle de layout PP-DocLayoutV2 au moment du build
# (détection de layout tourne localement dans ce conteneur, pas via llama-server).
# Le modèle VLM (GGUF) est dans llama-server — ne pas tenter de le charger ici.
RUN python -c "\
from paddleocr import PPStructureV3; \
PPStructureV3(device='cpu', show_log=False)" 2>/dev/null || true

COPY app.py .

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
