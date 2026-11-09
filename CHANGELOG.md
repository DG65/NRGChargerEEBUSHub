# Changelog

## 0.2.0 (Build 3, 09.11.2026)

- evcc-Parität (charger/eebus-evse.go):
  - Strombereich des Fahrzeugs nur bei `iso15118-2ed1`/`ed2`, sonst neue Eigenschaften `MinStrom`/`MaxStrom`
    (Standard 6/16 A); IEC-Wallboxen melden pauschal 6/16 A (evcc #14418).
  - `Laedt` erst über 0,6 × Mindeststrom × 230 V (evcc idleFactor) statt fester 100 W.
  - `GetState`: `chargingAllowed` wie evcc Enabled() (inaktive Grenze oder ≥ 1 A; ISO-Pause 0,1 A),
    `currentsStale` (15 s nach einer Grenzänderung ohne neue Strommessung), `currentRangeFrom`.
  - Fahrzeugkennung: leere Meldungen überschreiben sie nicht (Porsche PMCC), beim Abstecken geleert.
  - Optionen `GanzeAmpere` (abrunden, Elli Gen1 „coarseCurrent“) und `LadeenergieIgnorieren` (Elli Charger Pro
    „chargedEnergy: false“).
- Profile wie ChargerHub: `NRG.Watt`, `NRG.Ampere`, `NRG.kWh`; Sitzungsenergie `CHEEB.kWhSession` mit eigenem
  Suffix (MeterHub-Zählersuche, Vorbild CHB.kWhSession).
- Formular: neuer Bereich „Ladeeinstellungen“.

## 0.1.1 (Build 2, 07.11.2026)

- Rückmeldungen: Der Knopf im Formular führt jetzt zum Forum-Thread des Moduls statt zu GitHub.

## 0.1.0 (Build 1, 06.11.2026)

- Erste Fassung: Wallbox über EEBUS als Kind des NRG-Stack EEBUS-Gateways (ab 0.5.3, Kind-Schnittstelle 1.2).
- Anmeldung als CEM für EVSECC, EVCC, EVCEM, OPEV, OSCEV und EVSOC wie eebus-go `cem/*`; Wallbox (EVSE) und
  Fahrzeug (EV) werden getrennt geführt, das Fahrzeug kommt und geht mit dem Anstecken.
- Variablen für Fahrzeug angesteckt, Ladezustand, lädt, Ladefreigabe, Ladestrom, Leistung, Strom je Phase,
  Sitzungs- und Gesamtenergie, Ladestand, Kommunikationsstandard, Fahrzeugkennung.
- `CHEEB_SetChargeCurrent`, `CHEEB_SetChargeEnable`; Steuerregeln nach evcc (Pflichtgrenze immer, Empfehlung ab
  Mindeststrom, kein 0 A bei unbekanntem Kommunikationsstandard, nach dem Anstecken neu setzen).
- `CHEEB_GetFunctions`: Wallbox-Vertrag `charger` 1.12 feldgleich ChargerHub/OCPPHub.
- Formular nach NRG-Stack-Konvention mit „Formular: Aufbau“, zweisprachig Deutsch/Englisch.
- Geprüft gegen eine selbst gebaute simulierte Wallbox mit Fahrzeug: anstecken, 16 A, 10 A, pausieren,
  freigeben, abstecken.
