<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/CHEEBFormular.php';

// ===========================================================================
// NRG-Stack ChargerEEBUSHub
//
// Wallbox über EEBUS. Kind-Instanz des NRG-Stack EEBUS-Gateways (Kind-
// Schnittstelle 1.1, Rolle Energiemanagement). Meldet sich als CEM für die
// Wallbox-Anwendungsfälle an, wie eebus-go cem/*: EVSECC (Wallbox), EVCC
// (Fahrzeug angesteckt, Kommunikationsstandard, Kennung), EVCEM (Strom,
// Leistung, Energie), OPEV (Ladestrom als Pflichtgrenze je Phase), OSCEV
// (Empfehlung für Eigenverbrauch) und EVSOC (Ladestand).
//
// Steuerlogik nach evcc charger/eebus-evse.go: Pflichtgrenze immer schreiben
// (gleich der Höchstgrenze = nicht aktiv), Empfehlung nur ab Mindeststrom
// aktiv, nie 0 A schreiben, solange der Kommunikationsstandard unbekannt ist
// (ISO 15118 fiele sonst auf IEC 61851 zurück), nach dem Anstecken den
// gewünschten Strom erneut setzen. Liefert den charger-Vertrag des Verbunds
// wie ChargerHub/OCPPHub. Geprüft nur gegen einen selbst gebauten Simulator
// (eebus-go/spine-go, 06.11.2026).
// ===========================================================================

class ChargerEEBUSHub extends IPSModule
{
    use CHEEBFormular;

    private const GATEWAY_MODULE = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
    private const DATA_ZUM_GATEWAY = '{26D4DA14-6C0D-4686-AE00-D23234BAD90D}';
    private const CONTRACT_VERSION = '1.12';
    private const PHASEN = ['a', 'b', 'c'];
    // Unter dieser Leistung gilt „lädt nicht“ (wie ChargerHub chargingStateID).
    private const LAEDT_AB_W = 100;
    private const ZUSTAENDE = ['none' => 0, 'active' => 1, 'paused' => 2, 'finished' => 3, 'error' => 4];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('FormLayout', '[]');
        // Stand der Wallbox und des Fahrzeugs (Entitäten, Kennungen, Grenzen) zwischen zwei Meldungen.
        $this->RegisterAttributeString('Geraet', '{}');
        $this->RegisterAttributeInteger('LastSeenAt', 0);
        $this->RegisterAttributeString('LetztesErgebnis', '{}');
        // Gewünschter Ladestrom und Freigabe (gelten auch, solange kein Fahrzeug angesteckt ist).
        $this->RegisterAttributeFloat('SollStrom', 16.0);
        $this->RegisterAttributeBoolean('SollFrei', true);
        $this->RegisterAttributeBoolean('PauseAusstehend', false);
        $this->registerFormAttributes();
        $this->RegisterTimer('Anmelden', 0, 'CHEEB_Anmelden($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }
        $this->profileSicherstellen();
        $this->MaintainVariable('Erreichbar', $this->t('Reachable'), VARIABLETYPE_BOOLEAN, '', 1, true);
        $this->MaintainVariable('Angesteckt', $this->t('Vehicle plugged in'), VARIABLETYPE_BOOLEAN, '', 2, true);
        $this->MaintainVariable('Zustand', $this->t('Charging state'), VARIABLETYPE_INTEGER, 'CHEEB.State', 3, true);
        $this->MaintainVariable('Laedt', $this->t('Charging'), VARIABLETYPE_BOOLEAN, '', 4, true);
        $this->MaintainVariable('Ladefreigabe', $this->t('Charging release'), VARIABLETYPE_BOOLEAN, '~Switch', 5, true);
        $this->MaintainVariable('Ladestrom', $this->t('Charging current'), VARIABLETYPE_FLOAT, '~Ampere', 6, true);
        $this->MaintainVariable('Leistung', $this->t('Charging power'), VARIABLETYPE_FLOAT, '~Watt', 7, true);
        $this->MaintainVariable('StromL1', $this->t('Current L1'), VARIABLETYPE_FLOAT, '~Ampere', 8, true);
        $this->MaintainVariable('StromL2', $this->t('Current L2'), VARIABLETYPE_FLOAT, '~Ampere', 9, true);
        $this->MaintainVariable('StromL3', $this->t('Current L3'), VARIABLETYPE_FLOAT, '~Ampere', 10, true);
        $this->MaintainVariable('SitzungEnergie', $this->t('Energy of this charging session'), VARIABLETYPE_FLOAT, '~Electricity', 11, true);
        $this->MaintainVariable('Energie', $this->t('Energy charged in total'), VARIABLETYPE_FLOAT, '~Electricity', 12, true);
        $this->MaintainVariable('Ladestand', $this->t('Vehicle state of charge'), VARIABLETYPE_INTEGER, '~Battery.100', 13, true);
        $this->MaintainVariable('Kommunikation', $this->t('Vehicle communication'), VARIABLETYPE_STRING, '', 14, true);
        $this->MaintainVariable('Fahrzeugkennung', $this->t('Vehicle identification'), VARIABLETYPE_STRING, '', 15, true);
        $this->EnableAction('Ladefreigabe');
        $this->EnableAction('Ladestrom');
        if (@$this->GetValue('Ladestrom') === 0.0 || @$this->GetValue('Ladestrom') === null) {
            $this->wertSetzen('Ladestrom', $this->ReadAttributeFloat('SollStrom'));
        }
        $this->wertSetzen('Ladefreigabe', $this->ReadAttributeBoolean('SollFrei'));

        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->SetTimerInterval('Anmelden', 60000);
        $this->Anmelden();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        if (($Message === FM_CONNECT || $Message === FM_DISCONNECT) && IPS_GetKernelRunlevel() === KR_READY) {
            if ($Message === FM_DISCONNECT) {
                $this->wallboxVergessen();
            }
            $this->Anmelden();
        }
    }

    /** Profil für den Ladezustand; Namen in der Sprache, die Symcon beim Anlegen hat (wie WPModbusHub). */
    private function profileSicherstellen(): void
    {
        if (IPS_VariableProfileExists('CHEEB.State')) {
            return;
        }
        IPS_CreateVariableProfile('CHEEB.State', VARIABLETYPE_INTEGER);
        foreach ([0 => self::fest('no vehicle'), 1 => self::fest('charging allowed'), 2 => self::fest('paused'),
            3 => self::fest('finished'), 4 => self::fest('error')] as $wert => $text) {
            IPS_SetVariableProfileAssociation('CHEEB.State', $wert, $this->t($text), '', -1);
        }
    }

    // ------------------------------------------------------------------
    // Gateway
    // ------------------------------------------------------------------

    private function gatewayID(): int
    {
        $p = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($p <= 0 || !IPS_InstanceExists($p)) {
            return 0;
        }
        return (string)(IPS_GetInstance($p)['ModuleInfo']['ModuleID'] ?? '') === self::GATEWAY_MODULE ? $p : 0;
    }

    private function gateway(array $anfrage): array
    {
        if ($this->gatewayID() === 0) {
            return [];
        }
        try {
            $antwort = $this->SendDataToParent((string)json_encode(['DataID' => self::DATA_ZUM_GATEWAY, 'Sender' => $this->InstanceID] + $anfrage));
        } catch (Throwable $e) {
            $this->SendDebug('Gateway', $anfrage['Cmd'] . ': ' . $e->getMessage(), 0);
            return [];
        }
        return json_decode((string)$antwort, true) ?: [];
    }

    /** Anmeldung beim Gateway: CEM für die Wallbox-Anwendungsfälle, Client-Features wie eebus-go cem/*. */
    private static function anmeldung(): array
    {
        $client = function (string $typ): array {
            return ['type' => $typ, 'role' => 'client', 'functions' => []];
        };
        $uc = function (string $name, array $sz): array {
            return ['actor' => 'CEM', 'name' => $name, 'version' => '1.0.1', 'subRevision' => 'release', 'scenarios' => $sz];
        };
        return [
            'type' => 'CEM',
            'useCases' => [
                $uc('evseCommissioningAndConfiguration', [1, 2]),
                $uc('evCommissioningAndConfiguration', [1, 2, 3, 4, 5, 6, 7, 8]),
                $uc('measurementOfElectricityDuringEvCharging', [1, 2, 3]),
                $uc('overloadProtectionByEvChargingCurrentCurtailment', [1, 2, 3]),
                $uc('optimizationOfSelfConsumptionDuringEvCharging', [1, 2, 3]),
                $uc('evStateOfCharge', [1]),
            ],
            'features' => [$client('DeviceClassification'), $client('DeviceDiagnosis'), $client('DeviceConfiguration'), $client('Identification'),
                $client('ElectricalConnection'), $client('Measurement'), $client('LoadControl')],
            'remote' => [
                'entityTypes' => ['EVSE', 'EV'],
                'features' => [
                    ['type' => 'DeviceClassification', 'read' => ['deviceClassificationManufacturerData']],
                    ['type' => 'DeviceDiagnosis', 'subscribe' => true, 'read' => ['deviceDiagnosisStateData']],
                    ['type' => 'DeviceConfiguration', 'subscribe' => true, 'read' => ['deviceConfigurationKeyValueDescriptionListData', 'deviceConfigurationKeyValueListData']],
                    ['type' => 'Identification', 'subscribe' => true, 'read' => ['identificationListData']],
                    ['type' => 'ElectricalConnection', 'subscribe' => true, 'read' => ['electricalConnectionDescriptionListData',
                        'electricalConnectionParameterDescriptionListData', 'electricalConnectionPermittedValueSetListData']],
                    ['type' => 'Measurement', 'subscribe' => true, 'read' => ['measurementDescriptionListData', 'measurementConstraintsListData', 'measurementListData']],
                    ['type' => 'LoadControl', 'subscribe' => true, 'bind' => true, 'read' => ['loadControlLimitDescriptionListData', 'loadControlLimitConstraintsListData',
                        'loadControlLimitListData']],
                ],
            ],
        ];
    }

    /** CHEEB_Anmelden($id) — beim Gateway anmelden (auch per Timer, gleiche Anmeldung ändert dort nichts). */
    public function Anmelden(): void
    {
        if ($this->gatewayID() === 0) {
            $this->SetStatus(104);
            $this->wertSetzen('Erreichbar', false);
            return;
        }
        $r = $this->gateway(['Cmd' => 'Register', 'Entity' => self::anmeldung()]);
        if (empty($r['ok'])) {
            $this->SendDebug('Gateway', 'Register: ' . json_encode($r), 0);
            $this->SetStatus(201);
            return;
        }
        // Ab Kind-Schnittstelle 1.2 meldet das Gateway Fahrzeuge, die nach dem Verbinden angesteckt werden.
        if (version_compare((string)($r['interface'] ?? '1.0'), '1.2', '<')) {
            $this->SetStatus(202);
            return;
        }
        $this->statusAktualisieren();
    }

    private function statusAktualisieren(): void
    {
        $this->SetStatus(!empty($this->geraet()['evse']) ? IS_ACTIVE : 203);
    }

    // ------------------------------------------------------------------
    // Ereignisse des Gateways
    // ------------------------------------------------------------------

    public function ReceiveData($JSONString)
    {
        $e = json_decode((string)$JSONString, true);
        if (!is_array($e) || !in_array((int)($e['Target'] ?? 0), [0, $this->InstanceID], true)) {
            return '';
        }
        switch ((string)($e['Event'] ?? '')) {
            case 'connection':
                if (($e['state'] ?? '') !== 'connected') {
                    $this->wallboxVergessen();
                }
                break;
            case 'remoteEntity':
                $this->entitaet($e);
                $this->statusAktualisieren();
                break;
            case 'data':
                $this->datenAuswerten((array)($e['entity'] ?? []), (string)($e['function'] ?? ''), (array)($e['data'] ?? []));
                $this->WriteAttributeInteger('LastSeenAt', time());
                break;
            case 'result':
                $this->WriteAttributeString('LetztesErgebnis', (string)json_encode(['zeit' => time(), 'function' => $e['function'] ?? '',
                    'errorNumber' => (int)($e['errorNumber'] ?? 0), 'description' => (string)($e['description'] ?? '')]));
                if ((int)($e['errorNumber'] ?? 0) !== 0) {
                    $this->LogMessage($this->t('The wallbox rejected the charging current: error %s %s', (string)$e['errorNumber'], (string)($e['description'] ?? '')), KL_WARNING);
                }
                break;
        }
        return '';
    }

    private function geraet(): array
    {
        return json_decode($this->ReadAttributeString('Geraet'), true) ?: [];
    }

    private function geraetSchreiben(array $g): void
    {
        $this->WriteAttributeString('Geraet', (string)json_encode($g));
    }

    /** Wallbox (EVSE) bzw. Fahrzeug (EV) kommt dazu oder fällt weg. */
    private function entitaet(array $e): void
    {
        $g = $this->geraet();
        $typ = (string)($e['type'] ?? '');
        $ent = (array)($e['entity'] ?? []);
        $dazu = ($e['state'] ?? '') === 'added';
        if ($typ === 'EVSE' || (!$dazu && $ent == ($g['evse'] ?? null))) {
            if ($dazu) {
                $g['evse'] = $ent;
                $this->wertSetzen('Erreichbar', true);
                $this->LogMessage($this->t('Wallbox found (entity %s).', (string)json_encode($ent)), KL_MESSAGE);
            } else {
                $g = [];
                $this->wertSetzen('Erreichbar', false);
            }
        } elseif ($typ === 'EV' || (!$dazu && $ent == ($g['ev'] ?? null))) {
            if ($dazu) {
                // Neues Fahrzeug: alles Fahrzeugbezogene neu lernen.
                $g = array_merge($g, ['ev' => $ent, 'evSeit' => time(), 'lc' => [], 'ec' => [], 'ms' => [], 'keys' => [], 'kom' => '', 'state' => 'active', 'angewendet' => false]);
                $this->LogMessage($this->t('Vehicle plugged in.'), KL_MESSAGE);
            } else {
                foreach (['ev', 'evSeit', 'lc', 'ec', 'ms', 'keys', 'kom', 'state', 'grenzen', 'angewendet'] as $k) {
                    unset($g[$k]);
                }
                $this->LogMessage($this->t('Vehicle unplugged.'), KL_MESSAGE);
                $this->fahrzeugWerteLeeren();
            }
        }
        $this->geraetSchreiben($g);
        $this->zustandAktualisieren();
    }

    private function wallboxVergessen(): void
    {
        if ($this->geraet() !== []) {
            $this->WriteAttributeString('Geraet', '{}');
        }
        $this->wertSetzen('Erreichbar', false);
        $this->fahrzeugWerteLeeren();
        $this->statusAktualisieren();
    }

    private function fahrzeugWerteLeeren(): void
    {
        $this->wertSetzen('Angesteckt', false);
        $this->wertSetzen('Zustand', 0);
        $this->wertSetzen('Laedt', false);
        foreach (['Leistung', 'StromL1', 'StromL2', 'StromL3'] as $v) {
            $this->wertSetzen($v, 0.0);
        }
        $this->wertSetzen('Kommunikation', '');
    }

    private static function zahl($v): ?float
    {
        if (!is_array($v) || !isset($v['number'])) {
            return null;
        }
        return (float)$v['number'] * (10 ** (int)($v['scale'] ?? 0));
    }

    private function datenAuswerten(array $ent, string $fn, array $d): void
    {
        $g = $this->geraet();
        $istEv = !empty($g['ev']) && $ent == $g['ev'];
        switch ($fn) {
            case 'deviceClassificationManufacturerData':
                if (!$istEv) {
                    $g['hersteller'] = (string)($d['brandName'] ?? ($d['vendorName'] ?? ''));
                    $g['modell'] = (string)($d['deviceName'] ?? '');
                    $g['seriennummer'] = (string)($d['serialNumber'] ?? '');
                }
                break;
            case 'deviceDiagnosisStateData':
                if ($istEv) {
                    // eebus-go cem/evcc ChargeState: normalOperation = aktiv, standby = pausiert, finished, failure = Fehler.
                    $map = ['normalOperation' => 'active', 'standby' => 'paused', 'finished' => 'finished', 'failure' => 'error'];
                    $g['state'] = $map[(string)($d['operatingState'] ?? '')] ?? 'active';
                } else {
                    $g['evseState'] = (string)($d['operatingState'] ?? '');
                }
                break;
            case 'deviceConfigurationKeyValueDescriptionListData':
                if ($istEv) {
                    $g['keys'] = [];
                    foreach ((array)($d['deviceConfigurationKeyValueDescriptionData'] ?? []) as $k) {
                        $g['keys'][(string)($k['keyId'] ?? '')] = (string)($k['keyName'] ?? '');
                    }
                }
                break;
            case 'deviceConfigurationKeyValueListData':
                if ($istEv) {
                    foreach ((array)($d['deviceConfigurationKeyValueData'] ?? []) as $k) {
                        if (($g['keys'][(string)($k['keyId'] ?? '')] ?? '') === 'communicationsStandard' && isset($k['value']['string'])) {
                            $g['kom'] = (string)$k['value']['string'];
                            $this->wertSetzen('Kommunikation', $g['kom']);
                        }
                    }
                }
                break;
            case 'identificationListData':
                if ($istEv) {
                    $werte = array_filter(array_map(function ($i) {
                        return (string)($i['identificationValue'] ?? '');
                    }, (array)($d['identificationData'] ?? [])));
                    $this->wertSetzen('Fahrzeugkennung', implode(', ', $werte));
                }
                break;
            case 'electricalConnectionParameterDescriptionListData':
                if ($istEv) {
                    $g['ec']['param'] = [];
                    foreach ((array)($d['electricalConnectionParameterDescriptionData'] ?? []) as $p) {
                        $g['ec']['param'][] = ['parameterId' => (int)($p['parameterId'] ?? -1), 'measurementId' => isset($p['measurementId']) ? (int)$p['measurementId'] : null,
                            'phase' => (string)($p['acMeasuredPhases'] ?? '')];
                    }
                }
                break;
            case 'electricalConnectionPermittedValueSetListData':
                if ($istEv) {
                    $g['ec']['erlaubt'] = [];
                    foreach ((array)($d['electricalConnectionPermittedValueSetData'] ?? []) as $p) {
                        $r = $p['permittedValueSet'][0]['range'][0] ?? null;
                        if (is_array($r)) {
                            $g['ec']['erlaubt'][(string)($p['parameterId'] ?? '')] = [self::zahl($r['min'] ?? null), self::zahl($r['max'] ?? null)];
                        }
                    }
                }
                break;
            case 'electricalConnectionDescriptionListData':
                if ($istEv) {
                    $g['ec']['phasen'] = (int)($d['electricalConnectionDescriptionData'][0]['acConnectedPhases'] ?? 0);
                }
                break;
            case 'measurementDescriptionListData':
                if ($istEv) {
                    $g['ms'] = [];
                    foreach ((array)($d['measurementDescriptionData'] ?? []) as $m) {
                        $g['ms'][(string)($m['measurementId'] ?? '')] = ['scope' => (string)($m['scopeType'] ?? ''), 'unit' => (string)($m['unit'] ?? '')];
                    }
                }
                break;
            case 'measurementListData':
                if ($istEv) {
                    $this->geraetSchreiben($g);
                    $this->messwerte((array)($d['measurementData'] ?? []));
                    $g = $this->geraet();
                }
                break;
            case 'loadControlLimitDescriptionListData':
                if ($istEv) {
                    $g['lc'] = [];
                    foreach ((array)($d['loadControlLimitDescriptionData'] ?? []) as $l) {
                        $g['lc'][(string)($l['limitId'] ?? '')] = ['measurementId' => isset($l['measurementId']) ? (int)$l['measurementId'] : null,
                            'category' => (string)($l['limitCategory'] ?? ''), 'scope' => (string)($l['scopeType'] ?? ''), 'unit' => (string)($l['unit'] ?? '')];
                    }
                }
                break;
            case 'loadControlLimitListData':
                if ($istEv) {
                    $g['grenzen'] = [];
                    foreach ((array)($d['loadControlLimitData'] ?? []) as $l) {
                        $g['grenzen'][(string)($l['limitId'] ?? '')] = ['aktiv' => !empty($l['isLimitActive']), 'wert' => self::zahl($l['value'] ?? null),
                            'aenderbar' => (bool)($l['isLimitChangeable'] ?? true)];
                    }
                }
                break;
        }
        $this->geraetSchreiben($g);
        if ($istEv) {
            $this->zustandAktualisieren();
            // Nach dem Anstecken einmal den gewünschten Strom setzen, sobald Grenzen und Phasenzuordnung bekannt sind
            // (evcc: nach Wiederverbindung neu setzen); eine aufgeschobene Pause, sobald der Kommunikationsstandard kommt.
            $bereit = !empty($g['lc']) && !empty($g['ec']['param']);
            if (($bereit && empty($g['angewendet'])) || ($fn === 'deviceConfigurationKeyValueListData' && $this->ReadAttributeBoolean('PauseAusstehend'))) {
                $g['angewendet'] = true;
                $this->geraetSchreiben($g);
                $this->anwenden(false);
            }
        }
    }

    /** Messwerte des Fahrzeugs: Strom je Phase, Leistung (gesamt oder Summe), geladene Energie, Ladestand. */
    private function messwerte(array $daten): void
    {
        $g = $this->geraet();
        $phaseVon = [];
        foreach ((array)($g['ec']['param'] ?? []) as $p) {
            if ($p['measurementId'] !== null) {
                $phaseVon[(string)$p['measurementId']] = $p['phase'];
            }
        }
        $strom = [];
        $leistung = null;
        $leistungPhasen = 0.0;
        foreach ($daten as $m) {
            $id = (string)($m['measurementId'] ?? '');
            $w = self::zahl($m['value'] ?? null);
            $desc = $g['ms'][$id] ?? null;
            if ($w === null || $desc === null) {
                continue;
            }
            $phase = $phaseVon[$id] ?? '';
            switch ($desc['scope']) {
                case 'acCurrent':
                    $i = array_search($phase, self::PHASEN, true);
                    if ($i !== false) {
                        $strom[$i] = $w;
                    }
                    break;
                case 'acPower':
                    if ($phase === 'abc' || $phase === '') {
                        $leistung = $w;
                    } else {
                        $leistungPhasen += $w;
                    }
                    break;
                case 'charge':
                    $kwh = strtolower($desc['unit']) === 'kwh' ? $w : $w / 1000;
                    $this->sitzungsEnergie($kwh);
                    break;
                case 'stateOfCharge':
                    $this->wertSetzen('Ladestand', (int)round($w));
                    break;
            }
        }
        foreach ($strom as $i => $a) {
            $this->wertSetzen('StromL' . ($i + 1), $a);
        }
        if ($leistung === null && $leistungPhasen > 0) {
            $leistung = $leistungPhasen;
        }
        if ($leistung === null && $strom !== []) {
            // Ohne Leistungsmessung aus dem Strom rechnen (230 V je Phase, wie evcc).
            $leistung = array_sum($strom) * 230;
        }
        if ($leistung !== null) {
            $this->wertSetzen('Leistung', $leistung);
        }
        $this->zustandAktualisieren();
    }

    /** Sitzungsenergie übernehmen und in den Gesamtzähler fortschreiben (springt je Sitzung zurück). */
    private function sitzungsEnergie(float $kwh): void
    {
        $alt = @$this->GetValue('SitzungEnergie');
        $alt = is_numeric($alt) ? (float)$alt : 0.0;
        $plus = $kwh >= $alt ? $kwh - $alt : $kwh;
        $this->wertSetzen('SitzungEnergie', $kwh);
        if ($plus > 0) {
            $gesamt = @$this->GetValue('Energie');
            $this->wertSetzen('Energie', (is_numeric($gesamt) ? (float)$gesamt : 0.0) + $plus);
        }
    }

    private function zustandAktualisieren(): void
    {
        $g = $this->geraet();
        $an = !empty($g['ev']);
        $this->wertSetzen('Angesteckt', $an);
        $state = $an ? (string)($g['state'] ?? 'active') : 'none';
        $this->wertSetzen('Zustand', self::ZUSTAENDE[$state] ?? 1);
        $p = @$this->GetValue('Leistung');
        $this->wertSetzen('Laedt', $an && $state === 'active' && is_numeric($p) && (float)$p > self::LAEDT_AB_W);
    }

    private function wertSetzen(string $ident, $wert): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false && GetValue($id) !== $wert) {
            $this->SetValue($ident, $wert);
        }
    }

    // ------------------------------------------------------------------
    // Ladestrom und Freigabe
    // ------------------------------------------------------------------

    /** Je Phase: Grenze (limitId) der Kategorie und erlaubter Bereich, über Messung und Parameter verknüpft (eebus-go internal). */
    private function phasenGrenzen(string $kategorie, string $scope): array
    {
        $g = $this->geraet();
        $out = [];
        foreach (self::PHASEN as $phase) {
            foreach ((array)($g['ec']['param'] ?? []) as $p) {
                if ($p['phase'] !== $phase || $p['measurementId'] === null) {
                    continue;
                }
                foreach ((array)($g['lc'] ?? []) as $limitId => $l) {
                    if ($l['measurementId'] === $p['measurementId'] && $l['category'] === $kategorie && $l['scope'] === $scope) {
                        [$min, $max] = $g['ec']['erlaubt'][(string)$p['parameterId']] ?? [null, null];
                        $out[$phase] = ['limitId' => (int)$limitId, 'min' => $min, 'max' => $max,
                            'aenderbar' => (bool)($g['grenzen'][(string)$limitId]['aenderbar'] ?? true)];
                        break 2;
                    }
                }
            }
        }
        return $out;
    }

    /** Mindest- und Höchststrom laut Fahrzeug/Wallbox (erlaubte Werte der Pflichtgrenzen), sonst 6/16 A. */
    private function stromBereich(): array
    {
        $min = null;
        $max = null;
        foreach ($this->phasenGrenzen('obligation', 'overloadProtection') as $p) {
            if ($p['min'] !== null) {
                $min = $min === null ? $p['min'] : max($min, $p['min']);
            }
            if ($p['max'] !== null) {
                $max = $max === null ? $p['max'] : min($max, $p['max']);
            }
        }
        // Werte kommen aus dem JSON-Attribut, ganze Zahlen dort als int: als float zurückgeben.
        return [(float)($min ?? 6.0), (float)($max ?? 16.0)];
    }

    /**
     * Gewünschten Strom/Freigabe an das Fahrzeug schreiben (evcc writeCurrentLimitData + writeOscevLimits).
     * Ohne Fahrzeug wird nur gespeichert. $melden: Fehler ins Meldungsprotokoll.
     */
    private function anwenden(bool $melden): bool
    {
        $g = $this->geraet();
        $frei = $this->ReadAttributeBoolean('SollFrei');
        if (empty($g['ev'])) {
            return true;
        }
        $pflicht = $this->phasenGrenzen('obligation', 'overloadProtection');
        if ($pflicht === []) {
            if ($melden) {
                $this->LogMessage($this->t('Charging current not sent: the wallbox offers no current limit (OPEV) or has not reported it yet.'), KL_WARNING);
            }
            return false;
        }
        if (!$frei && in_array((string)($g['kom'] ?? ''), ['', 'unknown'], true)) {
            // evcc: 0 A bei unbekanntem Kommunikationsstandard lässt ISO-15118-Fahrzeuge auf IEC 61851 zurückfallen.
            $this->WriteAttributeBoolean('PauseAusstehend', true);
            if ($melden) {
                $this->LogMessage($this->t('Pausing postponed: the vehicle has not reported its communication standard yet.'), KL_NOTIFY);
            }
            return false;
        }
        $this->WriteAttributeBoolean('PauseAusstehend', false);
        [$min, $max] = $this->stromBereich();
        $soll = $frei ? max($min, min($max, $this->ReadAttributeFloat('SollStrom'))) : 0.0;
        $eintraege = [];
        foreach ($pflicht as $p) {
            if (!$p['aenderbar']) {
                continue;
            }
            // Grenze gleich der Höchstgrenze = keine Begrenzung (evcc: dann nicht aktiv).
            $aktiv = !($soll > 0 && $p['max'] !== null && $soll >= $p['max']);
            $eintraege[] = ['limitId' => $p['limitId'], 'isLimitActive' => $aktiv, 'value' => self::skaliert($soll)];
        }
        $ok = $this->grenzenSchreiben($eintraege, $melden);
        // Empfehlung (OSCEV): erst ab Mindeststrom aktiv; inaktiv = keine Empfehlung.
        $empf = [];
        foreach ($this->phasenGrenzen('recommendation', 'selfConsumption') as $p) {
            if ($p['aenderbar']) {
                $empf[] = ['limitId' => $p['limitId'], 'isLimitActive' => $soll >= ($p['min'] ?? $min), 'value' => self::skaliert($soll)];
            }
        }
        if ($empf !== []) {
            $this->grenzenSchreiben($empf, false);
        }
        return $ok;
    }

    private static function skaliert(float $a): array
    {
        // Zehntel-Ampere, wie ISO 15118 sie erlaubt; ganze Werte ohne Skala.
        $z = (int)round($a * 10);
        return $z % 10 === 0 ? ['number' => intdiv($z, 10), 'scale' => 0] : ['number' => $z, 'scale' => -1];
    }

    private function grenzenSchreiben(array $eintraege, bool $melden): bool
    {
        if ($eintraege === []) {
            return false;
        }
        $g = $this->geraet();
        $r = $this->gateway(['Cmd' => 'Write', 'Entity' => $g['ev'], 'Feature' => 'LoadControl', 'Function' => 'loadControlLimitListData',
            'Data' => ['loadControlLimitData' => $eintraege]]);
        if (empty($r['ok'])) {
            if ($melden) {
                $this->LogMessage($this->t('Charging current not sent: %s', (string)($r['error'] ?? $this->t('no answer from the gateway'))), KL_WARNING);
            }
            return false;
        }
        $this->SendDebug('OPEV/OSCEV', 'write ' . json_encode($eintraege), 0);
        return true;
    }

    /** CHEEB_SetChargeCurrent($id, $Ampere): bool — gewünschten Ladestrom je Phase setzen (wirkt sofort bzw. beim Anstecken). */
    public function SetChargeCurrent(float $Ampere): bool
    {
        $this->WriteAttributeFloat('SollStrom', max(0.0, $Ampere));
        $this->wertSetzen('Ladestrom', max(0.0, $Ampere));
        return $this->anwenden(true);
    }

    /** CHEEB_SetChargeEnable($id, $Enable): bool — Laden freigeben oder pausieren. */
    public function SetChargeEnable(bool $Enable): bool
    {
        $this->WriteAttributeBoolean('SollFrei', $Enable);
        $this->wertSetzen('Ladefreigabe', $Enable);
        return $this->anwenden(true);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Ladestrom':
                $this->SetChargeCurrent((float)$Value);
                break;
            case 'Ladefreigabe':
                $this->SetChargeEnable((bool)$Value);
                break;
            default:
                throw new Exception('Invalid ident ' . $Ident);
        }
    }

    /** Aktionsbereich: Strom und Freigabe aus dem Formular testen. */
    public function LadenTesten(float $Ampere, bool $Frei): string
    {
        $this->WriteAttributeFloat('SollStrom', max(0.0, $Ampere));
        $this->wertSetzen('Ladestrom', max(0.0, $Ampere));
        return $this->SetChargeEnable($Frei)
            ? '✅ ' . $this->t('Sent to the wallbox. Its answer appears in the variables in a few seconds.')
            : '⛔ ' . $this->t('Could not be sent. Details are in the message log.');
    }

    // ------------------------------------------------------------------
    // Verbund-Vertrag (charger, feldgleich ChargerHub/OCPPHub)
    // ------------------------------------------------------------------

    /** CHEEB_GetFunctions($id): array — Wallbox-Vertrag 1.12 wie CHUB_GetFunctions. */
    public function GetFunctions(): array
    {
        $id = function (string $ident): int {
            $v = @$this->GetIDForIdent($ident);
            return $v === false ? 0 : (int)$v;
        };
        $g = $this->geraet();
        [$min, $max] = $this->stromBereich();
        $fn = [
            'contractVersion'    => self::CONTRACT_VERSION,
            'function'           => 'charger',
            'label'              => IPS_GetName($this->InstanceID),
            'powerID'            => $id('Leistung'),
            'energyImportID'     => $id('Energie'),
            'measured'           => true,
            'chargeEnableID'     => $id('Ladefreigabe'),
            'currentLimitID'     => $id('Ladestrom'),
            'plugStateID'        => $id('Angesteckt'),
            'minCurrent'         => $min,
            'maxCurrent'         => $max,
            'managedBy'          => 'none',
            'externallyManaged'  => false,
            'vehicleNameID'      => 0,
            'lastSeenAt'         => $this->ReadAttributeInteger('LastSeenAt'),
            'deviceSerial'       => (string)($g['seriennummer'] ?? ''),
            'deviceHost'         => '',
            'manufacturer'       => (string)($g['hersteller'] ?? ''),
            'duplicateOf'        => null,
            'pluggedInAtID'      => 0,
            'pluggedOutAtID'     => 0,
            'chargeTimeSecID'    => 0,
            'connectionTimeSecID' => 0,
            'phaseModeID'        => 0,
            'phaseSwitchPauseSec' => 0,
            'currentStepA'       => 1.0,
            'controlLatencySec'  => 0,
            'sessionEnergyID'    => $id('SitzungEnergie'),
            'energyLimitID'      => 0,
            'chargingStateID'    => $id('Laedt'),
            'stateID'            => $id('Zustand'),
            'errorCodeID'        => 0,
            'planInfoID'         => 0,
        ];
        if (!empty($g['ec']['phasen'])) {
            $fn['phases'] = (int)$g['ec']['phasen'] >= 3 ? 3 : 1;
        }
        return [$fn];
    }

    /** CHEEB_GetState($id): string — Zustand als JSON (Diagnose). */
    public function GetState(): string
    {
        $g = $this->geraet();
        return (string)json_encode([
            'gateway' => $this->gatewayID(),
            'reachable' => (bool)@$this->GetValue('Erreichbar'),
            'evse' => $g['evse'] ?? null,
            'ev' => $g['ev'] ?? null,
            'evState' => $g['state'] ?? null,
            'communication' => $g['kom'] ?? '',
            'opev' => $this->phasenGrenzen('obligation', 'overloadProtection') !== [],
            'oscev' => $this->phasenGrenzen('recommendation', 'selfConsumption') !== [],
            'currentRange' => $this->stromBereich(),
            'setCurrent' => $this->ReadAttributeFloat('SollStrom'),
            'setEnable' => $this->ReadAttributeBoolean('SollFrei'),
            'pausePending' => $this->ReadAttributeBoolean('PauseAusstehend'),
            'limits' => $g['grenzen'] ?? [],
            'powerW' => @$this->GetValue('Leistung'),
            'lastResult' => json_decode($this->ReadAttributeString('LetztesErgebnis'), true),
        ]);
    }

    // ------------------------------------------------------------------
    // Formular
    // ------------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Wallbox connected'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'No EEBUS gateway assigned'],
            ['code' => 201, 'icon' => 'error', 'caption' => 'The EEBUS gateway did not accept the registration'],
            ['code' => 202, 'icon' => 'error', 'caption' => 'The EEBUS gateway is too old (version 0.5.3 or newer required)'],
            ['code' => 203, 'icon' => 'inactive', 'caption' => 'Waiting for the wallbox'],
        ];
        return $this->cheebBuildForm($this->cheebFachpanels(), [], $status);
    }

    protected function cheebFachpanels(): array
    {
        $gw = $this->gatewayID();
        $g = $this->geraet();
        $verbunden = !empty($g['evse']);
        $gwZeile = $gw > 0
            ? '✅ ' . $this->t('EEBUS gateway #%s (%s)', (string)$gw, IPS_GetName($gw))
            : self::fest('ℹ️ No EEBUS gateway assigned yet: choose “Change gateway” at the top and select or create an NRG-Stack EEBUS gateway (one per wallbox).');
        $wbZeile = $verbunden
            ? '✅ ' . $this->t('Wallbox connected: %s', trim(($g['hersteller'] ?? '') . ' ' . ($g['modell'] ?? '')) ?: '?')
            : self::fest('ℹ️ No wallbox connected yet. Check the connection in the EEBUS gateway and trust its SKI in the app of the wallbox.');
        $evZeile = !empty($g['ev'])
            ? $this->t('Vehicle plugged in (communication: %s) · current limit (OPEV): %s · self-consumption (OSCEV): %s · allowed %s–%s A',
                ($g['kom'] ?? '') !== '' ? (string)$g['kom'] : '?',
                $this->phasenGrenzen('obligation', 'overloadProtection') !== [] ? $this->t('available') : $this->t('not offered'),
                $this->phasenGrenzen('recommendation', 'selfConsumption') !== [] ? $this->t('available') : $this->t('not offered'),
                (string)$this->stromBereich()[0], (string)$this->stromBereich()[1])
            : ($verbunden ? self::fest('ℹ️ No vehicle plugged in.') : '');
        $erg = json_decode($this->ReadAttributeString('LetztesErgebnis'), true) ?: [];
        $ergZeile = isset($erg['zeit'])
            ? $this->t('Last answer of the wallbox (%s): %s', date($this->t('Y-m-d H:i:s'), (int)$erg['zeit']),
                (int)$erg['errorNumber'] === 0 ? $this->t('accepted') : $this->t('rejected (error %s %s)', (string)$erg['errorNumber'], (string)$erg['description']))
            : '';

        $verbindung = [
            'type' => 'ExpansionPanel', 'expanded' => !$verbunden, 'caption' => '🔌  Connection',
            'items' => array_values(array_filter([
                ['type' => 'Label', 'caption' => $gwZeile],
                ['type' => 'Label', 'caption' => $wbZeile, 'color' => $verbunden ? 0x2E8B3D : -1],
                $evZeile !== '' ? ['type' => 'Label', 'caption' => $evZeile] : null,
            ])),
        ];

        $laden = [
            'type' => 'ExpansionPanel', 'bereich' => 'actions', 'expanded' => false, 'caption' => '⚡  Test charging',
            'items' => array_values(array_filter([
                ['type' => 'Label', 'caption' => 'Sends charging current and release to the vehicle, e.g. to test the connection. In operation use the variables “Charging current” and “Charging release”, a script (CHEEB_SetChargeCurrent, CHEEB_SetChargeEnable) or an energy management.'],
                ['type' => 'NumberSpinner', 'name' => 'TestStrom', 'caption' => 'Charging current per phase', 'suffix' => 'A', 'minimum' => 0, 'maximum' => 32,
                    'value' => $this->ReadAttributeFloat('SollStrom')],
                ['type' => 'Button', 'caption' => 'Allow charging with this current', 'onClick' => 'echo CHEEB_LadenTesten($id, $TestStrom, true);'],
                ['type' => 'Button', 'caption' => 'Pause charging', 'onClick' => 'echo CHEEB_LadenTesten($id, $TestStrom, false);'],
                $ergZeile !== '' ? ['type' => 'Label', 'caption' => $ergZeile] : null,
            ])),
        ];
        return ['verbindung' => $verbindung, 'laden' => $laden];
    }
}
