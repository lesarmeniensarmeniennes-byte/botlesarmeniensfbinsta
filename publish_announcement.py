#!/usr/bin/env python3
"""
Publie le post d'annonce dans le RSS feed → dlvr.it → Facebook + Instagram
"""

import os, time, glob
from pathlib import Path
from xml.etree import ElementTree as ET
from email.utils import formatdate
from datetime import datetime, timezone

REPO_OWNER = os.environ["REPO_OWNER"]
REPO_NAME  = os.environ["REPO_NAME"]

TITRE = "Notre promesse à notre communauté 🇦🇲"

TEXTE = """🇦🇲 Une promesse à notre communauté

Vous êtes des milliers à nous suivre. Des Arméniens de Paris, de Marseille, de Los Angeles, de Beyrouth, d'Erevan. Des enfants de la diaspora qui cherchent leurs racines. Des curieux tombés amoureux d'une culture millénaire.

Cette page, c'est la vôtre.

À partir d'aujourd'hui, chaque soir à 19h, nous vous donnons rendez-vous avec un fragment de l'âme arménienne :

🏛️ Lundi — Histoire : les batailles, les rois, les empires oubliés
🎭 Mardi — Culture & Traditions : ce qui nous unit depuis des siècles
🌙 Mercredi — Légendes & Mythologie : les secrets que les anciens murmuraient
🫓 Jeudi — Gastronomie : les saveurs qui racontent un peuple
🌍 Vendredi — Diaspora & Personnalités : nos frères et sœurs aux quatre coins du monde
🏔️ Samedi — Lieux : l'Arménie que vous devez voir au moins une fois
✝️ Dimanche — Spiritualité : la foi qui a traversé les persécutions

Et certains jours, nous poserons tout pour honorer ce qui ne s'oublie pas — le 24 avril, le 28 mai, et tous les moments qui ont forgé notre identité.

Activez les notifications. Partagez avec ceux qui portent l'Arménie dans leur cœur.

On vous attend ce soir. 🕯️

👉 Tagguez un Arménien que vous aimez !

#Armenie #Armenian #Armenia #Diaspora #Culture #Communauté #RendezVous #Histoire #Traditions #Spiritualité #Gastronomie #Légendes #Fierté #Héritage #Arméniens #PageFacebook #NouvelleStratégie #ChaqueJour #19h #Ensemble"""

# Trouver l'image d'annonce générée
images = sorted(glob.glob("images/announcement_*.png"))
if not images:
    print("❌ Aucune image d'annonce trouvée dans images/")
    exit(1)

image_file = images[-1]  # la plus récente
image_url  = f"https://raw.githubusercontent.com/{REPO_OWNER}/{REPO_NAME}/main/{image_file}"
print(f"✅ Image utilisée : {image_file}")

# Charger le feed RSS
feed_path = Path("feed.xml")
ET.register_namespace("", "")
tree = ET.parse(feed_path)
root = tree.getroot()
channel = root.find("channel")

# Créer l'item
item = ET.Element("item")
ET.SubElement(item, "title").text = " "
ET.SubElement(item, "description").text = f"✨ {TITRE}\n\n{TEXTE}"
ET.SubElement(item, "pubDate").text = formatdate(time.time(), usegmt=True)
ET.SubElement(item, "guid").text = f"armenians-announcement-{int(time.time())}"
enclosure = ET.SubElement(item, "enclosure")
enclosure.set("url", image_url)
enclosure.set("type", "image/png")
enclosure.set("length", "0")

# Insérer en premier
first_item = channel.find("item")
if first_item is not None:
    idx = list(channel).index(first_item)
    channel.insert(idx, item)
else:
    channel.append(item)

# Sauvegarder
ET.indent(tree, space="  ")
tree.write(feed_path, encoding="unicode", xml_declaration=True)
print("✅ Feed RSS mis à jour avec le post d'annonce")
print(f"📢 dlvr.it va publier sur Facebook + Instagram dans les prochaines minutes")
