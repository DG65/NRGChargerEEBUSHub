<?php
/**
 * test-cheeb.php — Prüfstand für ChargerEEBUSHub ohne laufendes Symcon.
 *
 * Lädt ChargerEEBUSHub zusammen mit dem echten NRG-Stack EEBUS-Gateway (Kind-Schnittstelle 1.1)
 * in eine nachgebildete Symcon-Umgebung, in der Kind und Gateway wie im Datenfluss miteinander
 * reden (SendDataToParent → ForwardData, SendDataToChildren → ReceiveData).
 *
 * Teil 1 (immer): Anmeldung, Auswertung der Wallbox- und Fahrzeugdaten, Steuerregeln, Vertrag,
 *   Formular, Zweisprachigkeit.
 * Teil 2 (--live <wallbox-sim> <cert> <key>): simulierte Wallbox mit Fahrzeug (eebus-go/spine-go,
 *   EVSE + EV) über echte TLS-Verbindung; Fahrzeug ansteckt, Strom setzen, pausieren, abstecken.
 *
 * Aufruf:   php .tools/test-cheeb.php [--live wallbox-sim cert key]
 *           Gateway-Quellen: Umgebungsvariable EEBGW_DIR, sonst ../EEBUSGateway.
 * Rückgabe: 0 = alles bestanden, 1 = mindestens ein Fehlschlag
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const IM_CHANGESTATUS = 10505;
const FM_CONNECT = 11101;
const FM_DISCONNECT = 11102;
const IS_ACTIVE = 102;
const KL_MESSAGE = 10201;
const KL_NOTIFY = 10203;
const KL_WARNING = 10204;
const KL_ERROR = 10205;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;

$wurzel = dirname(__DIR__);
$gwDir = getenv('EEBGW_DIR') ?: dirname($wurzel) . '/EEBUSGateway';
$GLOBALS['INST'] = [];
$GLOBALS['VARS'] = [];
$GLOBALS['LANG'] = 'de';
$GLOBALS['LOCALE'] = [
    'ChargerEEBUSHub' => json_decode((string)file_get_contents($wurzel . '/ChargerEEBUSHub/locale.json'), true)['translations']['de'] ?? [],
    'EEBUSGateway' => json_decode((string)file_get_contents($gwDir . '/EEBUSGateway/locale.json'), true)['translations']['de'] ?? [],
];
$GLOBALS['OK'] = 0;
$GLOBALS['FEHLER'] = 0;

function pruefe(bool $b, string $text): void
{
    $GLOBALS[$b ? 'OK' : 'FEHLER']++;
    echo ($b ? '  ok   ' : '  FEHL ') . $text . "\n";
}

function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_SemaphoreEnter(string $n, int $ms): bool { return true; }
function IPS_SemaphoreLeave(string $n): bool { return true; }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['INST'][$id]); }
function IPS_GetName(int $id): string { return $GLOBALS['INST'][$id]->name ?? ('#' . $id); }
function IPS_GetInstance(int $id): array
{
    $i = $GLOBALS['INST'][$id];
    return ['ConnectionID' => $i->parentId ?? 0, 'InstanceStatus' => $i->status ?? 102, 'ModuleInfo' => ['ModuleID' => $i->modul ?? '']];
}
function IPS_GetConfiguration(int $id): string { return json_encode($GLOBALS['INST'][$id]->config); }
function IPS_SetConfiguration(int $id, string $c): bool { $GLOBALS['INST'][$id]->config = array_merge($GLOBALS['INST'][$id]->config, json_decode($c, true)); return true; }
function IPS_SetProperty(int $id, string $n, $v): bool { $GLOBALS['INST'][$id]->config[$n] = $v; return true; }
function IPS_ApplyChanges(int $id): bool { $i = $GLOBALS['INST'][$id]; if (method_exists($i, 'anwenden')) { $i->anwenden(); } return true; }
function IPS_GetInstanceListByModuleID(string $g): array { return []; }
function IPS_GetLibrary(string $guid): array
{
    $l = json_decode((string)file_get_contents(dirname(__DIR__) . '/library.json'), true);
    return ['Version' => $l['version'], 'Build' => $l['build']];
}
function GetValue(int $id) { return $GLOBALS['VARS'][$id] ?? null; }
$GLOBALS['PROFILE'] = [];
function IPS_VariableProfileExists(string $n): bool { return isset($GLOBALS['PROFILE'][$n]); }
function IPS_CreateVariableProfile(string $n, int $t): bool { $GLOBALS['PROFILE'][$n] = []; return true; }
function IPS_SetVariableProfileAssociation(string $n, $w, string $text, string $icon, int $farbe): bool { $GLOBALS['PROFILE'][$n][$w] = $text; return true; }
function IPS_SetVariableProfileDigits(string $n, int $d): bool { $GLOBALS['PROFILE'][$n]['_stellen'] = $d; return true; }
function IPS_SetVariableProfileText(string $n, string $pre, string $suf): bool { $GLOBALS['PROFILE'][$n]['_suffix'] = $suf; return true; }
function IPS_SetVariableProfileIcon(string $n, string $i): bool { return true; }

class IPSModule
{
    public $InstanceID;
    public $props = [];
    public $attrs = [];
    public $buffers = [];
    public $timers = [];
    public $vars = [];
    public $ids = [];
    public $status = 0;
    public $parentId = 0;
    public $name = '';
    public $modul = '';
    public $log = [];
    public $form = [];
    public $gesendet = [];

    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyFloat($n, $v) { $this->props[$n] = $v; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeFloat($n, $v) { $this->attrs[$n] = $v; }
    protected function ReadPropertyString($n) { return $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return $this->props[$n]; }
    protected function ReadPropertyBoolean($n) { return $this->props[$n]; }
    protected function ReadPropertyFloat($n) { return (float)$this->props[$n]; }
    protected function ReadAttributeString($n) { return $this->attrs[$n]; }
    protected function ReadAttributeInteger($n) { return $this->attrs[$n]; }
    protected function ReadAttributeBoolean($n) { return $this->attrs[$n]; }
    protected function ReadAttributeFloat($n) { return $this->attrs[$n]; }
    protected function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeFloat($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterTimer($n, $i, $s) { $this->timers[$n] = $i; }
    protected function SetTimerInterval($n, $i) { $this->timers[$n] = $i; }
    protected function RequireParent($g) {}
    protected function RegisterMessage($id, $m) {}
    protected function UnregisterMessage($id, $m) {}
    protected function SetStatus($s) { $this->status = $s; }
    protected function GetBuffer($n) { return $this->buffers[$n] ?? ''; }
    protected function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    protected function SendDebug($t, $m, $f) { if (getenv('WPEEB_DEBUG')) { echo "      [dbg {$this->InstanceID}] $t: $m\n"; } }
    protected function LogMessage($m, $t) { $this->log[] = $m; }
    protected function MaintainVariable($ident, $name, $typ, $profil, $pos, $behalten)
    {
        if (!isset($this->ids[$ident])) {
            $this->ids[$ident] = $this->InstanceID * 100 + count($this->ids) + 1;
            $this->vars[$ident] = null;
        }
    }
    protected function EnableAction($ident) {}
    protected function GetIDForIdent($ident) { return $this->ids[$ident] ?? false; }
    protected function GetValue($ident) { return $this->vars[$ident] ?? null; }
    protected function SetValue($ident, $v) { $this->vars[$ident] = $v; $GLOBALS['VARS'][$this->ids[$ident]] = $v; }
    protected function UpdateFormField($f, $p, $v) { $this->form[$f][$p] = $v; }
    public function Translate($text) { return $GLOBALS['LANG'] === 'de' ? ($GLOBALS['LOCALE'][get_class($this)][$text] ?? $text) : $text; }
    protected function SendDataToParent($json)
    {
        $eltern = $GLOBALS['INST'][$this->parentId] ?? null;
        if ($eltern instanceof EEBUSGateway) {
            return $eltern->ForwardData($json);
        }
        // Gateway → Client Socket: in die echte TLS-Verbindung (Teil 2).
        $d = json_decode($json, true);
        $this->gesendet[] = mb_convert_encoding($d['Buffer'], 'ISO-8859-1', 'UTF-8');
        if (isset($GLOBALS['BRUECKE'])) { fwrite($GLOBALS['BRUECKE'], end($this->gesendet)); }
        return '';
    }
    protected function SendDataToChildren($json)
    {
        foreach ($GLOBALS['INST'] as $i) {
            if ($i instanceof ChargerEEBUSHub && $i->parentId === $this->InstanceID) {
                $i->ReceiveData($json);
            }
        }
    }
}

class FakeSocket
{
    public $InstanceID;
    public $config = ['Open' => false, 'Host' => '', 'Port' => 0];
    public $status = 104;
    public $modul = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    public $name = 'Client Socket';
    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function anwenden(): void { $this->status = $this->config['Open'] ? 102 : 104; }
}

require $gwDir . '/EEBUSGateway/module.php';
require $wurzel . '/ChargerEEBUSHub/module.php';

/** Jede Symcon-Anfrage beginnt mit leerem Übersetzungsspeicher. */
function neueAnfrage($i): void
{
    foreach (['eebgwUebersetzt', 'cheebUebersetzt'] as $p) {
        if (property_exists($i, $p)) {
            $r = new ReflectionProperty(get_class($i), $p);
            $r->setValue($i, []);
        }
    }
}

/** Formular wie in der Konsole: feste caption-Texte übersetzt Symcon selbst. */
function formular(ChargerEEBUSHub $wp): string
{
    neueAnfrage($wp);
    $f = json_decode($wp->GetConfigurationForm(), true);
    $walk = function (&$x) use (&$walk) {
        if (!is_array($x)) {
            return;
        }
        foreach ($x as $k => &$v) {
            if ($k === 'caption' && is_string($v) && $GLOBALS['LANG'] === 'de') {
                $v = $GLOBALS['LOCALE']['ChargerEEBUSHub'][$v] ?? $v;
            } else {
                $walk($v);
            }
        }
    };
    $walk($f);
    return (string)json_encode($f, JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
echo "Teil 1: Bausteine\n";

$sock = new FakeSocket(40001);
$gw = new EEBUSGateway(40002);
$gw->name = 'EEBUS-Gateway Wallbox';
$gw->modul = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
$gw->parentId = 40001;
$gw->Create();
$gw->ApplyChanges();

$allein = new ChargerEEBUSHub(40010);
$allein->Create();
$allein->ApplyChanges();
pruefe($allein->status === 104, 'Ohne Gateway: Status 104');
unset($GLOBALS['INST'][40010]);

$wb = new ChargerEEBUSHub(40003);
$wb->name = 'Wallbox';
$wb->parentId = 40002;
$wb->Create();
$wb->ApplyChanges();
$anm = json_decode($gw->attrs['Anmeldungen'], true)['40003'] ?? [];
pruefe(count($anm['features'] ?? []) === 7 && ($anm['remote']['entityTypes'] ?? []) === ['EVSE', 'EV'] && count($anm['useCases'] ?? []) === 6,
    'Beim Gateway angemeldet: CEM mit 6 Anwendungsfällen, 7 Client-Features, Entitäten EVSE/EV');
pruefe($wb->status === 203 && $wb->vars['Ladestrom'] === 16.0 && $wb->vars['Ladefreigabe'] === true, 'Wartet auf die Wallbox, Vorgabe 16 A freigegeben');
pruefe(($GLOBALS['PROFILE']['CHEEB.State'][2] ?? '') === 'pausiert', 'Profil CHEEB.State angelegt (2 = pausiert)');

$sende = function (array $e) use ($wb) {
    $wb->ReceiveData(json_encode(['DataID' => '{12D8B8A8-3EE7-491F-A68A-D632304165D4}', 'Target' => 40003] + $e));
};
$sende(['Event' => 'remoteEntity', 'state' => 'added', 'entity' => [1], 'type' => 'EVSE', 'features' => []]);
$sende(['Event' => 'data', 'entity' => [1], 'function' => 'deviceClassificationManufacturerData', 'data' => ['brandName' => 'Demo', 'deviceName' => 'Wallbox X', 'serialNumber' => 'SN1']]);
pruefe($wb->status === 102 && $wb->vars['Erreichbar'] === true && $wb->vars['Angesteckt'] === false && $wb->vars['Zustand'] === 0, 'Wallbox da, kein Fahrzeug: Status 102, Zustand 0');
pruefe($wb->SetChargeCurrent(10) === true && $wb->attrs['SollStrom'] === 10.0, 'Ohne Fahrzeug: Ladestrom 10 A nur gemerkt');

$sende(['Event' => 'remoteEntity', 'state' => 'added', 'entity' => [1, 1], 'type' => 'EV', 'features' => []]);
pruefe($wb->vars['Angesteckt'] === true && $wb->vars['Zustand'] === 1, 'Fahrzeug angesteckt: Zustand 1');
$param = [];
$erlaubt = [];
foreach (['a', 'b', 'c'] as $i => $ph) {
    $param[] = ['electricalConnectionId' => 0, 'parameterId' => $i, 'measurementId' => $i, 'acMeasuredPhases' => $ph];
    $erlaubt[] = ['electricalConnectionId' => 0, 'parameterId' => $i, 'permittedValueSet' => [['range' => [['min' => ['number' => 6], 'max' => ['number' => 32]]]]]];
}
$param[] = ['electricalConnectionId' => 0, 'parameterId' => 3, 'measurementId' => 3, 'acMeasuredPhases' => 'abc'];
$sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'deviceConfigurationKeyValueDescriptionListData', 'data' => ['deviceConfigurationKeyValueDescriptionData' => [['keyId' => 0, 'keyName' => 'communicationsStandard']]]]);
$sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'electricalConnectionDescriptionListData', 'data' => ['electricalConnectionDescriptionData' => [['electricalConnectionId' => 0, 'acConnectedPhases' => 3]]]]);
$sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'electricalConnectionParameterDescriptionListData', 'data' => ['electricalConnectionParameterDescriptionData' => $param]]);
$sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'electricalConnectionPermittedValueSetListData', 'data' => ['electricalConnectionPermittedValueSetData' => $erlaubt]]);
$md = [];
foreach ([0, 1, 2] as $i) {
    $md[] = ['measurementId' => $i, 'scopeType' => 'acCurrent', 'unit' => 'A'];
}
$md[] = ['measurementId' => 3, 'scopeType' => 'acPower', 'unit' => 'W'];
$md[] = ['measurementId' => 4, 'scopeType' => 'charge', 'unit' => 'Wh'];
$md[] = ['measurementId' => 5, 'scopeType' => 'stateOfCharge', 'unit' => 'pct'];
$sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'measurementDescriptionListData', 'data' => ['measurementDescriptionData' => $md]]);
$messung = function (float $a, float $w, float $wh, float $soc) use ($sende) {
    $sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'measurementListData', 'data' => ['measurementData' => [
        ['measurementId' => 0, 'value' => ['number' => (int)($a * 10), 'scale' => -1]], ['measurementId' => 1, 'value' => ['number' => (int)($a * 10), 'scale' => -1]],
        ['measurementId' => 2, 'value' => ['number' => (int)($a * 10), 'scale' => -1]], ['measurementId' => 3, 'value' => ['number' => $w]],
        ['measurementId' => 4, 'value' => ['number' => $wh]], ['measurementId' => 5, 'value' => ['number' => $soc]]]]]);
};
$messung(10.5, 7245, 1500, 41);
pruefe($wb->vars['StromL2'] === 10.5 && $wb->vars['Leistung'] === 7245.0 && $wb->vars['SitzungEnergie'] === 1.5 && $wb->vars['Ladestand'] === 41
    && $wb->vars['Laedt'] === true, 'EVCEM/EVSOC: 10,5 A je Phase, 7245 W, Sitzung 1,5 kWh, Ladestand 41 %, lädt');
$messung(10.5, 7245, 3000, 43);
$messung(0, 0, 200, 43);
pruefe(abs($wb->vars['Energie'] - 3.2) < 0.0001 && $wb->vars['SitzungEnergie'] === 0.2 && $wb->vars['Laedt'] === false, 'Gesamtenergie läuft über den Sitzungswechsel weiter (1,5 + 1,5 + 0,2 kWh)');
$ld = [];
foreach ([0, 1, 2] as $i) {
    $ld[] = ['limitId' => $i, 'measurementId' => $i, 'limitCategory' => 'obligation', 'scopeType' => 'overloadProtection', 'unit' => 'A'];
    $ld[] = ['limitId' => $i + 10, 'measurementId' => $i, 'limitCategory' => 'recommendation', 'scopeType' => 'selfConsumption', 'unit' => 'A'];
}
$sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'loadControlLimitDescriptionListData', 'data' => ['loadControlLimitDescriptionData' => $ld]]);
$st = json_decode($wb->GetState(), true);
pruefe($st["opev"] === true && $st['oscev'] === true && $st['currentRange'] == [6, 16] && $st['currentRangeFrom'] === 'instance',
    'Grenzen je Phase erkannt; Kommunikation noch nicht ISO: Strombereich der Instanz 6–16 A statt der Gerätewerte (evcc #14418)');
pruefe(json_decode($wb->attrs['Geraet'], true)['angewendet'] === true, 'Nach dem Anstecken Strom einmal gesetzt (Gateway ohne Verbindung lehnt ab)');
pruefe($wb->SetChargeEnable(false) === false && $wb->attrs['PauseAusstehend'] === true && strpos(end($wb->log), 'Kommunikationsstandard') !== false,
    'Pausieren ohne bekannten Kommunikationsstandard aufgeschoben (evcc-Regel)');
$sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'deviceDiagnosisStateData', 'data' => ['operatingState' => 'standby']]);
pruefe($wb->vars['Zustand'] === 2, 'Fahrzeug standby: Zustand pausiert');
$sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'deviceConfigurationKeyValueListData', 'data' => ['deviceConfigurationKeyValueData' => [['keyId' => 0, 'value' => ['string' => 'iso15118-2ed1']]]]]);
pruefe($wb->vars['Kommunikation'] === 'iso15118-2ed1', 'Kommunikationsstandard ISO 15118 übernommen');
$st = json_decode($wb->GetState(), true);
pruefe($st['currentRange'] == [6, 32] && $st['currentRangeFrom'] === 'vehicle', 'ISO 15118-2: Strombereich des Fahrzeugs 6–32 A');
// Laden erlaubt (evcc Enabled): inaktive Grenze oder ≥ 1 A; ISO-Pause 0,1 A gilt als pausiert.
$grenzen = function (bool $aktiv, float $a) use ($sende) {
    $l = [];
    for ($i = 0; $i < 3; $i++) {
        $l[] = ['limitId' => $i, 'isLimitActive' => $aktiv, 'isLimitChangeable' => true, 'value' => ['number' => (int)round($a * 10), 'scale' => -1]];
    }
    $sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'loadControlLimitListData', 'data' => ['loadControlLimitData' => $l]]);
};
$grenzen(true, 0.1);
$a = json_decode($wb->GetState(), true)['chargingAllowed'];
$grenzen(true, 10.0);
$b = json_decode($wb->GetState(), true)['chargingAllowed'];
$grenzen(false, 32.0);
pruefe($a === false && $b === true && json_decode($wb->GetState(), true)['chargingAllowed'] === true, 'Laden erlaubt wie evcc: 0,1 A = Pause, 10 A = frei, inaktive Grenze = frei');
// Fahrzeugkennung: eine leere Meldung löscht die zuletzt gelesene nicht (Porsche PMCC meldet sie nur kurz).
$sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'identificationListData', 'data' => ['identificationData' => [['identificationId' => 0, 'identificationValue' => 'AA:BB']]]]);
$sende(['Event' => 'data', 'entity' => [1, 1], 'function' => 'identificationListData', 'data' => ['identificationData' => []]]);
pruefe($wb->vars['Fahrzeugkennung'] === 'AA:BB', 'Fahrzeugkennung bleibt nach leerer Meldung erhalten');
pruefe(($GLOBALS['PROFILE']['NRG.Ampere']['_suffix'] ?? '') === ' A' && ($GLOBALS['PROFILE']['CHEEB.kWhSession']['_suffix'] ?? '') === ' kWh (Sitzung)',
    'Profile wie ChargerHub: NRG.Ampere, Sitzungsenergie mit eigenem Suffix (keine Zählerverwechslung in MeterHub)');

$f = $wb->GetFunctions()[0];
pruefe($f['function'] === 'charger' && $f['contractVersion'] === '1.12' && $f['currentLimitID'] === $wb->ids['Ladestrom'] && $f['chargeEnableID'] === $wb->ids['Ladefreigabe']
    && $f['plugStateID'] === $wb->ids['Angesteckt'] && $f['minCurrent'] === 6.0 && $f['maxCurrent'] === 32.0 && $f['phases'] === 3 && $f['manufacturer'] === 'Demo'
    && $f['deviceSerial'] === 'SN1' && $f['sessionEnergyID'] === $wb->ids['SitzungEnergie'], 'Vertrag charger 1.12 feldgleich ChargerHub: IDs, 6–32 A, 3 Phasen, Hersteller, Seriennummer');

$html = formular($wb);
pruefe(strpos($html, 'Wallbox verbunden: Demo Wallbox X') !== false && strpos($html, 'Stromgrenze (OPEV): vorhanden') !== false
    && strpos($html, 'Eigenverbrauch (OSCEV): vorhanden') !== false && strpos($html, 'erlaubt 6–32 A') !== false, 'Formular: Wallbox, Fahrzeug, Anwendungsfälle, Strombereich');
pruefe(strpos(json_encode(json_decode($wb->GetConfigurationForm(), true)['actions']), 'CHEEB_LadenTesten') !== false, 'Laden testen im Aktionsbereich');

$sende(['Event' => 'remoteEntity', 'state' => 'removed', 'entity' => [1, 1]]);
pruefe($wb->vars['Angesteckt'] === false && $wb->vars['Zustand'] === 0 && $wb->vars['Leistung'] === 0.0 && $wb->vars['Erreichbar'] === true, 'Abgesteckt: Fahrzeugwerte geleert, Wallbox bleibt erreichbar');
$sende(['Event' => 'connection', 'state' => 'disconnected']);
pruefe($wb->vars['Erreichbar'] === false && $wb->status === 203, 'Verbindung weg: nicht erreichbar, Status 203');

require_once __DIR__ . '/i18n-texte.php';
$fehlt = array_diff_key(cheebQuelltexte($wurzel), $GLOBALS['LOCALE']['ChargerEEBUSHub']);
pruefe($fehlt === [], 'Zweisprachig: jeder Quelltext steht in locale.json' . ($fehlt ? ' – fehlt: ' . implode(' | ', array_keys($fehlt)) : ''));
$GLOBALS['LANG'] = 'en';
$wb->name = 'Wallbox';
$gw->name = 'EEBUS gateway';
$en = formular($wb);
pruefe(!preg_match('/Fahrzeug|Ladestrom|Verbindung|Wallbox verbunden/u', $en), 'Formular englisch: kein deutscher Text');
$GLOBALS['LANG'] = 'de';

// ---------------------------------------------------------------------------
// Teil 2: live gegen die simulierte Wallbox mit Fahrzeug
$lv = array_search('--live', $argv, true);
if ($lv !== false) {
    [$bin, $vCert, $vKey] = array_slice($argv, $lv + 1, 3);
    echo "\nTeil 2: live gegen simulierte Wallbox mit Fahrzeug (eebus-go/spine-go)\n";
    $port = 4740;
    $sock2 = new FakeSocket(41001);
    $gw2 = new EEBUSGateway(41002);
    $gw2->modul = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
    $gw2->parentId = 41001;
    $gw2->Create();
    $gw2->ApplyChanges();
    $wb2 = new ChargerEEBUSHub(41003);
    $wb2->name = 'Wallbox (live)';
    $wb2->parentId = 41002;
    $wb2->Create();
    $wb2->ApplyChanges();
    $log = sys_get_temp_dir() . '/cheeb-sim.log';
    $proc = proc_open([$bin, '-port', (string)$port, '-certpath', $vCert, '-keypath', $vKey, '-remoteski', $gw2->attrs['EigenerSki'], '-plug', '8', '-unplug', '70'],
        [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    sleep(2);
    try {
        $gw2->props['Host'] = '127.0.0.1';
        $gw2->props['Port'] = $port;
        $gw2->props['ErwarteterSki'] = (string)EEBGWCert::skiAusZertifikat((string)file_get_contents($vCert));
        $gw2->ApplyChanges();
        pruefe(strpos($gw2->GegenstellePruefen(), '✅') === 0, 'Gateway: Gegenstelle geprüft und übernommen');
        $dir = sys_get_temp_dir();
        file_put_contents("$dir/cheeb-c.pem", base64_decode($sock2->config['Certificate']));
        file_put_contents("$dir/cheeb-k.pem", base64_decode($sock2->config['PrivateKey']));
        file_put_contents("$dir/cheeb-ca.pem", base64_decode($sock2->config['CertificateAuthority']));
        $s = stream_socket_client("tls://127.0.0.1:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => [
            'local_cert' => "$dir/cheeb-c.pem", 'local_pk' => "$dir/cheeb-k.pem", 'cafile' => "$dir/cheeb-ca.pem",
            'verify_peer' => true, 'verify_peer_name' => false, 'allow_self_signed' => true,
            'ciphers' => 'ECDHE-ECDSA-AES128-SHA256', 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]]));
        pruefe($s !== false, 'TLS 1.2 mit Pflicht-Chiffre und Pinning');
        $GLOBALS['BRUECKE'] = $s;
        stream_set_blocking($s, false);
        $gw2->MessageSink(time(), 41001, IM_CHANGESTATUS, [IS_ACTIVE, 0]);
        $phase = 'wallbox';
        $ende = time() + 110;
        while (time() < $ende && $phase !== 'fertig') {
            $r = [$s]; $w = $x = null;
            if (stream_select($r, $w, $x, 1) > 0) {
                $d = fread($s, 65536);
                if ($d === '' && feof($s)) { break; }
                $gw2->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => mb_convert_encoding($d, 'UTF-8', 'ISO-8859-1')]));
            }
            $gw2->Takt();
            $v = $wb2->vars;
            if ($phase === 'wallbox' && $wb2->status === 102 && $v['Angesteckt'] === false) {
                pruefe(true, 'Wallbox angebunden, noch kein Fahrzeug');
                $phase = 'anstecken';
            } elseif ($phase === 'anstecken' && $v['Angesteckt'] === true && $v['Kommunikation'] === 'iec61851' && $v['Leistung'] == 11040.0) {
                pruefe(true, 'Fahrzeug später angesteckt (Discovery-Meldung): IEC 61851, lädt mit 16 A = ' . $v['Leistung'] . ' W, Ladestand ' . $v['Ladestand'] . ' %');
                $kennungLive = (string)$v['Fahrzeugkennung'];
                // Nur ganze Ampere (Elli Gen1): 10,7 A wird auf 10 A abgerundet.
                $wb2->props['GanzeAmpere'] = true;
                pruefe($wb2->SetChargeCurrent(10.7) === true, 'CHEEB_SetChargeCurrent(10,7) mit „nur ganze Ampere“');
                $phase = 'strom';
            } elseif ($phase === 'strom' && $v['StromL1'] == 10.0 && $v['Leistung'] == 6900.0) {
                pruefe(true, 'Fahrzeug folgt: 10 A je Phase (10,7 A abgerundet), 6900 W');
                pruefe($wb2->SetChargeEnable(false) === true, 'CHEEB_SetChargeEnable(false)');
                $phase = 'pause';
            } elseif ($phase === 'pause' && $v['Leistung'] == 0.0 && $v['Zustand'] === 2 && $v['Laedt'] === false) {
                pruefe(true, 'Pausiert: 0 W, Zustand pausiert');
                pruefe($wb2->SetChargeEnable(true) === true, 'CHEEB_SetChargeEnable(true)');
                $phase = 'weiter';
            } elseif ($phase === 'weiter' && $v['Leistung'] == 6900.0 && $v['Zustand'] === 1 && $v['Laedt'] === true) {
                pruefe(true, 'Lädt wieder mit 10 A');
                $phase = 'abstecken';
            } elseif ($phase === 'abstecken' && $v['Angesteckt'] === false && $v['Erreichbar'] === true) {
                pruefe(true, 'Fahrzeug abgesteckt (Discovery-Meldung), Wallbox bleibt erreichbar');
                $phase = 'fertig';
            }
        }
        pruefe($phase === 'fertig', 'Ablauf vollständig (Stand: ' . $phase . ')');
        pruefe((float)$wb2->vars['Energie'] > 0 && ($kennungLive ?? '') === '0a:1b:2c:3d:4e:5f' && $wb2->vars['Fahrzeugkennung'] === '',
            'Energie ' . $wb2->vars['Energie'] . ' kWh, Fahrzeugkennung gelesen und nach dem Abstecken geleert');
        $simLog = (string)file_get_contents($log);
        pruefe(strpos($simLog, 'SIM current 10 A') !== false && strpos($simLog, 'SIM current 0 A state standby') !== false && strpos($simLog, 'SIM ev unplugged') !== false,
            'Simulator: 10 A, Pause (standby), abgesteckt');
        fclose($s);
        unset($GLOBALS['BRUECKE']);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}

echo "\n" . $GLOBALS['OK'] . ' bestanden, ' . $GLOBALS['FEHLER'] . " fehlgeschlagen\n";
exit($GLOBALS['FEHLER'] > 0 ? 1 : 0);
