# PaddleOCR-VL — pipeline Python (wrapper FastAPI seulement)
#
# Architecture deux conteneurs :
#   - Ce conteneur : PaddleOCRVL (paddleocr==3.4.1), PP-DocLayoutV2 layout local,
#     appelle llama-server via HTTP pour l'inférence VLM (PaddleOCRVL.predict()).
#   - Sidecar llama-server requis — voir docker-compose.yml.
#
# Modèle VL : PaddleOCR-VL 1.6 GGUF (primaire) ou 1.5 (fallback documenté).
#   Les poids GGUF sont chargés par llama-server depuis le volume vl_models.
#   Ce conteneur ne charge aucun modèle VLM lui-même.
#
# Pourquoi paddleocr==3.4.1 (épinglé) :
#   - 3.4.0 : backend llama-cpp-server silencieusement cassé.
#   - 3.4.1 : première version stable avec PaddleOCRVL + llama-cpp-server.
#   - Ne jamais utiliser "paddleocr>=3.4.1" sans borne haute — l'API a changé
#     entre versions mineures (PPStructureV3 ≠ PaddleOCRVL, args incompatibles).
#
# Pourquoi paddlepaddle==3.2.1 :
#   - paddleocr 3.4.1 requiert paddlepaddle >= 3.2.1 (via PaddleX 3.4.x).
#   - paddlepaddle==2.6.2 (2.x) est incompatible avec paddleocr 3.x.
#
# CPU only — pas de section deploy/gpu.

FROM python:3.11-slim-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libglib2.0-0 libsm6 libxrender1 libxext6 libgomp1 libgl1 \
 && rm -rf /var/lib/apt/lists/*

# paddleocr==3.4.1 : première version stable avec PaddleOCRVL + llama-cpp-server.
# paddlepaddle==3.2.1 : requis par paddleocr 3.x (via PaddleX 3.4.x). CPU-only.
# httpx : client HTTP async pour les appels vers llama-server.
RUN pip install --no-cache-dir \
    "paddlepaddle==3.2.1" \
    "paddleocr==3.4.1" \
    "fastapi==0.115.6" \
    "uvicorn[standard]==0.32.1" \
    "python-multipart==0.0.20" \
    "Pillow==11.1.0" \
    "httpx==0.27.0"

WORKDIR /app

# Pré-téléchargement du modèle de layout PP-DocLayoutV2 au moment du build.
# On utilise PPStructureV3 (pas PaddleOCRVL) car PPStructureV3 n'a pas besoin
# de llama-server pour s'initialiser → pas d'erreur réseau au build.
# Les deux classes partagent le même cache PP-DocLayoutV2 dans paddleocr.
# Le modèle VLM (GGUF) est dans llama-server — ne pas tenter de le charger ici.
RUN python -c "\
from paddleocr import PPStructureV3; \
PPStructureV3(device='cpu')" 2>/dev/null || true

COPY app.py .

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
