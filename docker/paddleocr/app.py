"""
PaddleOCR-VL FastAPI service — pipeline deux conteneurs.

Architecture :
  Ce service (paddleocr-vl) utilise PaddleOCRVL (paddleocr==3.4.1) qui appelle
  llama-server (sidecar) pour l'inférence VLM via HTTP (API OpenAI-compatible).
  llama-server charge PaddleOCR-VL 1.6 GGUF depuis le volume vl_models.
  Ce conteneur gère la détection de layout (PP-DocLayoutV2, local) et l'agrégation.

  Classe utilisée : PaddleOCRVL (PAS PPStructureV3).
    PPStructureV3 = pipeline OCR traditionnel, sans backend VLM externe.
    PaddleOCRVL   = layout local + inférence VLM déportée (llama-cpp-server).
  Les arguments vl_rec_backend / vl_rec_server_url n'existent que sur PaddleOCRVL.

Redimensionnement obligatoire (MAX_SIDE = 1500) :
  PaddleOCR-VL est basé sur Qwen2-VL. Chaque patch 28×28 px = 1 token vision.
  Photo iPhone 3024×4032 → ~15 000 tokens → dépasse --ctx-size 4096 du llama-server
  → l'image est tronquée → AUCUN BLOC EXTRAIT malgré 9 min d'inférence.
  À 1500×1125 → ~2200 tokens vision + ~500 texte ≈ 2700 < 4096. ✓
  Gain de temps : 4032px → 1500px = ×7 moins de pixels → inférence ~2 min.

Contrat API POST /ocr :
  Retourne des blocs ordonnés préservant la spatialité de l'ordonnance.
  block_order = ordre de lecture (essentiel pour associer nom → dosage → posologie).
  block_label = "text" | "table" | "figure" — utilisé par l'étape Ollama.
  block_bbox = [x1, y1, x2, y2] — affiché dans le formulaire de validation humaine.

  Exemple de réponse :
  {
    "filename": "ordonnance.jpg",
    "blocks": [
      {"block_id": 1, "block_order": 1, "block_label": "text",
       "block_content": "Dr. Martin Paul", "block_bbox": [x1, y1, x2, y2]},
      {"block_id": 2, "block_order": 2, "block_label": "table",
       "block_content": "<table>...</table>", "block_bbox": [x1, y1, x2, y2]}
    ],
    "markdown_preview": "..."
  }

Structure interne PaddleOCRVL.predict() (vérifiée sur v3.4.1 source) :
  Retourne list[ResultObject]. Le contenu utile est dans :
    results[0].json["parsing_res_list"]  → list[dict]
  Chaque dict : block_id, block_order, block_label, block_content, block_bbox.
  block_bbox est un np.ndarray — converti en list Python avant sérialisation.
"""

import os
import tempfile
from pathlib import Path

import numpy as np
from PIL import Image as PILImage
from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.responses import JSONResponse

# URL BASE du sidecar llama-server (sans /v1 — PaddleOCRVL ajoute le chemin).
LLAMA_SERVER_URL = os.environ.get("LLAMA_SERVER_URL", "http://llama-server:8111")

# Longueur maximale du grand côté de l'image avant inférence VLM (pixels).
# Calculé pour tenir dans --ctx-size 4096 de llama-server (Qwen2-VL, patch 28×28) :
#   1500×1125 → ceil(1500/28)×ceil(1125/28) = 54×41 = 2214 tokens vision < 4096. ✓
# Augmenter MAX_SIDE nécessite d'augmenter --ctx-size en conséquence.
MAX_SIDE = 1500

app = FastAPI(title="PaddleOCR-VL service", version="1.0.0")

_pipeline = None


def get_pipeline():
    """
    Initialise et retourne le pipeline PaddleOCRVL (lazy, une seule fois par process).

    API vérifiée contre la source paddleocr==3.4.1 (GitHub tag v3.4.1) :
      Classe   : PaddleOCRVL (paddleocr/_pipelines/paddleocr_vl.py)
      Args VL  : vl_rec_backend (str), vl_rec_server_url (str, URL base sans /v1)
      device   : "cpu" | "gpu:0" | ... (via **kwargs → PaddleXPipelineWrapper)
      Appel    : pipeline.predict(path) → list[ResultObject]
      Résultat : results[0].json["parsing_res_list"] → list de blocs

    vl_rec_backend valeurs valides (paddleX 3.4.3 GenAIConfig) :
      "native" | "llama-cpp-server" | "vllm-server" | "sglang-server" |
      "fastdeploy-server" | "mlx-vlm-server"
    """
    global _pipeline
    if _pipeline is None:
        from paddleocr import PaddleOCRVL  # type: ignore[import]
        _pipeline = PaddleOCRVL(
            vl_rec_backend="llama-cpp-server",
            vl_rec_server_url=LLAMA_SERVER_URL,
            device="cpu",
            use_doc_orientation_classify=False,
            use_doc_unwarping=False,
        )
    return _pipeline


def _resize_for_ocr(src_path: str) -> tuple:
    """
    Redimensionne l'image src_path si son grand côté dépasse MAX_SIDE.

    Retourne (chemin_final, new_file_created).
    - new_file_created=False : l'image est déjà dans les limites, retourne src_path.
    - new_file_created=True  : retourne un nouveau fichier tmp JPEG à supprimer après usage.

    Qualité JPEG 85 : bon compromis OCR/taille. LANCZOS : meilleur filtre pour downscale.
    """
    img = PILImage.open(src_path)
    w, h = img.size
    if max(w, h) <= MAX_SIDE:
        img.close()
        return src_path, False

    scale = MAX_SIDE / max(w, h)
    new_w, new_h = round(w * scale), round(h * scale)
    img = img.resize((new_w, new_h), PILImage.LANCZOS)

    with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp_r:
        resized_path = tmp_r.name
    img.save(resized_path, "JPEG", quality=85)
    img.close()
    return resized_path, True


@app.get("/health")
def health():
    """Health-check — retourne 200 quand le service est démarré."""
    return {"status": "ok", "llama_server_url": LLAMA_SERVER_URL, "max_side": MAX_SIDE}


@app.post("/ocr")
async def run_ocr(file: UploadFile = File(...)):
    """
    POST /ocr — reçoit une image d'ordonnance, retourne des blocs ordonnés.

    Le résultat préserve l'ordre de lecture spatial (block_order) et le type de bloc
    (block_label : text / table / figure / formula / seal).
    Les blocs "table" ont un block_content HTML (<table>…</table>).
    Les blocs "text" ont un block_content en texte plat.

    Ce résultat est transmis tel quel à Ollama pour normalisation JSON.
    NE PAS aplatir en texte brut avant d'envoyer à Ollama — l'ordre spatial est
    l'information critique pour associer nom médicament → dosage → posologie.
    """
    if not file.content_type or not file.content_type.startswith("image/"):
        raise HTTPException(status_code=400, detail="Image attendue (image/*)")

    data = await file.read()
    original_filename = file.filename or "ordonnance.jpg"

    tmp_path     = None
    resized_path = None
    try:
        with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
            tmp_path = tmp.name
            tmp.write(data)

        # Redimensionner avant inférence — obligatoire pour tenir dans --ctx-size 4096.
        # Photo iPhone 3024×4032 → ×7 moins de pixels → inférence ~2 min au lieu de 9.
        inference_path, did_resize = _resize_for_ocr(tmp_path)
        if did_resize:
            resized_path = inference_path

        pipeline = get_pipeline()
        results = pipeline.predict(inference_path)

    finally:
        if tmp_path is not None:
            Path(tmp_path).unlink(missing_ok=True)
        if resized_path is not None:
            Path(resized_path).unlink(missing_ok=True)

    # --- Extraction des blocs depuis parsing_res_list ---
    blocks = []
    markdown_lines = []

    raw_blocks: list = []
    if results:
        raw_blocks = results[0].json.get("parsing_res_list", [])

    for idx, blk in enumerate(raw_blocks, start=1):
        block_order   = blk.get("block_order",   idx)
        block_label   = str(blk.get("block_label", "text")).lower()
        block_content = blk.get("block_content", "") or ""
        block_bbox    = blk.get("block_bbox", [])

        # block_bbox peut être un np.ndarray — convertir en list Python.
        if isinstance(block_bbox, np.ndarray):
            block_bbox = block_bbox.tolist()

        # Normaliser en [x1, y1, x2, y2] si format 2D [[x1,y1],[x2,y1],[x2,y2],[x1,y2]]
        if block_bbox and not isinstance(block_bbox[0], (int, float)):
            try:
                xs = [pt[0] for pt in block_bbox]
                ys = [pt[1] for pt in block_bbox]
                block_bbox = [min(xs), min(ys), max(xs), max(ys)]
            except Exception:
                block_bbox = []

        blocks.append({
            "block_id":      idx,
            "block_order":   block_order,
            "block_label":   block_label,
            "block_content": block_content,
            "block_bbox":    [round(float(v), 1) for v in block_bbox] if block_bbox else [],
        })

        if block_label == "table":
            markdown_lines.append(block_content)
        else:
            markdown_lines.append(str(block_content))

    # Tri final par block_order pour garantir l'ordre de lecture
    blocks.sort(key=lambda b: b["block_order"])

    return JSONResponse({
        "filename":         original_filename,
        "blocks":           blocks,
        "markdown_preview": "\n\n".join(markdown_lines),
    })
