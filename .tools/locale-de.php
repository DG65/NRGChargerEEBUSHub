<?php
/**
 * locale-de.php — deutsche Fassung aller Quelltexte (Referenzsprache, gepflegt in locale-de.json).
 * Erzeugt ChargerEEBUSHub/locale.json und meldet Quelltexte ohne Übersetzung:  php .tools/locale-de.php
 */

declare(strict_types=1);

require_once __DIR__ . '/i18n-texte.php';
$wurzel = dirname(__DIR__);
$de = json_decode((string)file_get_contents(__DIR__ . '/locale-de.json'), true) ?: [];
$fehlt = array_diff_key(cheebQuelltexte($wurzel), $de);
file_put_contents($wurzel . '/ChargerEEBUSHub/locale.json', json_encode(['translations' => ['de' => $de]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo count($de) . ' Einträge geschrieben' . ($fehlt ? ', ohne Übersetzung: ' . implode(' | ', array_keys($fehlt)) : '') . "\n";
