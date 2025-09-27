<?php

/**
 * IP-Symcon Modul: Philips Somneo (HF3671/01)
 * Minimal: Auslesen der eingestellten Weckzeiten über die lokale API.
 *
 * Hinweis: Die Somneo-Geräte verwenden i. d. R. HTTPS im LAN und oft ein selbstsigniertes Zertifikat.
 *          Dieses Modul bietet eine Option, die Zertifikatsprüfung zu deaktivieren (nicht empfohlen,
 *          aber praktisch für lokale Geräte mit Self-Signed-Certs).
 *
 *          Die exakte API-Struktur kann je nach Firmware variieren. Daher sind die Endpunkte als
 *          Eigenschaften konfigurierbar. Voreingestellt ist ein plausibler Standardpfad.
 */
class PhilipsSomneoWake extends IPSModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
    }

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Verbindungs- und API-Einstellungen
        // Optional: Komplette Basis-URL (überschreibt Host/Port/UseHTTPS, wenn gesetzt), z. B. "https://192.168.1.50:443"
        $this->RegisterPropertyString('BaseURL', '');
        $this->RegisterPropertyString('Host', ''); // z. B. 192.168.1.50
        $this->RegisterPropertyInteger('Port', 443);
        $this->RegisterPropertyBoolean('UseHTTPS', true);
        $this->RegisterPropertyBoolean('VerifyTLS', false); // Somneo nutzt häufig Self-Signed
        $this->RegisterPropertyInteger('Timeout', 5000); // ms

        // Auth (falls benötigt – viele Implementierungen nutzen Pairing/Token)
        $this->RegisterPropertyString('AuthHeaderName', ''); // z. B. Authorization
        $this->RegisterPropertyString('AuthHeaderValue', ''); // z. B. Bearer <token>

        // Endpunkte (anpassbar, da es unterschiedliche Firmwares/Bezeichnungen geben kann)
        // Voreinstellung für Weckzeiten (Alarme) gemäß inoffizieller Doku:
        $this->RegisterPropertyString('AlarmsEndpoint', '/di/v1/products/1/wualm/aalms');

        // Weitere optionale Endpunkte (leer = nicht verwendet)
        $this->RegisterPropertyString('DeviceInfoEndpoint', '/di/v1/products/0/firmware'); // GET
        $this->RegisterPropertyString('SensorsEndpoint', '/di/v1/products/1/wusrd'); // GET
        $this->RegisterPropertyString('LightStatusEndpoint', '/di/v1/products/1/wulgt'); // GET
        $this->RegisterPropertyString('LightControlEndpoint', '/di/v1/products/1/wulgt'); // PUT/POST (on/off)
        $this->RegisterPropertyString('LightControlMethod', 'PUT');
        $this->RegisterPropertyString('LightOnPayload', '{"onoff":true,"tempy":false}');
        $this->RegisterPropertyString('LightOffPayload', '{"onoff":false}');
        $this->RegisterPropertyString('BrightnessEndpoint', '/di/v1/products/1/wulgt'); // PUT/POST
        $this->RegisterPropertyString('BrightnessMethod', 'PUT');
        $this->RegisterPropertyString('BrightnessPayloadTemplate', '{"ltlvl":%d,"onoff":true,"tempy":false}');
        $this->RegisterPropertyString('SunsetStartEndpoint', '/di/v1/products/1/wudsk'); // PUT
        $this->RegisterPropertyString('SunsetStartMethod', 'PUT');
        $this->RegisterPropertyString('SunsetStartPayload', '{"onoff":true}');
        $this->RegisterPropertyString('SunsetStopEndpoint', '/di/v1/products/1/wudsk'); // PUT
        $this->RegisterPropertyString('SunsetStopMethod', 'PUT');
        $this->RegisterPropertyString('SunsetStopPayload', '{"onoff":false}');
        $this->RegisterPropertyString('VolumeStatusEndpoint', ''); // GET (optional)
        $this->RegisterPropertyString('VolumeSetEndpoint', ''); // PUT/POST
        $this->RegisterPropertyString('VolumeMethod', 'PUT');
        $this->RegisterPropertyString('VolumePayloadTemplate', '{"volume":%d}');
        $this->RegisterPropertyString('MuteSetEndpoint', ''); // PUT/POST
        $this->RegisterPropertyString('MuteMethod', 'PUT');
        $this->RegisterPropertyString('MuteOnPayload', '{"mute":true}');
        $this->RegisterPropertyString('MuteOffPayload', '{"mute":false}');

        // Aktualisierungsintervall für Status (Sekunden)
        $this->RegisterPropertyInteger('UpdateInterval', 300);

        // Variablen zur Anzeige der Alarme
        // Rohdaten
        $this->RegisterVariableString('AlarmsRaw', 'Weckzeiten (JSON)', '', 10);
        IPS_SetIcon($this->GetIDForIdent('AlarmsRaw'), 'Clock');

        // Einzelne Alarme (bis 4 – die üblichen Profile). Bei Bedarf über JSON auswerten.
        for ($i = 1; $i <= 4; $i++) {
            $this->RegisterVariableBoolean("Alarm{$i}Enabled", "Wecker {$i} aktiv", '', 20 + ($i * 10));
            IPS_SetIcon($this->GetIDForIdent("Alarm{$i}Enabled"), 'Clock');
            $this->RegisterVariableString("Alarm{$i}Time", "Wecker {$i} Zeit", '', 21 + ($i * 10));
            IPS_SetIcon($this->GetIDForIdent("Alarm{$i}Time"), 'Clock');
        }

        // Zusatz-Variablen für erweiterte API-Funktionen
        $this->RegisterVariableString('DeviceInfoRaw', 'Geräteinfo (JSON)', '', 200);
        IPS_SetIcon($this->GetIDForIdent('DeviceInfoRaw'), 'Information');
        $this->RegisterVariableString('DeviceName', 'Gerätename', '', 201);
        IPS_SetIcon($this->GetIDForIdent('DeviceName'), 'Information');
        $this->RegisterVariableString('FirmwareVersion', 'Firmware-Version', '', 202);
        IPS_SetIcon($this->GetIDForIdent('FirmwareVersion'), 'Gear');

        $this->RegisterVariableString('SensorsRaw', 'Sensoren (JSON)', '', 210);
        IPS_SetIcon($this->GetIDForIdent('SensorsRaw'), 'Information');
        $this->RegisterVariableFloat('Temperature', 'Temperatur', '', 211);
        IPS_SetIcon($this->GetIDForIdent('Temperature'), 'Temperature');
        $this->RegisterVariableFloat('Humidity', 'Luftfeuchtigkeit', '', 212);
        IPS_SetIcon($this->GetIDForIdent('Humidity'), 'Drops');
        $this->RegisterVariableFloat('Lux', 'Helligkeit (Lux)', '', 213);
        IPS_SetIcon($this->GetIDForIdent('Lux'), 'Sun');

        $this->RegisterVariableBoolean('LightOn', 'Licht an', '~Switch', 220);
        $this->EnableAction('LightOn');
        IPS_SetIcon($this->GetIDForIdent('LightOn'), 'Bulb');
        $this->RegisterVariableInteger('Brightness', 'Helligkeit (%)', '~Intensity.100', 221);
        $this->EnableAction('Brightness');
        IPS_SetIcon($this->GetIDForIdent('Brightness'), 'Bulb');

        $this->RegisterVariableBoolean('SunsetActive', 'Sonnenuntergang aktiv', '~Switch', 230);
        $this->EnableAction('SunsetActive');
        IPS_SetIcon($this->GetIDForIdent('SunsetActive'), 'Sun');

        $this->RegisterVariableInteger('Volume', 'Lautstärke', '~Intensity.100', 240);
        $this->EnableAction('Volume');
        IPS_SetIcon($this->GetIDForIdent('Volume'), 'Speaker');
        $this->RegisterVariableBoolean('Mute', 'Stumm', '~Switch', 241);
        $this->EnableAction('Mute');
        IPS_SetIcon($this->GetIDForIdent('Mute'), 'Mute');

        // Manuelle Aktualisierung
        $this->RegisterVariableBoolean('Refresh', 'Aktualisieren', '~Switch', 90);
        $this->EnableAction('Refresh');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Status abhängig von Konfiguration setzen
        $baseURL = trim($this->ReadPropertyString('BaseURL'));
        $host = trim($this->ReadPropertyString('Host'));
        if ($baseURL === '' && $host === '') {
            $this->SetStatus(104); // Bitte URL oder Host/IP konfigurieren
        } else {
            $this->SetStatus(102); // Aktiv
        }

        // Timer für automatische Aktualisierung registrieren/aktualisieren
        $intervalSec = max(0, (int)$this->ReadPropertyInteger('UpdateInterval'));
        $intervalMs = $intervalSec > 0 ? $intervalSec * 1000 : 0;
        // Timer anlegen (falls nicht vorhanden) und Intervall separat setzen
        $this->RegisterTimer('UpdateTimer', 0, 'SOMNEO_Update($_IPS["TARGET"]);');
        $this->SetTimerInterval('UpdateTimer', $intervalMs);
        $this->SendDebug('ApplyChanges', 'UpdateTimer Intervall: ' . $intervalMs . ' ms', 0);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Refresh':
                try {
                    $this->Update();
                } finally {
                    // Schalter wieder zurücksetzen
                    $this->SetValue('Refresh', false);
                }
                break;
            case 'LightOn':
                $this->setLightOn((bool)$Value);
                // Nach erfolgreichem Senden, lokalen Zustand setzen
                $this->SetValue('LightOn', (bool)$Value);
                break;
            case 'Brightness':
                $val = max(0, min(100, (int)$Value));
                $this->setBrightness($val);
                $this->SetValue('Brightness', $val);
                break;
            case 'SunsetActive':
                if ((bool)$Value) {
                    $this->startSunset();
                } else {
                    $this->stopSunset();
                }
                // Status lokal setzen, tatsächlicher Gerätestatus kann abweichen
                $this->SetValue('SunsetActive', (bool)$Value);
                break;
            case 'Volume':
                $vol = max(0, min(100, (int)$Value));
                $this->setVolume($vol);
                $this->SetValue('Volume', $vol);
                break;
            case 'Mute':
                $this->setMute((bool)$Value);
                $this->SetValue('Mute', (bool)$Value);
                break;
            default:
                throw new Exception('Invalid action: ' . $Ident);
        }
    }

    public function Update()
    {
        $this->SendDebug('Update', 'Starte Aktualisierung', 0);
        $baseURL = trim($this->ReadPropertyString('BaseURL'));
        $host = trim($this->ReadPropertyString('Host'));
        if ($baseURL === '' && $host === '') {
            $this->LogMessage('Weder URL noch Host konfiguriert.', KL_WARNING);
            return;
        }

        // Alarme
        $path = $this->ReadPropertyString('AlarmsEndpoint');
        if ($path !== '') {
            $json = $this->request('GET', $path);
            if ($json !== null) {
                $this->SetValue('AlarmsRaw', json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $this->parseAndPublishAlarms($json);
            } else {
                $this->LogMessage('Konnte Alarme nicht abrufen (null).', KL_WARNING);
            }
        }

        // Geräteinfo
        $this->updateDeviceInfo();

        // Sensoren
        $this->updateSensors();

        // Lichtstatus
        $this->updateLightStatus();

        // Audio/Volume
        $this->updateAudioStatus();
    }

    private function parseAndPublishAlarms($json)
    {
        // Ziel: Bis zu 4 Alarme erkennen. Da Struktur je nach FW abweichen kann,
        // versuchen wir ein paar gängige Varianten.

        // Variante C: Struktur aus /di/v1/products/1/wualm/aalms mit Feldern almhr, almmn, daynm
        if (is_object($json) && (isset($json->almhr) || isset($json->almmn))) {
            for ($i = 1; $i <= 4; $i++) {
                $h = 0;
                $m = 0;
                $dn = 0;
                if (isset($json->almhr) && is_array($json->almhr) && isset($json->almhr[$i - 1])) {
                    $h = (int)$json->almhr[$i - 1];
                }
                if (isset($json->almmn) && is_array($json->almmn) && isset($json->almmn[$i - 1])) {
                    $m = (int)$json->almmn[$i - 1];
                }
                if (isset($json->daynm) && is_array($json->daynm) && isset($json->daynm[$i - 1])) {
                    $dn = (int)$json->daynm[$i - 1];
                }
                // Aktiv, wenn Wochentagsmaske > 0 oder Zeit gesetzt (00:00 wird als aus angenommen)
                $enabled = ($dn > 0) || ($h > 0 || $m > 0);
                $timeStr = ($h > 0 || $m > 0) ? sprintf('%02d:%02d', $h, $m) : '';
                $this->SetValue("Alarm{$i}Enabled", $enabled);
                $this->SetValue("Alarm{$i}Time", $timeStr);
            }
            return;
        }

        $alarms = [];
        if (is_array($json)) {
            // Variante A: Liste von Alarmobjekten direkt
            $alarms = $json;
        } elseif (is_object($json)) {
            // Variante B: Objekt mit Feld "alarms" oder "wakeups" o.ä.
            foreach ([
                'alarms', 'wakeups', 'wakeAlarms', 'profiles', 'alarmList'
            ] as $key) {
                if (isset($json->$key) && is_array($json->$key)) {
                    $alarms = $json->$key;
                    break;
                }
            }
        }

        for ($i = 1; $i <= 4; $i++) {
            $enabled = false;
            $timeStr = '';

            if (isset($alarms[$i - 1])) {
                $a = $alarms[$i - 1];
                // Häufige Feldnamen raten: enabled/active/on, time ("HH:MM"), hour/min
                if (is_object($a)) {
                    $enabled = (bool)($a->enabled ?? $a->active ?? $a->on ?? false);
                    if (isset($a->time) && is_string($a->time)) {
                        $timeStr = $a->time;
                    } elseif (isset($a->hour) || isset($a->minute) || isset($a->min) || isset($a->hr)) {
                        $h = (int)($a->hour ?? $a->hr ?? 0);
                        $m = (int)($a->minute ?? $a->min ?? 0);
                        $timeStr = sprintf('%02d:%02d', $h, $m);
                    }
                } elseif (is_array($a)) {
                    $enabled = (bool)($a['enabled'] ?? $a['active'] ?? $a['on'] ?? false);
                    if (isset($a['time']) && is_string($a['time'])) {
                        $timeStr = $a['time'];
                    } else {
                        $h = (int)($a['hour'] ?? $a['hr'] ?? 0);
                        $m = (int)($a['minute'] ?? $a['min'] ?? 0);
                        if ($h || $m) {
                            $timeStr = sprintf('%02d:%02d', $h, $m);
                        }
                    }
                }
            }

            $this->SetValue("Alarm{$i}Enabled", $enabled);
            $this->SetValue("Alarm{$i}Time", $timeStr);
        }
    }

    private function updateDeviceInfo()
    {
        $ep = trim($this->ReadPropertyString('DeviceInfoEndpoint'));
        if ($ep === '') {
            return;
        }
        $json = $this->request('GET', $ep);
        if ($json === null) {
            return;
        }
        $this->SetValue('DeviceInfoRaw', json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (is_object($json)) {
            $name = '';
            $fw = '';
            foreach (['name', 'devname', 'deviceName', 'productName', 'model'] as $k) {
                if (isset($json->$k) && is_string($json->$k)) {
                    $name = (string)$json->$k;
                    break;
                }
            }
            foreach (['firmware', 'fw', 'swver', 'swversion', 'version'] as $k) {
                if (isset($json->$k)) {
                    $fw = is_string($json->$k) ? $json->$k : json_encode($json->$k);
                    break;
                }
            }
            if ($name !== '') {
                $this->SetValue('DeviceName', $name);
            }
            if ($fw !== '') {
                $this->SetValue('FirmwareVersion', (string)$fw);
            }
        }
    }

    private function updateSensors()
    {
        $ep = trim($this->ReadPropertyString('SensorsEndpoint'));
        if ($ep === '') {
            return;
        }
        $json = $this->request('GET', $ep);
        if ($json === null) {
            return;
        }
        $this->SetValue('SensorsRaw', json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $temp = null; $hum = null; $lux = null;
        if (is_object($json)) {
            $temp = $this->tryFindNumeric($json, ['temperature', 'temp', 'tmp']);
            $hum = $this->tryFindNumeric($json, ['humidity', 'hum', 'rh']);
            $lux = $this->tryFindNumeric($json, ['light_level', 'lux', 'illum', 'light', 'brightness']);
        } elseif (is_array($json)) {
            // ggf. erstes Objekt betrachten
            foreach ($json as $item) {
                if (is_object($item)) {
                    $temp = $temp ?? $this->tryFindNumeric($item, ['temperature', 'temp', 'tmp']);
                    $hum = $hum ?? $this->tryFindNumeric($item, ['humidity', 'hum', 'rh']);
                    $lux = $lux ?? $this->tryFindNumeric($item, ['light_level', 'lux', 'illum', 'light', 'brightness']);
                }
            }
        }
        if ($temp !== null) {
            $this->SetValue('Temperature', (float)$temp);
        }
        if ($hum !== null) {
            $this->SetValue('Humidity', (float)$hum);
        }
        if ($lux !== null) {
            $this->SetValue('Lux', (float)$lux);
        }
    }

    private function updateLightStatus()
    {
        $ep = trim($this->ReadPropertyString('LightStatusEndpoint'));
        if ($ep === '') {
            return;
        }
        $json = $this->request('GET', $ep);
        if ($json === null) {
            return;
        }
        if (is_object($json)) {
            $on = $this->tryFindBool($json, ['onoff', 'on', 'state', 'power', 'pwr', 'lamp', 'light']);
            if ($on !== null) {
                $this->SetValue('LightOn', (bool)$on);
            }
            $b = $this->tryFindNumeric($json, ['ltlvl', 'brightness', 'bri', 'level', 'intensity']);
            if ($b !== null) {
                $b = (int)$b;
                // Somneo: ltlvl 1..25 => 0..100 %
                if ($b >= 1 && $b <= 25) {
                    $b = (int)round($b * 100 / 25);
                } elseif ($b > 100) { // ggf. 0..255 auf 0..100 skalieren
                    $b = (int)round(($b / 255) * 100);
                }
                $b = max(0, min(100, $b));
                $this->SetValue('Brightness', $b);
            }
        }
    }

    private function updateAudioStatus()
    {
        $ep = trim($this->ReadPropertyString('VolumeStatusEndpoint'));
        if ($ep === '') {
            return;
        }
        $json = $this->request('GET', $ep);
        if ($json === null) {
            return;
        }
        if (is_object($json)) {
            $v = $this->tryFindNumeric($json, ['volume', 'vol']);
            if ($v !== null) {
                $v = (int)$v;
                if ($v > 100) {
                    $v = (int)min(100, $v);
                }
                $v = max(0, min(100, $v));
                $this->SetValue('Volume', $v);
            }
            $mute = $this->tryFindBool($json, ['mute', 'muted']);
            if ($mute !== null) {
                $this->SetValue('Mute', (bool)$mute);
            }
        }
    }

    private function tryFindNumeric($obj, $keys)
    {
        foreach ($keys as $k) {
            if (isset($obj->$k) && is_numeric($obj->$k)) {
                return $obj->$k;
            }
        }
        // 1. Ebene tiefer durchsuchen (häufige Strukturvarianten)
        foreach ($obj as $v) {
            if (is_object($v)) {
                foreach ($keys as $k) {
                    if (isset($v->$k) && is_numeric($v->$k)) {
                        return $v->$k;
                    }
                }
            }
        }
        return null;
    }

    private function tryFindBool($obj, $keys)
    {
        foreach ($keys as $k) {
            if (isset($obj->$k)) {
                $val = $obj->$k;
                if (is_bool($val)) return $val;
                if (is_numeric($val)) return ((int)$val) !== 0;
                if (is_string($val)) return in_array(strtolower($val), ['1', 'true', 'on', 'yes'], true);
            }
        }
        // 1. Ebene tiefer
        foreach ($obj as $v) {
            if (is_object($v)) {
                foreach ($keys as $k) {
                    if (isset($v->$k)) {
                        $val = $v->$k;
                        if (is_bool($val)) return $val;
                        if (is_numeric($val)) return ((int)$val) !== 0;
                        if (is_string($val)) return in_array(strtolower($val), ['1', 'true', 'on', 'yes'], true);
                    }
                }
            }
        }
        return null;
    }

    private function setLightOn($on)
    {
        $ep = trim($this->ReadPropertyString('LightControlEndpoint'));
        if ($ep === '') {
            $this->LogMessage('LightControlEndpoint ist nicht konfiguriert.', KL_WARNING);
            return;
        }
        $method = strtoupper(trim($this->ReadPropertyString('LightControlMethod')) ?: 'PUT');
        $payload = $on ? $this->ReadPropertyString('LightOnPayload') : $this->ReadPropertyString('LightOffPayload');
        $this->request($method, $ep, $payload);
    }

    private function setBrightness($value)
    {
        $ep = trim($this->ReadPropertyString('BrightnessEndpoint'));
        if ($ep === '') {
            $this->LogMessage('BrightnessEndpoint ist nicht konfiguriert.', KL_WARNING);
            return;
        }
        $method = strtoupper(trim($this->ReadPropertyString('BrightnessMethod')) ?: 'PUT');
        $tpl = $this->ReadPropertyString('BrightnessPayloadTemplate');
        // Sonderfall: 0% -> Licht ausschalten
        if ($value <= 0) {
            $this->setLightOn(false);
            return;
        }
        // Somneo: ltlvl 1..25
        $level = max(1, min(25, (int)round($value * 25 / 100)));
        if (strpos($tpl, '%d') !== false) {
            $payload = sprintf($tpl, $level);
        } else {
            $payload = $tpl; // falls ohne Platzhalter
        }
        $this->request($method, $ep, $payload);
    }

    private function startSunset()
    {
        $ep = trim($this->ReadPropertyString('SunsetStartEndpoint'));
        if ($ep === '') {
            $this->LogMessage('SunsetStartEndpoint ist nicht konfiguriert.', KL_WARNING);
            return;
        }
        $method = strtoupper(trim($this->ReadPropertyString('SunsetStartMethod')) ?: 'POST');
        $payload = $this->ReadPropertyString('SunsetStartPayload');
        $this->request($method, $ep, $payload);
    }

    private function stopSunset()
    {
        $ep = trim($this->ReadPropertyString('SunsetStopEndpoint'));
        if ($ep === '') {
            $this->LogMessage('SunsetStopEndpoint ist nicht konfiguriert.', KL_WARNING);
            return;
        }
        $method = strtoupper(trim($this->ReadPropertyString('SunsetStopMethod')) ?: 'POST');
        $payload = $this->ReadPropertyString('SunsetStopPayload');
        $this->request($method, $ep, $payload);
    }

    private function setVolume($value)
    {
        $ep = trim($this->ReadPropertyString('VolumeSetEndpoint'));
        if ($ep === '') {
            $this->LogMessage('VolumeSetEndpoint ist nicht konfiguriert.', KL_WARNING);
            return;
        }
        $method = strtoupper(trim($this->ReadPropertyString('VolumeMethod')) ?: 'PUT');
        $tpl = $this->ReadPropertyString('VolumePayloadTemplate');
        if (strpos($tpl, '%d') !== false) {
            $payload = sprintf($tpl, $value);
        } else {
            $payload = $tpl;
        }
        $this->request($method, $ep, $payload);
    }

    private function setMute($mute)
    {
        $ep = trim($this->ReadPropertyString('MuteSetEndpoint'));
        if ($ep === '') {
            $this->LogMessage('MuteSetEndpoint ist nicht konfiguriert.', KL_WARNING);
            return;
        }
        $method = strtoupper(trim($this->ReadPropertyString('MuteMethod')) ?: 'PUT');
        $payload = $mute ? $this->ReadPropertyString('MuteOnPayload') : $this->ReadPropertyString('MuteOffPayload');
        $this->request($method, $ep, $payload);
    }

    private function request($method, $path, $payload = null)
    {
        $timeoutMs = $this->ReadPropertyInteger('Timeout');
        $verify = $this->ReadPropertyBoolean('VerifyTLS');

        $baseURL = trim($this->ReadPropertyString('BaseURL'));
        $host = trim($this->ReadPropertyString('Host'));
        $port = $this->ReadPropertyInteger('Port');
        $https = $this->ReadPropertyBoolean('UseHTTPS');

        // Normalize endpoint path
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $url = '';
        $scheme = 'http';

        if ($baseURL !== '') {
            // If scheme is missing, infer from UseHTTPS
            $effectiveBase = $baseURL;
            if (!preg_match('#^https?://#i', $effectiveBase)) {
                $effectiveBase = ($https ? 'https://' : 'http://') . $effectiveBase;
            }
            $parts = parse_url($effectiveBase);
            $scheme = strtolower($parts['scheme'] ?? ($https ? 'https' : 'http'));
            $baseHost = $parts['host'] ?? '';
            $basePort = $parts['port'] ?? (($scheme === 'https') ? 443 : 80);
            $basePath = rtrim($parts['path'] ?? '', '/');
            if ($baseHost === '') {
                $this->SendDebug('request', 'BaseURL ist ungültig (kein Host)', 0);
                return null;
            }
            $url = sprintf('%s://%s:%d%s%s', $scheme, $baseHost, $basePort, $basePath, $path);
        } else {
            if ($host === '') {
                $this->SendDebug('request', 'Host ist leer und BaseURL nicht gesetzt', 0);
                return null;
            }
            $scheme = $https ? 'https' : 'http';
            $url = sprintf('%s://%s:%d%s', $scheme, $host, $port, $path);
        }

        $this->SendDebug('request.url', $url, 0);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => max(1000, (int)$timeoutMs),
            CURLOPT_TIMEOUT_MS => max(1000, (int)$timeoutMs + 1000),
            CURLOPT_SSL_VERIFYPEER => ($scheme === 'https') ? $verify : false,
            CURLOPT_SSL_VERIFYHOST => ($scheme === 'https') ? ($verify ? 2 : 0) : 0,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ]);

        if ($payload !== null) {
            $body = is_string($payload) ? $payload : json_encode($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $this->LogMessage('HTTP Fehler: ' . $err, KL_WARNING);
            return null;
        }

        $this->SendDebug('request.status', (string)$status, 0);
        $this->SendDebug('request.body', $response, 0);

        // 204 No Content
        if ($status === 204 || $response === '') {
            return [];
        }

        $decoded = json_decode($response);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // ggf. Klartext zurückgeben
            return [
                'status' => $status,
                'body' => $response
            ];
        }
        return $decoded;
    }

    private function buildHeaders()
    {
        $headers = ['Accept: application/json'];
        if ($this->ReadPropertyString('AuthHeaderName') !== '' && $this->ReadPropertyString('AuthHeaderValue') !== '') {
            $headers[] = $this->ReadPropertyString('AuthHeaderName') . ': ' . $this->ReadPropertyString('AuthHeaderValue');
        }
        // JSON default
        $headers[] = 'Content-Type: application/json';
        return $headers;
    }
}


// Alias-Klassen für Kernel-Kompatibilität / Migrationspfade
// Der IP-Symcon Kernel leitet Klassennamen teils aus dem Modulnamen bzw. Prefix ab.
// Diese Alias-Klassen stellen sicher, dass sowohl "PhilipsSomneoWakeupLight" als auch der alte Name
// "SOMNEO_Somneo" gefunden werden und auf die eigentliche Implementierung verweisen.
class PhilipsSomneoWakeupLight extends PhilipsSomneoWake {}
class SOMNEO_Somneo extends PhilipsSomneoWake {}
