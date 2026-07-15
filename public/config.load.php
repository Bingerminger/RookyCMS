<?php
declare(strict_types=1);

/**
 * RookyCMS — Konfigurations-Loader.
 *
 * Lädt config.php. Fehlt sie (frische Installation), wird sie einmalig
 * aus config.example.php erzeugt, damit ein frisch geklontes Repo ohne
 * Handarbeit lauffähig ist. Danach: Passwort in config.php ändern!
 */

$rookyConfig  = __DIR__ . '/config.php';
$rookyExample = __DIR__ . '/config.example.php';

if (!is_file($rookyConfig)) {
    if (!is_file($rookyExample) || !@copy($rookyExample, $rookyConfig)) {
        throw new RuntimeException(
            'config.php fehlt und konnte nicht aus config.example.php erzeugt werden. ' .
            'Bitte config.example.php nach config.php kopieren und anpassen.'
        );
    }
}

require_once $rookyConfig;
