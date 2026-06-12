# Staging Git Pull Instructies

⚠️ **BELANGRIJK**: De `www/content/` folder wordt NIET meer getracked in Git. 
Content wordt beheerd op staging en mag NOOIT worden overschreven door Git pulls.

## ✅ Veilige Pull Methode

**Gebruik ALTIJD dit script voor git pull op staging:**

```bash
./www/scripts/staging-safe-pull.sh
```

Dit script:
1. Maakt automatisch een backup van `www/content/` VOOR de pull
2. Voert `git pull` uit
3. Herstelt `www/content/` als die per ongeluk is verwijderd
4. Verwijdert de backup als alles goed is

## ❌ NOOIT DOEN

```bash
# ❌ NOOIT gewone git pull gebruiken!
git pull origin main
```

Dit kan `www/content/` verwijderen en kost je een uur om te herstellen!

## 🔒 Content Beschermen (Eénmalig)

Als je content hebt aangepast op staging en wilt beschermen tegen toekomstige pulls:

```bash
./www/scripts/staging-protect-content.sh
```

Dit markeert alle content bestanden als "skip-worktree" zodat Git ze niet verwijdert.

## 📝 Wat is er veranderd?

- `www/content/` staat nu in `.gitignore`
- Content bestanden zijn uit Git verwijderd (maar blijven lokaal bestaan)
- Content wordt alleen beheerd op staging, niet in Git

## 🆘 Probleem Oplossen

Als `www/content/` per ongeluk is verwijderd:

1. Check of er een backup is: `ls -la backup/content-before-pull-*`
2. Herstel: `cp -r backup/content-before-pull-[DATUM]/content www/content`
3. Draai protect script: `./www/scripts/staging-protect-content.sh`

