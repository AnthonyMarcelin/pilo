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

Support PDF :
  POST /ocr accepte application/pdf en plus de image/*.
  Rasterisation via pypdfium2 (disponible via paddlex[ocr]) : scale=2 ≈ 200 DPI.
  Chaque page est redimensionnée (MAX_SIDE) puis inférée séquentiellement.
  block_order décalé entre pages pour maintenir l'ordre de lecture global.

Contrat API POST /ocr :
  Accepte : image/* (JPEG, PNG, WebP, …) ou application/pdf.
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


def _pdf_to_images(pdf_path: str) -> list:
    """
    Rasterise chaque page d'un PDF en fichier JPEG temporaire.
    Retourne la liste des chemins créés (tous à supprimer après usage).

    pypdfium2 est disponible via paddlex[ocr]==3.4.3 (dépendance directe pypdfium2>=4).
    scale=2 → ~200 DPI pour une page A4 standard (~1654×2339 px avant redim par MAX_SIDE).
    Le redimensionnement MAX_SIDE est appliqué ensuite par _resize_for_ocr().
    """
    import pypdfium2 as pdfium  # type: ignore[import]

    pdf = pdfium.PdfDocument(pdf_path)
    paths = []
    try:
        for i in range(len(pdf)):
            page = pdf[i]
            bitmap = page.render(scale=2)
            img = bitmap.to_pil()
            with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
                path = tmp.name
            img.save(path, "JPEG", quality=85)
            img.close()
            paths.append(path)
    finally:
        pdf.close()
    return paths


@app.get("/health")
def health():
    """Health-check — retourne 200 quand le service est démarré."""
    return {"status": "ok", "llama_server_url": LLAMA_SERVER_URL, "max_side": MAX_SIDE}


@app.post("/ocr")
async def run_ocr(file: UploadFile = File(...)):
    """
    POST /ocr — reçoit une image ou un PDF d'ordonnance, retourne des blocs ordonnés.

    Accepte : image/* (JPEG, PNG, WebP…) ou application/pdf.
    Pour les PDF multi-pages, chaque page est traitée séquentiellement.
    Le block_order des pages suivantes est décalé pour maintenir l'ordre global.

    Ce résultat est transmis tel quel à Ollama pour normalisation JSON.
    NE PAS aplatir en texte brut avant d'envoyer à Ollama — l'ordre spatial est
    l'information critique pour associer nom médicament → dosage → posologie.
    """
    is_pdf   = (file.content_type == "application/pdf")
    is_image = bool(file.content_type and file.content_type.startswith("image/"))

    if not (is_pdf or is_image):
        raise HTTPException(
            status_code=400,
            detail=f"Image (image/*) ou PDF (application/pdf) attendu. Reçu : {file.content_type}",
        )

    data = await file.read()
    original_filename = file.filename or ("ordonnance.pdf" if is_pdf else "ordonnance.jpg")

    tmp_path:      str | None = None   # fichier uploadé brut
    pdf_img_paths: list       = []     # fichiers JPEG issus de la rasterisation PDF
    resized_paths: list       = []     # fichiers JPEG redimensionnés
    all_raw_blocks: list      = []

    try:
        suffix = ".pdf" if is_pdf else ".jpg"
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp_path = tmp.name
            tmp.write(data)

        # Construire la liste des images à inférer (1 pour image, N pour PDF N pages)
        if is_pdf:
            image_paths    = _pdf_to_images(tmp_path)
            pdf_img_paths  = image_paths
        else:
            image_paths = [tmp_path]

        pipeline = get_pipeline()

        for img_path in image_paths:
            # Redimensionner avant inférence — obligatoire pour tenir dans --ctx-size 4096.
            inference_path, did_resize = _resize_for_ocr(img_path)
            if did_resize:
                resized_paths.append(inference_path)

            results = pipeline.predict(inference_path)
            if not results:
                continue

            page_blocks = results[0].json.get("parsing_res_list", [])

            # Décaler block_order : les blocs des pages suivantes s'ordonnent après
            # ceux des pages précédentes (offset = nombre total de blocs déjà collectés).
            offset = len(all_raw_blocks)
            for blk in page_blocks:
                order = blk.get("block_order")
                if order is not None:
                    blk["block_order"] = order + offset

            all_raw_blocks.extend(page_blocks)

    finally:
        if tmp_path is not None:
            Path(tmp_path).unlink(missing_ok=True)
        for p in pdf_img_paths:
            Path(p).unlink(missing_ok=True)
        for p in resized_paths:
            Path(p).unlink(missing_ok=True)

    # --- Normalisation des blocs en contrat API ---
    blocks = []
    markdown_lines = []

    for idx, blk in enumerate(all_raw_blocks, start=1):
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
