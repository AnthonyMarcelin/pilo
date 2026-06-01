"""
PaddleOCR-VL FastAPI service — pipeline deux conteneurs.

Architecture :
  Ce service (paddleocr-vl) utilise PaddleOCRVL (paddleocr==3.4.1) qui appelle
  llama-server (sidecar) pour l'inférence VLM via HTTP (API OpenAI-compatible).
  llama-server charge PaddleOCR-VL 1.6 GGUF depuis le volume vl_models.
  Ce conteneur gère la détection de layout (PP-DocLayoutV3, local) et l'agrégation.

  Classe utilisée : PaddleOCRVL (PAS PPStructureV3).
    PPStructureV3 = pipeline OCR traditionnel, sans backend VLM externe.
    PaddleOCRVL   = layout local + inférence VLM déportée (llama-cpp-server).

Redimensionnement obligatoire (MAX_SIDE = 1500) :
  PaddleOCR-VL est basé sur Qwen2-VL. Chaque patch 28×28 px = 1 token vision.
  Photo iPhone 3024×4032 → ~15 000 tokens → dépasse --ctx-size 4096 du llama-server
  → l'image est tronquée → AUCUN BLOC EXTRAIT malgré 9 min d'inférence.
  À 1500×1125 → ~2200 tokens vision + ~500 texte ≈ 2700 < 4096. ✓

Support PDF :
  POST /ocr accepte application/pdf en plus de image/*.
  Rasterisation via pypdfium2 (disponible via paddlex[ocr]).
  Chaque page est redimensionnée puis inférée séquentiellement.

Extraction multi-stratégies (PaddleOCRVLResult est une sous-classe de dict) :
  Stratégie 1 : result.json["parsing_res_list"] → list[dict] (chemin normal)
  Stratégie 2 : result["parsing_res_list"] → list[PaddleOCRVLBlock] (accès dict direct)
  Stratégie 3 : result.markdown["markdown_texts"] → bloc texte unique (fallback)
  Un logging [DIAG] détaillé est émis à chaque appel pour diagnostic.
  Visible avec : docker compose logs paddleocr-vl --tail=200
"""

import logging
import os
import tempfile
from pathlib import Path

import numpy as np
from PIL import Image as PILImage
from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.responses import JSONResponse

# Configure logging — WARNING pour que les [DIAG] apparaissent toujours dans uvicorn
logging.basicConfig(level=logging.WARNING, format="%(asctime)s %(levelname)s %(message)s")
_log = logging.getLogger("paddleocr_vl")

# URL BASE du sidecar llama-server (sans /v1 — PaddleOCRVL ajoute le chemin).
LLAMA_SERVER_URL = os.environ.get("LLAMA_SERVER_URL", "http://llama-server:8111")

# Longueur maximale du grand côté de l'image avant inférence VLM (pixels).
# 1500×1125 → 54×41 patches = 2214 tokens vision < ctx-size 4096. ✓
MAX_SIDE = 1500

app = FastAPI(title="PaddleOCR-VL service", version="1.0.0")

_pipeline = None


def get_pipeline():
    """Pipeline PaddleOCRVL lazy — initialisé une seule fois par process."""
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
    Redimensionne src_path si son grand côté > MAX_SIDE.
    Retourne (chemin_final, new_file_created).
    Si new_file_created=True, le nouveau tmp JPEG doit être supprimé après usage.
    """
    img = PILImage.open(src_path)
    w, h = img.size
    if max(w, h) <= MAX_SIDE:
        img.close()
        return src_path, False
    scale = MAX_SIDE / max(w, h)
    img = img.resize((round(w * scale), round(h * scale)), PILImage.LANCZOS)
    with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp_r:
        resized_path = tmp_r.name
    img.save(resized_path, "JPEG", quality=85)
    img.close()
    return resized_path, True


def _pdf_to_images(pdf_path: str) -> list:
    """
    Rasterise chaque page d'un PDF en JPEG tmp (pypdfium2, scale=2 ≈ 200 DPI).
    Retourne les chemins créés (tous à supprimer après usage).
    """
    import pypdfium2 as pdfium  # type: ignore[import]
    pdf = pdfium.PdfDocument(pdf_path)
    paths = []
    try:
        for i in range(len(pdf)):
            bitmap = pdf[i].render(scale=2)
            img = bitmap.to_pil()
            with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
                path = tmp.name
            img.save(path, "JPEG", quality=85)
            img.close()
            paths.append(path)
    finally:
        pdf.close()
    return paths


# ─── Extraction robuste du résultat PaddleOCRVL ──────────────────────────────
#
# PaddleOCRVLResult EST une sous-classe de dict (héritage via BaseCVResult).
# result["parsing_res_list"] → list[PaddleOCRVLBlock]  (accès dict brut)
# result.json["parsing_res_list"] → list[dict]          (après sérialisation)
# result.markdown["markdown_texts"] → str               (texte complet)
#
# On essaie les trois dans l'ordre, avec logging pour diagnostiquer.

def _block_obj_to_dict(blk) -> dict:
    """
    Convertit un PaddleOCRVLBlock (objet ou dict) en dict normalisé.
    Essaie d'abord .json, puis accès attributs directs.
    """
    if isinstance(blk, dict):
        return blk
    # Tentative via .json (peut échouer si dépendances manquantes)
    try:
        j = blk.json
        if isinstance(j, dict) and j:
            return j
    except Exception:
        pass
    # Accès attribut direct — les noms exacts varient selon la version
    return {
        "block_id":      getattr(blk, "block_id",      None),
        "block_order":   getattr(blk, "block_order",   None),
        "block_label":   getattr(blk, "block_label",   getattr(blk, "label", "text")),
        "block_content": getattr(blk, "block_content", getattr(blk, "content", "")),
        "block_bbox":    getattr(blk, "block_bbox",    getattr(blk, "bbox", [])),
    }


def _log_result_diag(result) -> None:
    """Émet un log [DIAG] complet sur la structure du result — visible dans docker logs."""
    try:
        _log.warning(f"[DIAG] type={type(result).__name__}  is_dict={isinstance(result, dict)}")

        # Accès dict brut
        if isinstance(result, dict):
            keys = sorted(result.keys())
            _log.warning(f"[DIAG] dict.keys()={keys}")
            prl = result.get("parsing_res_list")
            _log.warning(f"[DIAG] dict['parsing_res_list'] = {type(prl).__name__}  len={len(prl) if prl else 0}")
            if prl:
                b0 = prl[0]
                _log.warning(f"[DIAG] first block type={type(b0).__name__}")
                if isinstance(b0, dict):
                    _log.warning(f"[DIAG] first block keys={list(b0.keys())}")
                    _log.warning(f"[DIAG] first block_content={repr(str(b0.get('block_content',''))[:300])}")
                else:
                    attrs = [a for a in dir(b0) if not a.startswith("_")][:20]
                    _log.warning(f"[DIAG] first block attrs={attrs}")
                    for attr in ("block_content", "content", "block_label", "label"):
                        if hasattr(b0, attr):
                            _log.warning(f"[DIAG]   .{attr}={repr(str(getattr(b0, attr))[:200])}")

        # Propriété .json
        try:
            j = result.json
            _log.warning(f"[DIAG] .json type={type(j).__name__}")
            if isinstance(j, dict):
                _log.warning(f"[DIAG] .json.keys()={sorted(j.keys())}")
                # Chercher parsing_res_list avec et sans wrapper "res"
                inner = j.get("res", j) if "res" in j else j
                prl_j = inner.get("parsing_res_list", "MISSING")
                _log.warning(f"[DIAG] .json['parsing_res_list'] = {type(prl_j).__name__}  len={len(prl_j) if isinstance(prl_j, list) else 'N/A'}")
                if isinstance(prl_j, list) and prl_j:
                    b0 = prl_j[0]
                    _log.warning(f"[DIAG] json first block type={type(b0).__name__}  {'keys='+str(list(b0.keys())) if isinstance(b0, dict) else ''}")
                    if isinstance(b0, dict):
                        _log.warning(f"[DIAG] json first block_content={repr(str(b0.get('block_content',''))[:300])}")
        except Exception as e:
            _log.warning(f"[DIAG] .json ERROR: {e}")

        # Propriété .markdown
        try:
            md = result.markdown
            _log.warning(f"[DIAG] .markdown type={type(md).__name__}")
            if isinstance(md, dict):
                _log.warning(f"[DIAG] .markdown.keys()={list(md.keys())}")
                mt = md.get("markdown_texts", "")
                _log.warning(f"[DIAG] markdown_texts len={len(mt)}  preview={repr(mt[:500])}")
            else:
                _log.warning(f"[DIAG] .markdown={repr(str(md)[:500])}")
        except Exception as e:
            _log.warning(f"[DIAG] .markdown ERROR: {e}")

    except Exception as e:
        _log.warning(f"[DIAG] logging error: {e}")


def _extract_blocks_from_result(result) -> list:
    """
    Extrait les blocs d'un PaddleOCRVLResult avec 3 stratégies de repli.
    Retourne une liste de dicts normalisés {block_id, block_order, block_label,
    block_content, block_bbox} — ou [] si tout échoue.
    """
    # ── Stratégie 1 : result.json["parsing_res_list"] ────────────────────────
    # Chemin documenté : .json retourne un dict avec "parsing_res_list" → list[dict].
    # Certaines versions wrappent sous une clé "res" — on vérifie les deux.
    try:
        j = result.json
        if isinstance(j, dict):
            inner = j.get("res", j) if "res" in j else j
            prl = inner.get("parsing_res_list", [])
            if prl:
                blocks = prl if isinstance(prl[0], dict) else [_block_obj_to_dict(b) for b in prl]
                _log.warning(f"[EXTRACT] Stratégie 1 (.json) → {len(blocks)} blocs")
                return blocks
    except Exception as e:
        _log.warning(f"[EXTRACT] Stratégie 1 (.json) échouée : {e}")

    # ── Stratégie 2 : result["parsing_res_list"] (dict brut) ─────────────────
    # PaddleOCRVLResult IS un dict subclass — les blocs sont directement accessibles.
    # Retourne des PaddleOCRVLBlock objects (pas des dicts) — on les convertit.
    try:
        if isinstance(result, dict):
            prl = result.get("parsing_res_list", [])
            if prl:
                blocks = [_block_obj_to_dict(b) for b in prl]
                _log.warning(f"[EXTRACT] Stratégie 2 (dict direct) → {len(blocks)} blocs")
                return blocks
    except Exception as e:
        _log.warning(f"[EXTRACT] Stratégie 2 (dict) échouée : {e}")

    # ── Stratégie 3 : result.markdown (fallback texte plat) ──────────────────
    # Si les deux précédentes échouent, on envoie le texte markdown complet à Ollama
    # comme un seul bloc. Moins précis (pas de bbox, pas de labels) mais fonctionnel.
    try:
        md = result.markdown
        if isinstance(md, dict):
            text = md.get("markdown_texts", "")
        else:
            text = str(md)
        if text.strip():
            _log.warning(f"[EXTRACT] Stratégie 3 (markdown) → 1 bloc texte, {len(text)} chars")
            return [{"block_id": 1, "block_order": 1, "block_label": "text",
                     "block_content": text, "block_bbox": []}]
    except Exception as e:
        _log.warning(f"[EXTRACT] Stratégie 3 (markdown) échouée : {e}")

    _log.warning("[EXTRACT] Toutes les stratégies ont échoué — 0 blocs")
    return []


@app.get("/health")
def health():
    return {"status": "ok", "llama_server_url": LLAMA_SERVER_URL, "max_side": MAX_SIDE}


@app.post("/ocr")
async def run_ocr(file: UploadFile = File(...)):
    """
    POST /ocr — reçoit une image ou un PDF, retourne des blocs ordonnés.

    Accepte : image/* ou application/pdf.
    Pour PDF multi-pages, chaque page est traitée séquentiellement.
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

    tmp_path:      str | None = None
    pdf_img_paths: list       = []
    resized_paths: list       = []
    all_raw_blocks: list      = []

    try:
        suffix = ".pdf" if is_pdf else ".jpg"
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp_path = tmp.name
            tmp.write(data)

        image_paths = _pdf_to_images(tmp_path) if is_pdf else [tmp_path]
        if is_pdf:
            pdf_img_paths = image_paths

        pipeline = get_pipeline()

        for img_path in image_paths:
            inference_path, did_resize = _resize_for_ocr(img_path)
            if did_resize:
                resized_paths.append(inference_path)

            _log.warning(f"[OCR] predict() sur {inference_path}")
            results = pipeline.predict(inference_path)
            _log.warning(f"[OCR] predict() → type={type(results).__name__}  len={len(results) if hasattr(results, '__len__') else '?'}")

            if not results:
                _log.warning("[OCR] results vide — skip")
                continue

            # Log de diagnostic — voir docker compose logs paddleocr-vl
            _log_result_diag(results[0])

            # Extraction multi-stratégies
            page_blocks = _extract_blocks_from_result(results[0])

            # Décaler block_order pour conserver l'ordre inter-pages
            offset = len(all_raw_blocks)
            for blk in page_blocks:
                if isinstance(blk, dict):
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

    # ── Normalisation en contrat API ──────────────────────────────────────────
    blocks = []
    markdown_lines = []

    for idx, blk in enumerate(all_raw_blocks, start=1):
        if not isinstance(blk, dict):
            blk = _block_obj_to_dict(blk)

        # .get("block_order", idx) NE remplace PAS un None explicite dans le dict.
        # Certains blocs (images, formules) ont block_order=None → fallback sur idx.
        _raw_order    = blk.get("block_order")
        block_order   = _raw_order if _raw_order is not None else idx
        block_label   = str(blk.get("block_label", "text")).lower()
        block_content = blk.get("block_content", "") or ""
        block_bbox    = blk.get("block_bbox", [])

        if isinstance(block_bbox, np.ndarray):
            block_bbox = block_bbox.tolist()

        # Normaliser en [x1, y1, x2, y2] si format 2D
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

        markdown_lines.append(block_content if block_label == "table" else str(block_content))

    # Tri robuste aux None : None poussé en fin de liste (float("inf")).
    # Normalement plus de None ici grâce au fallback idx ci-dessus, mais garde de sécurité.
    blocks.sort(key=lambda b: b["block_order"] if b["block_order"] is not None else float("inf"))

    _log.warning(f"[OCR] réponse finale : {len(blocks)} blocs")

    return JSONResponse({
        "filename":         original_filename,
        "blocks":           blocks,
        "markdown_preview": "\n\n".join(markdown_lines),
    })
