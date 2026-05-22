#!/usr/bin/env python3
"""
Agent de contenu automatique - Les Arméniens & Arméniennes
Génère quotidiennement un post viral + image composite pour Facebook/Instagram via Dlvr.it RSS
"""

import os
import json
import math
import requests
import time
from datetime import date
from email.utils import formatdate
from pathlib import Path
from xml.etree import ElementTree as ET

from openai import OpenAI
from PIL import Image, ImageDraw, ImageFont

REPO_OWNER = os.environ["REPO_OWNER"]
REPO_NAME  = os.environ["REPO_NAME"]
client     = OpenAI(api_key=os.environ["OPENAI_API_KEY"])

# ─── Layout constants ─────────────────────────────────────────────────────────

W, H       = 1080, 1350   # 4:5 Facebook / Instagram
PHOTO_H    = 560           # hauteur de la photo en haut
BG         = (12, 12, 12)
ORANGE     = (255, 145, 0)
WHITE      = (255, 255, 255)
RED_BADGE  = (195, 20, 20)
BLUE_HL    = (18, 38, 115)
MARGIN     = 32

# Chemins de polices (Ubuntu GitHub Actions + macOS fallback)
_FONT_PATHS = [
    "/usr/share/fonts/truetype/ubuntu/Ubuntu-B.ttf",
    "/usr/share/fonts/truetype/ubuntu/Ubuntu-Bold.ttf",
    "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    "/System/Library/Fonts/Supplemental/Impact.ttf",
    "/System/Library/Fonts/Helvetica.ttc",
]

def _font(size: int) -> ImageFont.FreeTypeFont:
    for path in _FONT_PATHS:
        try:
            return ImageFont.truetype(path, size)
        except (IOError, OSError):
            continue
    return ImageFont.load_default()

# ─── Banque de sujets ─────────────────────────────────────────────────────────

# ─── Dates critiques arméniennes (priorité absolue) ──────────────────────────

CRITICAL_DATES = {
    (4, 24): "Journée de commémoration du Génocide arménien — 1,5 million de martyrs",
    (5, 28): "Fête de l'Indépendance de la Première République d'Arménie (28 mai 1918)",
    (9, 21): "Fête nationale de l'Arménie — Indépendance (21 septembre 1991)",
    (1,  6): "Noël arménien apostolique — Nativité et Épiphanie",
    (8, 11): "Navasard — Nouvel An arménien traditionnel",
}

# ─── Rotation thématique hebdomadaire ────────────────────────────────────────

WEEKLY_THEMES = {
    0: "Histoire arménienne",           # Lundi
    1: "Culture & Traditions",          # Mardi
    2: "Légendes & Mythologie",         # Mercredi
    3: "Gastronomie arménienne",        # Jeudi
    4: "Diaspora & Personnalités",      # Vendredi
    5: "Lieux à visiter en Arménie",    # Samedi
    6: "Spiritualité & Lieux sacrés",   # Dimanche
}

# ─── Gestion des sujets utilisés ─────────────────────────────────────────────

def load_used_topics() -> list:
    path = Path("topics_used.json")
    return json.loads(path.read_text(encoding="utf-8")) if path.exists() else []

def save_used_topic(topic_name: str) -> None:
    used = load_used_topics()
    used.append({"topic": topic_name, "date": date.today().isoformat()})
    Path("topics_used.json").write_text(
        json.dumps(used[-365:], ensure_ascii=False, indent=2), encoding="utf-8"
    )

# ─── Génération du contenu (GPT-4o) ──────────────────────────────────────────

SYSTEM_PROMPT = """Tu es l'éditorialiste de la page "Les Arméniens & Arméniennes".
Ton style : fier, inspirant, mystérieux, cinématographique, émotionnel. Jamais scolaire.
Réponds UNIQUEMENT en JSON valide, sans markdown."""

def generate_content(today: date, used_topics: list) -> dict:
    day_names    = ["lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche"]
    day_name     = day_names[today.weekday()]
    month_day    = (today.month, today.day)
    weekly_theme = WEEKLY_THEMES[today.weekday()]
    recent_str   = ", ".join(t["topic"] for t in used_topics[-365:]) or "aucun"
    critical     = CRITICAL_DATES.get(month_day)

    if critical:
        sujet_instruction = f"""🔴 DATE PRIORITAIRE — Tu DOIS écrire sur ce sujet aujourd'hui :
{critical}
Traite ce sujet avec solennité, émotion et profondeur historique."""
    else:
        sujet_instruction = f"""Aujourd'hui est le {today.day} {today.strftime("%B")} {today.year} ({day_name}).

ÉTAPE 1 — Vérifie dans ta connaissance : y a-t-il un événement arménien notable lié au {today.day} {today.strftime("%B")} \
(naissance ou disparition d'une personnalité arménienne, événement historique survenu ce jour précis) ?
→ Si oui : écris sur cet événement.

ÉTAPE 2 — Si aucun événement notable ce jour précis : le thème du jour est "{weekly_theme}".
Choisis un sujet original, inspirant, peu connu si possible."""

    prompt = f"""{sujet_instruction}

Sujets récents à NE PAS répéter : {recent_str}

STRUCTURE OBLIGATOIRE du champ "post" (respecte les sauts de ligne) :
1. ACCROCHE (1-2 phrases choc) — fait découvrir quelque chose de surprenant, débute par une question ou un fait renversant
2. Ligne vide
3. DÉVELOPPEMENT (2-3 paragraphes séparés par des lignes vides) — storytelling éducatif, chiffres ou dates marquants, anecdote sensorielle ou historique méconnue
4. Ligne vide
5. CHUTE ÉMOTIONNELLE (1-2 phrases) — fierté, mystère ou émotion forte qui donne envie de partager
6. Ligne vide
7. QUESTION D'ENGAGEMENT — question courte et directe pour les commentaires

Style : cinématographique, fier, mystérieux. Jamais scolaire ni encyclopédique. 2-3 emojis max, bien placés. 280-350 mots.

JSON avec ces clés exactes :
{{
  "sujet": "sujet choisi en 3-6 mots (mémorisation anti-doublon)",
  "categorie": "catégorie du sujet",
  "titre": "titre viral (max 7 mots, mystérieux ou émotionnel, donne envie de lire)",
  "post": "texte structuré comme décrit ci-dessus, avec \\n\\n entre chaque section",
  "cta": "call to action ultra-court (max 8 mots)",
  "hashtags": "20 hashtags séparés par espaces, mix français/anglais, inclure #Armenie #Armenian #Armenia #Culture",
  "image_prompt": "prompt en anglais uniquement. Scène visuelle épique et cinématographique. Ultra-réaliste, lumière dorée dramatique, profondeur de champ. Aucun texte, aucun titre, aucune personne visible, aucun logo. Juste la scène représentant le sujet choisi."
}}"""

    response = client.chat.completions.create(
        model="gpt-4o",
        messages=[
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user",   "content": prompt},
        ],
        temperature=0.85,
        response_format={"type": "json_object"},
    )
    return json.loads(response.choices[0].message.content)

# ─── Compositing image (Pillow) ───────────────────────────────────────────────
#
#  Layout exact (ref: Les Arméniens & Arméniennes)
#
#  ┌─────────────────────────────────┐  y=0
#  │ [logo_square ~140px]            │  haut-gauche
#  │  ┌───────────────────────────┐  │
#  │  │   PHOTO  (cadre blanc)    │  │  y=155 → y=700
#  │  └───────────────────────────┘  │
#  │                                 │
#  │   TITRE ACCROCHEUR              │  grand, blanc, centré
#  │                                 │
#  │  ─────────────────────────────  │
#  │  [○]  LES ARMÉNIENS & ...       │  bannière logo bas
#  │  ─────────────────────────────  │
#  └─────────────────────────────────┘  y=1350

# ── dimensions internes ───────────────────────────────────────────────────────
_LOGO_SQ   = 150    # taille logo carré haut-gauche
_PHOTO_M   = 22     # marge autour de la photo
_PHOTO_FR  = 8      # épaisseur cadre blanc
_PHOTO_Y   = 160    # y début photo
_PHOTO_H   = 520    # hauteur photo
_BANNER_H  = 200    # hauteur cible bannière bas (crop centré)

def _wrap_text(draw, text, font, max_w):
    words, lines, cur = text.split(), [], []
    for w in words:
        test = " ".join(cur + [w])
        if draw.textbbox((0, 0), test, font=font)[2] > max_w and cur:
            lines.append(" ".join(cur)); cur = [w]
        else:
            cur.append(w)
    if cur: lines.append(" ".join(cur))
    return lines

def compose_image(raw_photo_path: str, titre: str, category: str, output_path: str) -> None:
    canvas = Image.new("RGB", (W, H), BG)
    draw   = ImageDraw.Draw(canvas)

    # ── 1. Photo pleine largeur (sans cadre blanc) ────────────────────────────
    py0  = _PHOTO_Y
    py1  = _PHOTO_Y + _PHOTO_H
    ph_w = W
    ph_h = _PHOTO_H

    photo  = Image.open(raw_photo_path).convert("RGB")
    pw, pph = photo.size
    scale  = max(ph_w / pw, ph_h / pph)
    photo  = photo.resize((int(pw * scale), int(pph * scale)), Image.LANCZOS)
    ox     = (photo.width  - ph_w) // 2
    oy     = (photo.height - ph_h) // 2
    photo  = photo.crop((ox, oy, ox + ph_w, oy + ph_h))
    canvas.paste(photo, (0, py0))

    # Gradient subtil bas de la photo → fond noir
    grad = Image.new("RGBA", (W, 80), (0, 0, 0, 0))
    gd   = ImageDraw.Draw(grad)
    for i in range(80):
        gd.line([(0, i), (W, i)], fill=(*BG, int(255 * (i / 79))))
    base = canvas.convert("RGBA")
    base.paste(grad, (0, py1 - 80), mask=grad.split()[3])
    canvas = base.convert("RGB")
    draw   = ImageDraw.Draw(canvas)

    # ── 2. Logo haut-gauche ROND ──────────────────────────────────────────────
    sq_path = Path("assets/logo_square.png")
    s       = _LOGO_SQ
    if sq_path.exists():
        sq   = Image.open(sq_path).convert("RGBA").resize((s, s), Image.LANCZOS)
    else:
        # fallback : cercle drapeau arménien
        sq  = Image.new("RGBA", (s, s), (0, 0, 0, 0))
        sd  = ImageDraw.Draw(sq)
        r   = s // 2 - 2
        cx  = cy = s // 2
        third = (r * 2) // 3
        top   = cy - r
        for i, col in enumerate([RED_BADGE, (82, 82, 170), ORANGE]):
            sd.rectangle([(cx-r, top+i*third), (cx+r, top+(i+1)*third+2)], fill=(*col, 255))
        mask_tmp = Image.new("L", (s, s), 0)
        ImageDraw.Draw(mask_tmp).ellipse([(cx-r, cy-r), (cx+r, cy+r)], fill=255)
        sq.putalpha(mask_tmp)

    # masque circulaire sur le logo (rond parfait)
    circ_mask = Image.new("L", (s, s), 0)
    ImageDraw.Draw(circ_mask).ellipse([(0, 0), (s - 1, s - 1)], fill=255)
    sq.putalpha(circ_mask)
    canvas.paste(sq.convert("RGB"), (14, 14), mask=circ_mask)

    # ── 3. Titre (grand, blanc, centré) ──────────────────────────────────────
    title_area_y = py1 + 30
    title_area_h = H - _BANNER_H - title_area_y - 10
    max_w        = W - MARGIN * 2

    # taille de police adaptative
    for fsize in [108, 92, 78, 66]:
        fnt   = _font(fsize)
        lines = _wrap_text(draw, titre.upper(), fnt, max_w)
        total = sum(draw.textbbox((0,0), l, font=fnt)[3] - draw.textbbox((0,0), l, font=fnt)[1] + 18
                    for l in lines)
        if total <= title_area_h or fsize == 66:
            break

    ty = title_area_y + (title_area_h - total) // 2
    for line in lines:
        bb  = draw.textbbox((0, 0), line, font=fnt)
        lh  = bb[3] - bb[1]
        tx  = (W - (bb[2] - bb[0])) // 2
        draw.text((tx, ty), line, font=fnt, fill=WHITE)
        ty += lh + 18

    # ── 4. Bannière logo bas (crop centré, hauteur fixe _BANNER_H) ───────────
    banner_path = Path("assets/logo_banner.png")
    if banner_path.exists():
        banner  = Image.open(banner_path).convert("RGB")
        # Mise à l'échelle pleine largeur
        scale   = W / banner.width
        bw      = W
        bh_raw  = int(banner.height * scale)
        banner  = banner.resize((bw, bh_raw), Image.LANCZOS)
        # Crop centré verticalement pour garder le cercle + texte sans étirement
        if bh_raw > _BANNER_H:
            oy     = (bh_raw - _BANNER_H) // 2
            banner = banner.crop((0, oy, bw, oy + _BANNER_H))
        # Harmoniser fond bannière avec le canvas : les pixels sombres (JPEG ≈20)
        # sont remappés à BG (12,12,12) pour supprimer la seam visible
        r_lut = [BG[0] if v < 28 else v for v in range(256)]
        g_lut = [BG[1] if v < 28 else v for v in range(256)]
        b_lut = [BG[2] if v < 28 else v for v in range(256)]
        r, g, b = banner.split()
        banner = Image.merge("RGB", [r.point(r_lut), g.point(g_lut), b.point(b_lut)])
        by = H - _BANNER_H
        canvas.paste(banner, (0, by))
    else:
        by = H - 100
        draw.line([(MARGIN, by),     (W - MARGIN, by)],     fill=WHITE, width=2)
        draw.line([(MARGIN, by + 80),(W - MARGIN, by + 80)], fill=WHITE, width=2)
        fb  = _font(28)
        txt = "Les Arméniens & Arméniennes"
        bb  = draw.textbbox((0, 0), txt, font=fb)
        draw.text(((W - bb[2] + bb[0]) // 2, by + 18), txt, font=fb, fill=WHITE)

    canvas.save(output_path, "PNG")
    print(f"  ✔ Compositing → {output_path}")

# ─── Génération photo + compositing ──────────────────────────────────────────

def generate_image(image_prompt: str, titre: str, category: str, filename: str) -> None:
    import base64
    clean_prompt = (
        image_prompt
        + " Photorealistic, cinematic, ultra-detailed. NO text, NO title, NO logo, NO overlay of any kind."
    )
    response = client.images.generate(
        model="gpt-image-1",
        prompt=clean_prompt,
        size="1024x1536",   # portrait, proche 4:5
        quality="high",
        n=1,
    )
    raw_bytes = base64.b64decode(response.data[0].b64_json)
    raw_path  = Path("images") / f"_raw_{filename}"
    raw_path.parent.mkdir(exist_ok=True)
    raw_path.write_bytes(raw_bytes)

    compose_image(str(raw_path), titre, category, str(Path("images") / filename))
    raw_path.unlink()

# ─── Flux RSS ─────────────────────────────────────────────────────────────────

FEED_PATH = Path("feed.xml")
MAX_ITEMS = 10

def update_rss_feed(titre: str, post_text: str, hashtags: str, cta: str, image_filename: str) -> None:
    image_url = (
        f"https://raw.githubusercontent.com/{REPO_OWNER}/{REPO_NAME}/main/images/{image_filename}"
    )

    if FEED_PATH.exists() and FEED_PATH.stat().st_size > 200:
        tree    = ET.parse(FEED_PATH)
        root    = tree.getroot()
        channel = root.find("channel")
    else:
        root    = ET.Element("rss", version="2.0")
        channel = ET.SubElement(root, "channel")
        ET.SubElement(channel, "title").text       = "Les Arméniens & Arméniennes"
        ET.SubElement(channel, "link").text        = image_url.rsplit("/", 1)[0] + "/feed.xml"
        ET.SubElement(channel, "description").text = "Contenu culturel arménien quotidien"
        ET.SubElement(channel, "language").text    = "fr"

    # Titre mis en forme dans le corps + séparateur avant hashtags
    # <title> vide → dlvr.it ne préfixe pas le titre dans le post social
    description = f"✨ {titre}\n\n{post_text}\n\n👉 {cta}\n\n{hashtags}"
    item = ET.Element("item")
    ET.SubElement(item, "title").text       = " "
    ET.SubElement(item, "description").text = description
    ET.SubElement(item, "pubDate").text     = formatdate(usegmt=True)
    ET.SubElement(item, "guid").text        = f"armenians-{date.today().isoformat()}-{int(time.time())}"
    ET.SubElement(item, "enclosure", url=image_url, type="image/png", length="0")

    existing = channel.findall("item")
    for old in existing[MAX_ITEMS - 1:]:
        channel.remove(old)
    pos = list(channel).index(existing[0]) if existing else len(list(channel))
    channel.insert(pos, item)

    out = ET.ElementTree(root)
    ET.indent(out, space="  ")
    out.write(FEED_PATH, encoding="unicode", xml_declaration=True)

# ─── Main ─────────────────────────────────────────────────────────────────────

def main() -> None:
    today   = date.today()
    used    = load_used_topics()

    content  = generate_content(today, used)
    topic    = content["sujet"]
    category = content["categorie"]

    print(f"📅 {today}  |  {topic}  ({category})")
    print(f"✅ Texte : {content['titre']}")

    image_filename = f"{today.isoformat()}-{int(time.time())}.png"
    generate_image(content["image_prompt"], content["titre"], category, image_filename)
    print(f"🎨 Image : images/{image_filename}")

    update_rss_feed(
        titre          = content["titre"],
        post_text      = content["post"],
        hashtags       = content["hashtags"],
        cta            = content["cta"],
        image_filename = image_filename,
    )
    print("📡 RSS mis à jour")

    save_used_topic(topic)
    print(f"💾 Sujet sauvegardé : {topic}")

if __name__ == "__main__":
    main()
