# NRG-Stack ChargerEEBUSHub

[![Symcon](https://img.shields.io/badge/Symcon-PHP%20module-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Module version](https://img.shields.io/badge/Module%20version-0.2.0-blue.svg)](library.json)
[![Symcon version](https://img.shields.io/badge/Symcon%20version-9.0%2B-green.svg)](https://www.symcon.de)
[![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-lightgrey.svg)](LICENSE)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue.svg)](https://paypal.me/DietmarGureth)

[Deutsch](README.md) · **English**

Connects a **wallbox with EEBUS interface** to Symcon: detects the vehicle, reads charging state, current per
phase, power, charged energy and state of charge, sets charging current and charging release. Delivers the
NRG-Stack wallbox contract like ChargerHub and OCPPHub.

> Version 0.1.0: first version, tested against a self-built simulated wallbox with vehicle
> (eebus-go/spine-go). Not tested with a real wallbox.

## Requirements

- Symcon 9.0 or newer
- **NRG-Stack EEBUS gateway version 0.5.3 or newer** (separate module in the store): it holds certificate and
  pairing and establishes the connection to the wallbox. ChargerEEBUSHub is attached to it as a child
  instance, one gateway per wallbox.
- A wallbox with enabled EEBUS interface in the same network

## Which wallboxes?

According to field reports (e.g. evcc), EEBUS is offered by e.g. Elli Charger 2 / Porsche Wallbox (also VW,
Škoda, Cupra), Porsche Mobile Charger Connect/Plus, WARP Charger, EVBox Livo, Mennekes AMTRON (with update).
The first-generation Elli Charger Connect/Pro has many EEBUS bugs according to evcc. Some wallboxes need their
own PV surplus charging switched off so that they follow the energy management.

> **Vaillant (e.g. sensoNET Charger):** probably the same applies as for Vaillant heat pumps, which use the
> same EEBUS component: set the gateway to “The device connects to the gateway” (gateway 0.7.4 or newer). In
> the other direction a Vaillant heat pump closes the connection every minute. Not yet tested with a Vaillant
> wallbox itself.

## Setup

1. Create the instance “NRG-Stack ChargerEEBUSHub” and choose or create an EEBUS gateway as parent instance.
2. In the gateway, enter address and port of the wallbox (manufacturer app/manual), click “Check remote
   device” and trust its SKI.
3. Trust the SKI of the gateway in the app or web interface of the wallbox.
4. Plug in a vehicle: charging state, measurements and current range appear.

## Variables

| Ident | Meaning |
|---|---|
| `Erreichbar` | Wallbox connected via EEBUS |
| `Angesteckt` | Vehicle plugged in |
| `Zustand` | 0 no vehicle, 1 charging allowed, 2 paused, 3 finished, 4 error |
| `Laedt` | currently charging (power above 100 W) |
| `Ladefreigabe` | allow/pause charging (switchable) |
| `Ladestrom` | requested charging current per phase in A (switchable) |
| `Leistung`, `StromL1`–`StromL3` | charging power in W, current per phase in A |
| `SitzungEnergie`, `Energie` | energy charged in this session / in total in kWh |
| `Ladestand` | vehicle state of charge in % (if reported) |
| `Kommunikation`, `Fahrzeugkennung` | IEC 61851 or ISO 15118, vehicle identification |

## Charging settings (like evcc)

- **Current range:** if the vehicle charges via ISO 15118-2, the range it reports applies. With IEC 61851 the
  wallbox only reports a general 6/16 A; then the configured minimum and maximum current apply.
- **Only whole amperes:** for wallboxes rounding to whole amperes internally (Elli Charger Connect/Pro of the
  first generation); the current is rounded down.
- **Ignore reported charged energy:** for wallboxes with an unreliable energy value (Elli Charger Pro).
- “Charging” only above 0.6 × the minimum power; pausing with 0 A is only sent once the vehicle has
  reported its communication standard.

## Functions

| Function | Returns |
|---|---|
| `CHEEB_SetChargeCurrent(int $id, float $Ampere)` | bool: charging current per phase (effective now or on plug-in) |
| `CHEEB_SetChargeEnable(int $id, bool $Enable)` | bool: allow or pause charging |
| `CHEEB_GetFunctions(int $id)` | NRG-Stack wallbox contract (`function` `charger`, version 1.12) |
| `CHEEB_GetState(int $id)` | JSON: state for diagnosis |

Control rules as in evcc: the charging current is sent as an obligation limit per phase (OPEV), and as a
recommendation if self-consumption optimisation (OSCEV) is offered. Equal to the maximum means “no limit”.
Pausing (0 A) is only sent once the vehicle has reported its communication standard - otherwise an ISO 15118
vehicle could fall back to IEC 61851.

## Not included yet

- Charging plans (CEVC) and phase switching
- The NRG-Stack EMS only recognises the module once it is added to its list of sources.

## For developers

Test bench: `php .tools/test-cheeb.php` (offline, loads the gateway from `../EEBUSGateway` or `EEBGW_DIR`) or
with `--live <wallbox-sim> <cert> <key>` against the simulated wallbox.

## License

[PolyForm Noncommercial 1.0.0](LICENSE) – free for private and non-commercial use.
