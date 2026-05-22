#!/usr/bin/env python3
"""
Agent de contenu automatique - Les Arméniens & Arméniennes
Génère quotidiennement un post viral + image composite pour Facebook/Instagram via Dlvr.it RSS
"""

import os
import json
import math
import random
import requests
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

TOPICS = [
    {"topic": "Monastère de Tatev",                     "category": "Lieux sacrés"},
    {"topic": "Monastère de Geghard",                   "category": "Lieux sacrés"},
    {"topic": "Khor Virap",                             "category": "Lieux sacrés"},
    {"topic": "Noravank",                               "category": "Lieux sacrés"},
    {"topic": "Monastère de Haghpat",                   "category": "Lieux sacrés"},
    {"topic": "Monastère de Sanahin",                   "category": "Lieux sacrés"},
    {"topic": "Cathédrale d'Etchmiadzin",               "category": "Lieux sacrés"},
    {"topic": "Temple de Garni",                        "category": "Arménie antique"},
    {"topic": "Lac Sevan",                              "category": "Lieux à visiter"},
    {"topic": "Dilijan, la forêt arménienne",           "category": "Lieux à visiter"},
    {"topic": "Jermuk et ses sources thermales",        "category": "Lieux à visiter"},
    {"topic": "Gyumri, ville de culture",               "category": "Lieux à visiter"},
    {"topic": "Erevan, la ville rose",                  "category": "Lieux à visiter"},
    {"topic": "Khorovats, le BBQ arménien",             "category": "Gastronomie"},
    {"topic": "La Dolma arménienne",                    "category": "Gastronomie"},
    {"topic": "Harissa, plat de résistance",            "category": "Gastronomie"},
    {"topic": "Le Lavash sacré",                        "category": "Traditions"},
    {"topic": "Gata, douceur de grand-mère",            "category": "Gastronomie"},
    {"topic": "Basturma, viande d'exception",           "category": "Gastronomie"},
    {"topic": "Lahmajoun arménien",                     "category": "Gastronomie"},
    {"topic": "Pakhlava arménienne",                    "category": "Gastronomie"},
    {"topic": "Le café arménien",                       "category": "Traditions"},
    {"topic": "Tigranes le Grand",                      "category": "Histoire"},
    {"topic": "Vartan Mamikonian",                      "category": "Histoire"},
    {"topic": "La bataille d'Avarayr 451",              "category": "Histoire"},
    {"topic": "La Route de la Soie et l'Arménie",       "category": "Histoire"},
    {"topic": "Haïk, père de la nation arménienne",     "category": "Mythologie"},
    {"topic": "Ara le Beau et Sémiramis",               "category": "Mythologie"},
    {"topic": "Le Mont Ararat, symbole éternel",        "category": "Symboles"},
    {"topic": "Les Khachkars, croix de pierre",         "category": "Artisanat"},
    {"topic": "L'alphabet arménien de Mesrop Mashtots", "category": "Culture"},
    {"topic": "La danse Kochari",                       "category": "Traditions"},
    {"topic": "Les mariages arméniens traditionnels",   "category": "Traditions"},
    {"topic": "Vardavar, la fête de l'eau",             "category": "Fêtes"},
    {"topic": "Navasard, le Nouvel An arménien",        "category": "Fêtes"},
    {"topic": "Le duduk, instrument de l'âme",          "category": "Musique"},
    {"topic": "Les fêtes religieuses arméniennes",      "category": "Fêtes"},
    {"topic": "Charles Aznavour",                       "category": "Diaspora"},
    {"topic": "William Saroyan",                        "category": "Diaspora"},
    {"topic": "Atom Egoyan, cinéaste arménien",         "category": "Diaspora"},
    {"topic": "Les Arméniens à Paris",                  "category": "Diaspora"},
    {"topic": "La diaspora arménienne aux États-Unis",  "category": "Diaspora"},
    {"topic": "Les villages oubliés d'Arménie",         "category": "Mystères"},
    {"topic": "Les légendes du Lac Sevan",              "category": "Légendes"},
    {"topic": "Les coutumes de protection arméniennes", "category": "Traditions"},
]

# ─── Gestion des sujets utilisés ─────────────────────────────────────────────

def load_used_topics() -> list:
    path = Path("topics_used.json")
    return json.loads(path.read_text(encoding="utf-8")) if path.exists() else []

def save_used_topic(topic_name: str) -> None:
    used = load_used_topics()
    used.append({"topic": topic_name, "date": date.today().isoformat()})
    Path("topics_used.json").write_text(
        json.dumps(used[-90:], ensure_ascii=False, indent=2), encoding="utf-8"
    )

def pick_topic(used_topics: list) -> dict:
    recent    = {t["topic"] for t in used_topics[-30:]}
    available = [t for t in TOPICS if t["topic"] not in recent] or TOPICS
    return random.choice(available)

# ─── Génération du contenu (GPT-4o) ──────────────────────────────────────────

SYSTEM_PROMPT = """Tu es un expert en storytelling viral et culture arménienne pour la page "Les Arméniens & Arméniennes".
Ton style : fier, inspirant, mystérieux, cinématographique, émotionnel. Jamais scolaire.
Réponds UNIQUEMENT en JSON valide, sans markdown."""

def generate_content(topic: str, category: str) -> dict:
    prompt = f"""Génère un post viral pour la page Facebook/Instagram "Les Arméniens & Arméniennes".

Sujet : {topic}
Catégorie : {category}

JSON avec ces clés exactes :
{{
  "titre": "titre viral (max 8 mots, très émotionnel, intrigant)",
  "post": "texte du post (150-300 mots, hook puissant, storytelling, quelques emojis, question engageante finale)",
  "cta": "call to action court",
  "hashtags": "20 hashtags séparés par espaces, mix français/anglais, inclure #Armenie #Armenian #Armenia",
  "image_prompt": "prompt DALL-E en anglais uniquement. Scène visuelle épique représentant {topic}. Style photo cinématographique ultra-réaliste. Lumière dramatique dorée ou crépusculaire. Aucun texte, aucun titre, aucun logo dans l'image. Juste la scène."
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

def _wrap_text(draw: ImageDraw.Draw, text: str, font: ImageFont.FreeTypeFont, max_w: int) -> list[str]:
    words, lines, current = text.split(), [], []
    for word in words:
        test = " ".join(current + [word])
        if draw.textbbox((0, 0), test, font=font)[2] > max_w and current:
            lines.append(" ".join(current))
            current = [word]
        else:
            current.append(word)
    if current:
        lines.append(" ".join(current))
    return lines

def _draw_gradient(canvas: Image.Image, y_start: int, height: int) -> Image.Image:
    overlay = Image.new("RGBA", (W, height), (0, 0, 0, 0))
    d = ImageDraw.Draw(overlay)
    for i in range(height):
        alpha = int(255 * math.sqrt(i / (height - 1)))
        d.line([(0, i), (W, i)], fill=(*BG, alpha))
    base = canvas.convert("RGBA")
    base.paste(overlay, (0, y_start), mask=overlay.split()[3])
    return base.convert("RGB")

def compose_image(raw_photo_path: str, titre: str, category: str, output_path: str) -> None:
    # ── 1. Canvas + photo ───────────────────────────────────────────────────
    canvas = Image.new("RGB", (W, H), BG)
    photo  = Image.open(raw_photo_path).convert("RGB")
    pw, ph = photo.size
    scale  = max(W / pw, (PHOTO_H + 60) / ph)
    photo  = photo.resize((int(pw * scale), int(ph * scale)), Image.LANCZOS)
    ox     = (photo.width - W) // 2
    photo  = photo.crop((ox, 0, ox + W, PHOTO_H + 60))
    canvas.paste(photo, (0, 0))

    # ── 2. Gradient photo → noir ────────────────────────────────────────────
    canvas = _draw_gradient(canvas, PHOTO_H - 100, 160)
    draw   = ImageDraw.Draw(canvas)

    # ── 3. Badge catégorie (trapèze rouge, bas-gauche de la photo) ──────────
    font_cat  = _font(28)
    badge_txt = category.upper()
    bb        = draw.textbbox((0, 0), badge_txt, font=font_cat)
    bw        = bb[2] - bb[0] + 44
    by        = PHOTO_H - 58
    bh        = 46
    draw.polygon([(0, by), (bw + 18, by), (bw, by + bh), (0, by + bh)], fill=RED_BADGE)
    draw.text((16, by + 10), badge_txt, font=font_cat, fill=WHITE)

    # ── 4. Titre ─────────────────────────────────────────────────────────────
    font_lg = _font(84)
    font_sm = _font(68)
    lines   = _wrap_text(draw, titre.upper(), font_lg, W - MARGIN * 2)

    ty = PHOTO_H + 28
    # Couleurs : ligne 1 et dernière → ORANGE, les autres → BLANC
    # Ligne du milieu → highlight bleu
    mid = len(lines) // 2
    for i, line in enumerate(lines[:6]):
        font  = font_lg if i < 4 else font_sm
        color = ORANGE if (i == 0 or i == len(lines) - 1) else WHITE
        bb    = draw.textbbox((MARGIN, ty), line, font=font)
        lh    = bb[3] - bb[1]

        if i == mid and len(lines) >= 3:
            pad = 10
            draw.rectangle(
                [(MARGIN - pad, ty - pad // 2), (bb[2] + pad, ty + lh + pad // 2)],
                fill=BLUE_HL,
            )

        draw.text((MARGIN, ty), line, font=font, fill=color)
        ty += lh + 14

    # ── 5. Logo haut-gauche (logo_square.png = logo carré fond noir) ────────
    sq_path = Path("assets/logo_square.png")
    if sq_path.exists():
        sq   = Image.open(sq_path).convert("RGBA").resize((105, 105), Image.LANCZOS)
        mask = Image.new("L", (105, 105), 0)
        ImageDraw.Draw(mask).ellipse([(0, 0), (104, 104)], fill=255)
        sq.putalpha(mask)
        canvas.paste(sq, (MARGIN, MARGIN), mask=sq.split()[3])
    else:
        cx, cy, cr = MARGIN, MARGIN, 40
        draw.ellipse([(cx, cy), (cx + cr * 2, cy + cr * 2)], fill=WHITE)
        draw.text((cx + 12, cy + 14), "LA", font=_font(22), fill=BG)

    # ── 6. Bannière logo bas (logo_banner.png = bannière horizontale) ────────
    banner_path = Path("assets/logo_banner.png")
    bar_y       = H - 140

    if banner_path.exists():
        banner   = Image.open(banner_path).convert("RGBA")
        # Conserver le ratio — largeur pleine page, hauteur proportionnelle
        bw_target = W - 80
        bh_target = int(banner.height * bw_target / banner.width)
        banner    = banner.resize((bw_target, bh_target), Image.LANCZOS)
        bx        = (W - bw_target) // 2
        by        = H - bh_target - 30
        canvas.paste(banner, (bx, by), mask=banner.split()[3])
    else:
        # Fallback : barre blanche avec texte
        bar_h, bar_mx = 68, 80
        draw.rectangle([(bar_mx, bar_y), (W - bar_mx, bar_y + bar_h)], fill=WHITE)
        fb  = _font(26)
        txt = "Les Arméniens & Arméniennes"
        bb  = draw.textbbox((0, 0), txt, font=fb)
        tx  = (W - (bb[2] - bb[0])) // 2
        draw.text((tx, bar_y + (bar_h - (bb[3] - bb[1])) // 2), txt, font=fb, fill=BG)

    canvas.save(output_path, "PNG")
    print(f"  ✔ Compositing terminé → {output_path}")

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

    description = f"{post_text}\n\n{cta}\n\n{hashtags}"
    item = ET.Element("item")
    ET.SubElement(item, "title").text       = titre
    ET.SubElement(item, "description").text = description
    ET.SubElement(item, "pubDate").text     = formatdate(usegmt=True)
    ET.SubElement(item, "guid").text        = f"armenians-{date.today().isoformat()}"
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
    used   = load_used_topics()
    chosen = pick_topic(used)
    topic, category = chosen["topic"], chosen["category"]

    print(f"📅 {date.today()}  |  {topic}  ({category})")

    content = generate_content(topic, category)
    print(f"✅ Texte : {content['titre']}")

    image_filename = f"{date.today().isoformat()}.png"
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
    print(f"💾 Sujet sauvegardé")

if __name__ == "__main__":
    main()
