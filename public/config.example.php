<?php
declare(strict_types=1);

/**
 * RookyCMS — Konfiguration (Vorlage).
 *
 * Erste Inbetriebnahme: Diese Datei wird beim ersten Seitenaufruf
 * automatisch nach config.php kopiert (oder von Hand:
 * `cp config.example.php config.php`).
 *
 * config.php selbst ist bewusst NICHT Teil des Repositories — dort
 * stehen die echten Werte deiner Instanz, insbesondere das Passwort.
 */

/**
 * Passwort für alle Bearbeitungs-Aktionen (Anlegen, Bearbeiten,
 * Löschen, Verschieben). Nach der Installation UNBEDINGT ÄNDERN —
 * '1234' ist nur der Demo-Wert.
 */
const ADMIN_PASSWORD = '1234';

/**
 * Aktualisierungs-Intervall in Sekunden (0 = aus).
 * Kein Seiten-Reload: der Client fragt nur die Daten ab und rendert
 * ausschließlich bei tatsächlichen Änderungen neu.
 */
const AUTO_REFRESH_SECONDS = 60;

/** Seitentitel im Browser-Tab und in der Topbar. */
const SITE_TITLE = 'RookyCMS';
