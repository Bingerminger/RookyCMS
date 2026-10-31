#!/bin/bash
# =============================================================================
# build-demo.sh — die öffentliche Demo als statische Seite (GitHub Pages).
#
# GitHub Pages hat kein PHP. Dieses Skript startet deshalb den echten Server
# auf einer Wegwerf-Kopie: Beim ersten Aufruf übernimmt RookyCMS wie bei jeder
# Neuinstallation den Demo-Bestand aus demo-data/ (Kacheln + Bilder). Die so
# gerenderte Seite wird als index.html abgelegt — mit `data-demo` an <html>,
# dazu css/, js/, die Bilder und die Lizenz. Im Browser beantwortet app.js
# im Demo-Modus die API selbst (demoFetch): ausprobieren ja, speichern nein.
#
#   bash tools/build-demo.sh                    # nach dist/demo
#   bash tools/build-demo.sh /tmp/demo 8898     # Ziel, Port
#
# Ansehen: php -S 127.0.0.1:8090 -t dist/demo  → http://127.0.0.1:8090/
# Gebaut wird von .github/workflows/pages.yml (bei Releases, von Hand).
# Ein Fehler (Server, Fehlerbanner, fehlende Kacheln) bricht den Bau ab —
# die Demo soll nichts zeigen, was es im Echtbetrieb nicht gibt.
# =============================================================================
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT=${1:-$ROOT/dist/demo}
PORT=${2:-8898}
WORK=$(mktemp -d)
PID=""
cleanup() { [ -n "$PID" ] && kill "$PID" 2>/dev/null; rm -rf "$WORK"; }
trap cleanup EXIT

# Arbeitskopie nur mit Code — ohne config.php, data/, uploads/ der lokalen Instanz
mkdir -p "$WORK/app"
rsync -a --exclude 'config.php' --exclude 'data/' --exclude 'uploads/' --exclude 'backup/' \
  --exclude '.DS_Store' "$ROOT/public/" "$WORK/app/public/"
cp -R "$ROOT/demo-data" "$WORK/app/demo-data"
cp "$ROOT/VERSION" "$WORK/app/VERSION"
cp "$ROOT/public/config.example.php" "$WORK/app/public/config.php"

php -S "127.0.0.1:$PORT" -t "$WORK/app/public" >/dev/null 2>&1 &
PID=$!
disown "$PID" 2>/dev/null || true # kein „Terminated“ beim Aufräumen
for _ in $(seq 1 50); do
  curl -sf "http://127.0.0.1:$PORT/api.php?action=entries" >/dev/null 2>&1 && break
  sleep 0.1
done

expected=$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true));' "$ROOT/demo-data/entries.json")
api=$(curl -sf "http://127.0.0.1:$PORT/api.php?action=entries") || { echo "API nicht erreichbar" >&2; exit 1; }
got=$(printf '%s' "$api" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo ($d["success"] ?? false) ? count($d["entries"]) : -1;')
[ "$got" = "$expected" ] || { echo "Demo-Bestand: $got statt $expected Kacheln" >&2; exit 1; }

page=$(curl -sf "http://127.0.0.1:$PORT/") || { echo "Seite nicht erreichbar" >&2; exit 1; }
case "$page" in *banner--error*) echo "Seite zeigt ein Fehlerbanner" >&2; exit 1 ;; esac

rm -rf "$OUT"
mkdir -p "$OUT"
printf '%s' "$page" | sed 's/<html lang="de"/<html lang="de" data-demo/' > "$OUT/index.html"
grep -q '<html lang="de" data-demo' "$OUT/index.html" || { echo "data-demo nicht gesetzt" >&2; exit 1; }

cp -R "$WORK/app/public/css" "$WORK/app/public/js" "$WORK/app/public/uploads" "$OUT/"
cp "$WORK/app/public/favicon.png" "$ROOT/LICENSE" "$OUT/"
touch "$OUT/.nojekyll"

echo "Demo gebaut: $OUT — $got Kacheln, RookyCMS $(cat "$ROOT/VERSION"), $(du -sh "$OUT" | cut -f1)"
