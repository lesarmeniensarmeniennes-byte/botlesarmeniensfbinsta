#!/usr/bin/env python3
"""Génère logo_square.png et logo_banner.png — Les Arméniens & Arméniennes"""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

BG     = (13,  13,  13 )
RED    = (204, 0,   0  )
BLUE   = (82,  82,  170)
ORANGE = (228, 135, 42 )
WHITE  = (255, 255, 255)

FONT_PATHS = [
    "/System/Library/Fonts/Supplemental/Georgia.ttf",
    "/System/Library/Fonts/Supplemental/Times New Roman.ttf",
    "/usr/share/fonts/truetype/ubuntu/Ubuntu-B.ttf",
    "/System/Library/Fonts/Helvetica.ttc",
]
def font(size):
    for p in FONT_PATHS:
        try: return ImageFont.truetype(p, size)
        except: pass
    return ImageFont.load_default()

def flag_circle(size, r):
    """Retourne une image RGBA : cercle drapeau arménien centré."""
    W = H = size
    cx = cy = size // 2

    # 1. fond transparent
    img  = Image.new("RGBA", (W, H), (0, 0, 0, 0))

    # 2. dessin des 3 bandes dans un calque séparé
    bands = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    bd    = ImageDraw.Draw(bands)
    third = (r * 2) // 3
    top   = cy - r
    for i, color in enumerate([RED, BLUE, ORANGE]):
        y0 = top + i * third
        y1 = y0 + third + 2
        bd.rectangle([(cx - r, y0), (cx + r, y1)], fill=(*color, 255))

    # 3. masque circulaire
    mask = Image.new("L", (W, H), 0)
    ImageDraw.Draw(mask).ellipse(
        [(cx - r, cy - r), (cx + r, cy + r)], fill=255
    )

    # 4. appliquer le masque sur les bandes
    bands.putalpha(mask)
    img.paste(bands, (0, 0), mask=bands.split()[3])
    return img

# ── logo_square.png ──────────────────────────────────────────────────────────
def make_square(path="assets/logo_square.png"):
    S  = 800
    r  = 280
    cy = 315
    cx = S // 2

    canvas = Image.new("RGB", (S, S), BG)

    # cercle drapeau
    circle = flag_circle(S, r)
    # repositionner si cy != S//2 : on crée avec cy = S//2 puis on colle
    circle_img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    raw = flag_circle(r * 2 + 4, r)
    circle_img.paste(raw, (cx - r - 2, cy - r - 2), mask=raw.split()[3])
    canvas.paste(circle_img.convert("RGB"),
                 mask=circle_img.split()[3])

    draw = ImageDraw.Draw(canvas)
    f    = font(66)
    for txt, y in [("LES ARMÉNIENS", 642), ("& ARMÉNIENNES", 718)]:
        bb = draw.textbbox((0, 0), txt, font=f)
        draw.text(((S - bb[2] + bb[0]) // 2, y), txt, font=f, fill=WHITE)

    canvas.save(path)
    print(f"✅ {path}")

# ── logo_banner.png ──────────────────────────────────────────────────────────
def make_banner(path="assets/logo_banner.png"):
    W, H = 1600, 360
    r    = 130
    cx   = 90 + r
    cy   = H // 2

    canvas = Image.new("RGB", (W, H), BG)

    # cercle drapeau
    diam = r * 2 + 4
    raw  = flag_circle(diam, r)
    canvas.paste(
        Image.new("RGB", (diam, diam), BG),
        (cx - r - 2, cy - r - 2)
    )
    canvas.paste(raw.convert("RGB"),
                 (cx - r - 2, cy - r - 2),
                 mask=raw.split()[3])

    draw = ImageDraw.Draw(canvas)

    # lignes horizontales
    lm = 55
    draw.line([(lm, 42),  (W - lm, 42)],  fill=WHITE, width=2)
    draw.line([(lm, 318), (W - lm, 318)], fill=WHITE, width=2)

    # texte
    f  = font(88)
    tx = cx + r + 55
    draw.text((tx, 80),  "LES ARMÉNIENS",  font=f, fill=WHITE)
    draw.text((tx, 188), "& ARMÉNIENNES",  font=f, fill=WHITE)

    canvas.save(path)
    print(f"✅ {path}")

if __name__ == "__main__":
    Path("assets").mkdir(exist_ok=True)
    make_square()
    make_banner()
