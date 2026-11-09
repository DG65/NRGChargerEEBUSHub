# NRG-Stack ChargerEEBUSHub

[![Symcon](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Modul Version](https://img.shields.io/badge/Modul%20Version-0.2.0-blue.svg)](library.json)
[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-9.0%2B-green.svg)](https://www.symcon.de)
[![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-lightgrey.svg)](LICENSE)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue.svg)](https://paypal.me/DietmarGureth)

**Deutsch** · [English](README.en.md)

Bindet eine **Wallbox mit EEBUS-Schnittstelle** an Symcon an: Fahrzeug erkennen, Ladezustand, Strom je
Phase, Leistung, geladene Energie und Ladestand lesen, Ladestrom und Ladefreigabe setzen. Liefert den
Wallbox-Vertrag des NRG-Stack wie ChargerHub und OCPPHub.

> Stand 0.1.0: erste Fassung, geprüft gegen eine selbst gebaute simulierte Wallbox mit Fahrzeug
> (eebus-go/spine-go). An keiner echten Wallbox getestet.

## Voraussetzungen

- Symcon 9.0 oder neuer
- **NRG-Stack EEBUS-Gateway ab Version 0.5.3** (eigenes Modul im Store): Es hält Zertifikat und Kopplung und
  baut die Verbindung zur Wallbox auf. ChargerEEBUSHub hängt als Kind-Instanz daran, je Wallbox ein Gateway.
- Eine Wallbox mit freigeschalteter EEBUS-Schnittstelle im selben Netz

## Welche Wallboxen?

Laut Praxisberichten (u. a. evcc) bieten EEBUS u. a.: Elli Charger 2 / Porsche Wallbox (auch VW, Škoda,
Cupra), Porsche Mobile Charger Connect/Plus, WARP Charger, EVBox Livo, Mennekes AMTRON (mit Update).
Elli Charger Connect/Pro der ersten Generation hat laut evcc viele EEBUS-Fehler. Bei manchen Wallboxen
muss das eigene PV-Überschussladen ausgeschaltet sein, damit sie dem Energiemanagement folgen.

## Einrichtung

1. Instanz „NRG-Stack ChargerEEBUSHub“ anlegen und als übergeordnete Instanz ein EEBUS-Gateway wählen bzw.
   neu anlegen.
2. Im Gateway Adresse und Port der Wallbox eintragen (Hersteller-App/Anleitung), „Gegenstelle prüfen“ und
   ihren SKI übernehmen.
3. Den SKI des Gateways in der App oder Weboberfläche der Wallbox freigeben.
4. Fahrzeug anstecken: Ladezustand, Messwerte und Strombereich erscheinen.

## Variablen

| Ident | Bedeutung |
|---|---|
| `Erreichbar` | Wallbox über EEBUS verbunden |
| `Angesteckt` | Fahrzeug angesteckt |
| `Zustand` | 0 kein Fahrzeug, 1 Laden möglich, 2 pausiert, 3 fertig, 4 Fehler |
| `Laedt` | lädt gerade (Leistung über 100 W) |
| `Ladefreigabe` | Laden freigeben/pausieren (schaltbar) |
| `Ladestrom` | gewünschter Ladestrom je Phase in A (schaltbar) |
| `Leistung`, `StromL1`–`StromL3` | Ladeleistung in W, Strom je Phase in A |
| `SitzungEnergie`, `Energie` | geladene Energie dieser Sitzung bzw. gesamt in kWh |
| `Ladestand` | Ladestand des Fahrzeugs in % (wenn gemeldet) |
| `Kommunikation`, `Fahrzeugkennung` | IEC 61851 oder ISO 15118, Kennung des Fahrzeugs |

## Ladeeinstellungen (wie evcc)

- **Strombereich:** Lädt das Fahrzeug über ISO 15118-2, gilt der Bereich, den es selbst meldet. Bei IEC 61851
  meldet die Wallbox nur pauschal 6/16 A, dann gilt der eingestellte Mindest- und Höchststrom.
- **Nur ganze Ampere:** für Wallboxen, die intern auf ganze Ampere runden (Elli Charger Connect/Pro der ersten
  Generation); der Strom wird abgerundet.
- **Gemeldete Ladeenergie ignorieren:** für Wallboxen mit unzuverlässigem Energiewert (Elli Charger Pro).
- „Lädt“ gilt erst über dem 0,6-Fachen der Mindestleistung; Pausieren mit 0 A wird erst gesendet, wenn das
  Fahrzeug seinen Kommunikationsstandard gemeldet hat.

## Funktionen

| Funktion | Rückgabe |
|---|---|
| `CHEEB_SetChargeCurrent(int $id, float $Ampere)` | bool: Ladestrom je Phase (wirkt sofort bzw. beim Anstecken) |
| `CHEEB_SetChargeEnable(int $id, bool $Enable)` | bool: Laden freigeben oder pausieren |
| `CHEEB_GetFunctions(int $id)` | Wallbox-Vertrag des NRG-Stack (`function` `charger`, Version 1.12) |
| `CHEEB_GetState(int $id)` | JSON: Zustand zur Diagnose |

Steuerregeln nach evcc: Der Ladestrom wird als Pflichtgrenze je Phase geschickt (OPEV), bei vorhandener
Eigenverbrauchs-Empfehlung (OSCEV) zusätzlich als Empfehlung. Gleich der Höchstgrenze bedeutet „keine
Begrenzung“. Pausieren (0 A) wird erst geschickt, wenn das Fahrzeug seinen Kommunikationsstandard gemeldet
hat - sonst könnte ein ISO-15118-Fahrzeug auf IEC 61851 zurückfallen.

## Noch nicht enthalten

- Ladepläne (CEVC) und Phasenumschaltung
- Das EMS des NRG-Stack erkennt das Modul erst, wenn es in seine Quellenliste aufgenommen ist.

## Für Entwickler

Prüfstand: `php .tools/test-cheeb.php` (ohne Netz, lädt das Gateway aus `../EEBUSGateway` oder
`EEBGW_DIR`) bzw. mit `--live <wallbox-sim> <cert> <key>` gegen die simulierte Wallbox.

## Lizenz

[PolyForm Noncommercial 1.0.0](LICENSE) – frei für private und nicht-kommerzielle Nutzung.
