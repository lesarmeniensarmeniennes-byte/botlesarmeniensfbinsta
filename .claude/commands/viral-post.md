# /viral-post — Générer un post viral arménien

Génère un nouveau post viral pour la page "Les Arméniens & Arméniennes" en déclenchant le workflow GitHub Actions, puis affiche le résultat.

## Étapes à suivre

1. **Déclencher le workflow** via GitHub Actions `workflow_dispatch` :
   - Naviguer vers : `https://github.com/lesarmeniensarmeniennes-byte/botlesarmeniensfbinsta/actions/workflows/daily_post.yml`
   - Cliquer "Run workflow" → branche `main` → confirmer
   - Si le bouton "Run workflow" n'est pas visible, utiliser le terminal :
     ```bash
     cd "/Users/jacquespapazian/plugin vente"
     git stash
     git pull origin main --rebase
     git stash pop
     python3 generate_post.py
     git add feed.xml images/ topics_used.json
     git commit -m "📅 Post viral $(date +%Y-%m-%d)"
     git push origin main
     ```

2. **Attendre la complétion** (environ 1-2 minutes) en surveillant :
   `https://github.com/lesarmeniensarmeniennes-byte/botlesarmeniensfbinsta/commits/main`
   Actualiser jusqu'à voir un nouveau commit "📅 Post du..."

3. **Lire le dernier commit** pour afficher :
   - Le titre du post (`✨ ...` dans la description RSS)
   - La catégorie (Histoire / Culture / Légendes / Gastronomie / Diaspora / Lieux / Spiritualité)
   - Le thème hebdo attendu selon le jour (`WEEKLY_THEMES`)
   - L'image générée (URL dans `<enclosure>`)

4. **Afficher un résumé** au format :
   ```
   ✅ Post viral généré !
   
   📌 Sujet : [sujet]
   🗂️ Catégorie : [catégorie]
   📅 Thème du jour : [thème hebdo]
   🖼️ Image : [URL]
   
   [premiers 200 mots du texte]
   ```

## Contexte du projet

- **Repo** : `lesarmeniensarmeniennes-byte/botlesarmeniensfbinsta`
- **Script** : `/Users/jacquespapazian/plugin vente/generate_post.py`
- **Feed RSS** : `https://raw.githubusercontent.com/lesarmeniensarmeniennes-byte/botlesarmeniensfbinsta/main/feed.xml`
- **Thèmes hebdo** : Lundi=Histoire, Mardi=Culture, Mercredi=Légendes, Jeudi=Gastronomie, Vendredi=Diaspora, Samedi=Lieux, Dimanche=Spiritualité
- **Anti-doublon** : 365 jours (topics_used.json)
- **Publication** : dlvr.it → Facebook + Instagram automatiquement
