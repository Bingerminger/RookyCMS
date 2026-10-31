# Credits & Hinweise zu Drittanbietern

RookyCMS selbst — PHP, JavaScript, CSS, Dokumentation und Demo-Daten — ist für
dieses Projekt geschrieben und steht unter der
[GNU AGPL v3.0 oder neuer](LICENSE). Versionen bis 1.1.0 sind unter der
MIT-Lizenz erschienen und bleiben unter ihr erhältlich.

## Mitgeliefert

Nichts von Dritten. RookyCMS bindet keine Bibliotheken, Frameworks, Schriften
oder Icon-Sätze ein:

- **Schriften:** die Systemschriften des Geräts (`system-ui`, `ui-monospace` …),
  es wird keine Schriftdatei ausgeliefert oder nachgeladen.
- **Icons und Logo:** eigene Inline-SVGs in `public/index.php` und
  `public/js/app.js`.
- **Demo-Bilder** (`demo-data/uploads/`): für das Projekt erzeugte Farbflächen,
  Verläufe und Muster.

## Dienste zur Laufzeit

Keine. RookyCMS stellt keine Anfragen ins Internet — die Links, die du in
Kacheln einträgst, öffnet erst dein Browser, wenn du sie anklickst.

## Vorbilder der Stile

Die vier Stile sind eigene CSS-Farbsätze (seit 1.3.0; bis 1.2 gab es
Graphit, Fluent, Ubuntu und Nord). Drei lehnen sich an bekannte Farbwelten an:

| Stil | angelehnt an |
|---|---|
| **Klar** | die ruhige Grau-/Weiß-Anmutung von macOS und iOS mit Systemblau |
| **Minze** | [cat-o-fit](https://github.com/Bingerminger/cat-o-fit) — Schwesterprojekt desselben Autors |
| **Schiefer** | [Energietracker](https://github.com/Bingerminger/energietracker) — Schwesterprojekt desselben Autors |

Übernommen sind nur einzelne Farbwerte, kein Code und keine Grafiken. Zu den
Namen siehe [TRADEMARKS.md](TRADEMARKS.md).
