<?php

declare(strict_types=1);

/**
 * MOVA LiDAX — IP-Symcon Modul (Stufe 1: Status über MOVAhome-Cloud).
 *
 * Portiert aus dem getesteten Python-Client (mova_client.py). Reines REST:
 * Login (OAuth/md5), Geräteliste, Statusabruf per sendCommand (get_properties).
 * Bewegende Befehle sind in dieser Stufe NICHT enthalten.
 */
class MovaLidax extends IPSModule
{
    private const SALT       = 'RAylYC%fmSKp7%Tq';
    private const AUTH_BASIC = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';
    private const USER_AGENT = 'Mova_Smarthome/1.5.59 (iPhone; iOS 16.0; Scale/3.00)';
    private const PORT       = '13267';

    // Status -> Klartext (DeviceStatus 2:1 / latestStatus)
    private const STATUS_MAP = [
        0 => 'Kein Status', 1 => 'Mäht', 2 => 'Standby', 3 => 'Pausiert',
        4 => 'Pause (Fehler)', 5 => 'Rückkehr zur Station', 6 => 'Lädt',
        11 => 'Kartierung', 13 => 'Vollständig geladen', 14 => 'Update'
    ];
    private const CHARGING_MAP = [
        0 => 'Nicht angedockt', 1 => 'Lädt', 2 => 'Lädt nicht',
        3 => 'Geladen', 5 => 'Rückkehr zum Laden', 16 => 'Pausiert (Temperatur)'
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Region', 'eu');
        $this->RegisterPropertyString('AccountType', 'mova');
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyInteger('Interval', 60);
        $this->RegisterPropertyBoolean('AllowControl', false);

        // Token-/Geräte-Cache
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('Uuid', '');
        $this->RegisterAttributeString('TenantId', '');
        $this->RegisterAttributeInteger('KeyExpire', 0);
        $this->RegisterAttributeString('Did', '');
        $this->RegisterAttributeString('Host', '');
        $this->RegisterAttributeString('Model', '');
        $this->RegisterAttributeString('Uid', '');
        $this->RegisterAttributeString('Ver', '');
        // Karten-Metadaten für Zonen-/Begrenzungs-Mähen
        $this->RegisterAttributeString('MapZones', '[]');     // [{id,name}]
        $this->RegisterAttributeString('MapContours', '[]');  // [[id,sub], ...]
        $this->RegisterAttributeInteger('MapId', 1);

        $this->RegisterTimer('Poll', 0, 'MOVA_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->createProfiles();

        $this->RegisterVariableInteger('Battery', $this->Translate('Battery'), '~Battery.100', 10);
        $this->RegisterVariableInteger('State', $this->Translate('Status'), 'MOVA.Status', 20);
        $this->RegisterVariableInteger('Charging', $this->Translate('Charging'), 'MOVA.Charging', 30);
        $this->RegisterVariableBoolean('Online', $this->Translate('Online'), '', 40);
        $this->RegisterVariableString('Firmware', $this->Translate('Firmware'), '', 50);
        $this->RegisterVariableInteger('LastUpdate', $this->Translate('Last Update'), '~UnixTimestamp', 60);

        // Steuer-Variablen (im WebFront/Tile bedienbar)
        $this->RegisterVariableInteger('Action', $this->Translate('Command'), 'MOVA.Action', 70);
        $this->EnableAction('Action');
        $this->RegisterVariableInteger('Zone', $this->Translate('Mow zone'), 'MOVA.Zones', 80);
        $this->EnableAction('Zone');

        // Bei Konfigurationsänderung Geräte-Cache verwerfen (Region/Konto könnte sich geändert haben)
        $this->WriteAttributeString('Did', '');
        $this->WriteAttributeString('Host', '');

        $email = $this->ReadPropertyString('Email');
        $pass  = $this->ReadPropertyString('Password');

        if ($email === '' || $pass === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('Poll', 0);
            return;
        }

        $this->SetStatus(102);
        $interval = max(15, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('Poll', $interval * 1000);
    }

    /**
     * Öffentlich (MOVA_Poll): Status abrufen und Variablen aktualisieren.
     */
    public function Poll(): void
    {
        try {
            if (!$this->ensureLogin()) {
                $this->SetStatus(201);
                return;
            }
            if (!$this->ensureDevice()) {
                $this->SetStatus(202);
                return;
            }
            $status = $this->fetchStatus();
            if ($status === null) {
                $this->SetStatus(203);
                return;
            }

            if (isset($status['battery'])) {
                $this->SetValue('Battery', (int) $status['battery']);
            }
            if (isset($status['state'])) {
                $this->SetValue('State', (int) $status['state']);
            }
            if (isset($status['charging'])) {
                $this->SetValue('Charging', (int) $status['charging']);
            }
            $this->SetValue('Online', (bool) ($status['online'] ?? false));
            if (($status['firmware'] ?? '') !== '') {
                $this->SetValue('Firmware', (string) $status['firmware']);
            }
            $this->SetValue('LastUpdate', time());
            $this->SetStatus(102);
        } catch (Exception $e) {
            $this->LogMessage('Poll-Fehler: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    // ------------------------------------------------------------------ //
    //  Steuerung (Stufe 2) — bewegende Befehle hinter Sicherheitsschalter
    // ------------------------------------------------------------------ //
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Action':
                $this->dispatchAction((int) $Value);
                $this->SetValue('Action', 0);
                break;
            case 'Zone':
                $this->SetValue('Zone', (int) $Value);
                if ((int) $Value > 0) {
                    $this->StartZone((int) $Value);
                }
                break;
        }
    }

    private function dispatchAction(int $value): void
    {
        switch ($value) {
            case 1:  $this->StartAll();   break;
            case 2:  $this->StartEdge();  break;
            case 10: $this->MowerStop();  break;
            case 11: $this->MowerDock();  break;
            case 12: $this->MowerPause(); break;
            case 13: $this->ReturnHome(); break;
        }
    }

    /** Gesamtes Gebiet mähen (Action 2:50, Opcode 100). */
    public function StartAll(): bool
    {
        if (!$this->requireControl()) {
            return false;
        }
        $mapId = $this->ReadAttributeInteger('MapId');
        return $this->mowTask('Gesamtes Gebiet',
            ['m' => 'a', 'p' => 0, 'o' => 100, 'd' => ['region_id' => [$mapId], 'area_id' => []]]);
    }

    /** Begrenzung/Rand mähen (Opcode 101) — braucht geladene Konturen. */
    public function StartEdge(): bool
    {
        if (!$this->requireControl()) {
            return false;
        }
        $contours = json_decode($this->ReadAttributeString('MapContours'), true);
        if (!is_array($contours) || count($contours) === 0) {
            echo $this->Translate('No contours loaded — please load the map first.');
            return false;
        }
        return $this->mowTask('Begrenzung',
            ['m' => 'a', 'p' => 0, 'o' => 101, 'd' => ['edge' => $contours]]);
    }

    /** Einzelne Zone mähen (Opcode 102). */
    public function StartZone(int $ZoneID): bool
    {
        if (!$this->requireControl()) {
            return false;
        }
        if ($ZoneID <= 0) {
            return false;
        }
        return $this->mowTask('Zone ' . $ZoneID,
            ['m' => 'a', 'p' => 0, 'o' => 102, 'd' => ['region' => [$ZoneID]]]);
    }

    // Stop/Dock/Pause sind "beruhigende" Befehle -> ohne Freischalt-Pflicht.
    public function MowerStop(): bool
    {
        return $this->simpleAction(5, 2, 'Stop');
    }

    public function MowerDock(): bool
    {
        return $this->simpleAction(5, 3, 'Dock');
    }

    public function MowerPause(): bool
    {
        return $this->simpleAction(5, 4, 'Pause');
    }

    /**
     * Aufgabe abbrechen und heimfahren: STOP (beendet die Mähaufgabe),
     * kurze Pause, dann DOCK (zur Station). So bleibt keine Aufgabe offen.
     */
    public function ReturnHome(): bool
    {
        $stop = $this->simpleAction(5, 2, 'Stop (Aufgabe beenden)');
        IPS_Sleep(1500);
        $dock = $this->simpleAction(5, 3, 'Dock');
        return $stop && $dock;
    }

    /** Karten-Metadaten (Zonen + Konturen) für Zonen-/Begrenzungs-Mähen laden. */
    public function LoadMap(): bool
    {
        if (!$this->ensureLogin() || !$this->ensureDevice()) {
            echo $this->Translate('Not connected.');
            return false;
        }
        $resp = $this->apiRequest('dreame-user-iot/iotuserdata/getDeviceData',
            ['did' => $this->ReadAttributeString('Did'), 'model' => []]);
        $data = (is_array($resp) && isset($resp['data']) && is_array($resp['data'])) ? $resp['data'] : null;
        if ($data === null) {
            echo $this->Translate('No map data received.');
            return false;
        }
        $map = $this->decodeMap($data);
        if ($map === null) {
            echo $this->Translate('Could not parse map.');
            return false;
        }

        $zones = [];
        foreach ($this->mapList($map, 'mowingAreas') as $entry) {
            $zid  = (int) ($entry[0] ?? 0);
            $name = (is_array($entry[1] ?? null)) ? (string) ($entry[1]['name'] ?? '') : '';
            if ($name === '') {
                $name = $this->Translate('Zone') . ' ' . $zid;
            }
            $zones[] = ['id' => $zid, 'name' => $name];
        }
        $contours = [];
        foreach ($this->mapList($map, 'contours') as $entry) {
            $cid  = $entry[0] ?? null;
            $pair = null;
            if (is_array($cid) && count($cid) === 2) {
                $pair = [(int) $cid[0], (int) $cid[1]];
            } elseif (is_string($cid) && strpos($cid, ',') !== false) {
                $parts = explode(',', $cid);
                if (count($parts) === 2) {
                    $pair = [(int) trim($parts[0]), (int) trim($parts[1])];
                }
            } elseif (is_int($cid)) {
                $pair = [$cid, 0];
            }
            if ($pair !== null) {
                $contours[] = $pair;
            }
        }
        $mapId = isset($map['mapIndex']) ? ((int) $map['mapIndex'] + 1) : 1;

        $this->WriteAttributeString('MapZones', json_encode($zones));
        $this->WriteAttributeString('MapContours', json_encode($contours));
        $this->WriteAttributeInteger('MapId', $mapId);
        $this->rebuildZoneProfile($zones);

        $msg = sprintf($this->Translate('Map loaded: %d zones, %d contours.'), count($zones), count($contours));
        $this->LogMessage($msg, KL_NOTIFY);
        echo $msg;
        return true;
    }

    private function requireControl(): bool
    {
        if (!$this->ReadPropertyBoolean('AllowControl')) {
            echo $this->Translate('Control is locked. Enable "Allow control" in the configuration first.');
            $this->LogMessage('Bewegender Befehl blockiert (Steuerung nicht freigeschaltet).', KL_WARNING);
            return false;
        }
        return true;
    }

    private function mowTask(string $label, array $payload)
    {
        if (!$this->ensureLogin() || !$this->ensureDevice()) {
            echo $this->Translate('Not connected.');
            return false;
        }
        $res = $this->sendAction(2, 50, [$payload]);
        $ok  = $res !== null;
        $this->LogMessage('Maehbefehl "' . $label . '": ' . ($ok ? 'OK' : 'fehlgeschlagen'),
            $ok ? KL_NOTIFY : KL_WARNING);
        return $ok;
    }

    private function simpleAction(int $siid, int $aiid, string $label)
    {
        if (!$this->ensureLogin() || !$this->ensureDevice()) {
            echo $this->Translate('Not connected.');
            return false;
        }
        $res = $this->sendAction($siid, $aiid, []);
        $ok  = $res !== null;
        $this->LogMessage('Befehl "' . $label . '": ' . ($ok ? 'OK' : 'fehlgeschlagen'),
            $ok ? KL_NOTIFY : KL_WARNING);
        return $ok;
    }

    private function sendAction(int $siid, int $aiid, array $in)
    {
        $did = $this->ReadAttributeString('Did');
        $id  = random_int(1, 1000000);
        $params  = ['did' => $did, 'siid' => $siid, 'aiid' => $aiid, 'in' => $in];
        $payload = [
            'did'  => $did,
            'id'   => $id,
            'data' => ['did' => $did, 'id' => $id, 'method' => 'action', 'params' => $params],
        ];
        $resp = $this->apiRequest($this->sendCommandPath(), $payload);
        if (!is_array($resp) || ($resp['code'] ?? null) === 80001) {
            return null;
        }
        return $resp['data']['result'] ?? null;
    }

    // --- Karten-JSON dekodieren (nur Metadaten: Zonen/Konturen) ---
    private function decodeMap(array $batch): ?array
    {
        $chunks = [];
        foreach ($batch as $k => $v) {
            if (preg_match('/^MAP\.(\d+)$/', (string) $k, $m)) {
                $chunks[(int) $m[1]] = (string) $v;
            }
        }
        if (count($chunks) === 0) {
            return null;
        }
        ksort($chunks);
        $raw  = implode('', $chunks);
        $info = $batch['MAP.info'] ?? '';
        if (is_string($info) && ctype_digit($info)) {
            $len = (int) $info;
            if ($len > 0 && $len < strlen($raw)) {
                $raw = substr($raw, 0, $len);
            }
        }
        $arr = json_decode(trim($raw), true);
        if (!is_array($arr) || count($arr) === 0) {
            return null;
        }
        $first = $arr[0];
        if (is_string($first)) {
            $m = json_decode($first, true);
            return is_array($m) ? $m : null;
        }
        return is_array($first) ? $first : null;
    }

    private function mapList(array $map, string $key): array
    {
        $node = $map[$key] ?? null;
        if (is_array($node) && ($node['dataType'] ?? '') === 'Map'
            && isset($node['value']) && is_array($node['value'])) {
            return $node['value'];
        }
        return [];
    }

    private function rebuildZoneProfile(array $zones): void
    {
        if (!IPS_VariableProfileExists('MOVA.Zones')) {
            IPS_CreateVariableProfile('MOVA.Zones', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('MOVA.Zones', 0, '–', '', -1);
        foreach ($zones as $z) {
            IPS_SetVariableProfileAssociation('MOVA.Zones', (int) $z['id'], (string) $z['name'], '', -1);
        }
    }

    // ------------------------------------------------------------------ //
    //  Cloud-Logik (Port aus mova_client.py)
    // ------------------------------------------------------------------ //
    private function apiUrl(): string
    {
        return 'https://' . $this->ReadPropertyString('Region') . '.iot.mova-tech.com:' . self::PORT;
    }

    private function tenant(): string
    {
        $t = $this->ReadAttributeString('TenantId');
        return $t !== '' ? $t : '000002';
    }

    private function login(): bool
    {
        $refresh = $this->ReadAttributeString('RefreshToken');
        if ($refresh !== '') {
            $body = 'platform=IOS&scope=all&grant_type=refresh_token&refresh_token=' . $refresh;
        } else {
            $pw = md5($this->ReadPropertyString('Password') . self::SALT);
            // E-Mail roh senden (wie der getestete Python-Client) — kein urlencode.
            $body = 'platform=IOS&scope=all&grant_type=password'
                . '&username=' . $this->ReadPropertyString('Email')
                . '&password=' . $pw . '&type=account';
        }

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . self::USER_AGENT,
            'Authorization: ' . self::AUTH_BASIC,
            'Tenant-Id: ' . $this->tenant(),
        ];

        $resp = $this->httpPost($this->apiUrl() . '/dreame-auth/oauth/token', $headers, $body);
        if ($resp === null) {
            return false;
        }
        $data = json_decode($resp['body'], true);
        if ($resp['code'] == 200 && is_array($data) && isset($data['access_token'])) {
            $this->WriteAttributeString('AccessToken', $data['access_token']);
            if (isset($data['refresh_token'])) {
                $this->WriteAttributeString('RefreshToken', $data['refresh_token']);
            }
            $this->WriteAttributeInteger('KeyExpire', time() + (int) ($data['expires_in'] ?? 3600) - 120);
            if (isset($data['tenant_id'])) {
                $this->WriteAttributeString('TenantId', (string) $data['tenant_id']);
            }
            if (isset($data['uid'])) {
                $this->WriteAttributeString('Uuid', (string) $data['uid']);
            }
            return true;
        }

        // Refresh-Token evtl. abgelaufen -> einmal voller Passwort-Login
        if ($refresh !== '') {
            $this->WriteAttributeString('RefreshToken', '');
            return $this->login();
        }
        $this->LogMessage('Login fehlgeschlagen (HTTP ' . $resp['code'] . '): '
            . substr((string) $resp['body'], 0, 200), KL_WARNING);
        return false;
    }

    private function ensureLogin(): bool
    {
        if ($this->ReadAttributeString('AccessToken') === '' || time() > $this->ReadAttributeInteger('KeyExpire')) {
            return $this->login();
        }
        return true;
    }

    private function apiRequest(string $path, ?array $params): ?array
    {
        $this->ensureLogin();
        $headers = [
            'Content-Type: application/json',
            'User-Agent: ' . self::USER_AGENT,
            'Authorization: ' . self::AUTH_BASIC,
            'Tenant-Id: ' . $this->tenant(),
            'Dreame-Auth: ' . $this->ReadAttributeString('AccessToken'),
        ];
        $body = $params === null ? null : json_encode($params, JSON_UNESCAPED_SLASHES);

        $resp = $this->httpPost($this->apiUrl() . '/' . $path, $headers, $body);
        if ($resp === null) {
            return null;
        }
        if ($resp['code'] == 401) {
            // Token abgelaufen -> neu einloggen und einmal wiederholen
            $this->WriteAttributeString('AccessToken', '');
            if (!$this->login()) {
                return null;
            }
            $headers[4] = 'Dreame-Auth: ' . $this->ReadAttributeString('AccessToken');
            $resp = $this->httpPost($this->apiUrl() . '/' . $path, $headers, $body);
            if ($resp === null) {
                return null;
            }
        }
        $data = json_decode($resp['body'], true);
        return is_array($data) ? $data : null;
    }

    private function ensureDevice(): bool
    {
        if ($this->ReadAttributeString('Did') !== '' && $this->ReadAttributeString('Host') !== '') {
            return true;
        }
        $resp = $this->apiRequest('dreame-user-iot/iotuserbind/device/listV2', null);
        if (!is_array($resp) || ($resp['code'] ?? null) !== 0 || !isset($resp['data']['page']['records'])) {
            return false;
        }
        $records = $resp['data']['page']['records'];
        $target = $this->ReadPropertyString('DeviceID');

        $chosen = null;
        if ($target !== '') {
            foreach ($records as $r) {
                if ((string) ($r['did'] ?? '') === $target) {
                    $chosen = $r;
                    break;
                }
            }
        }
        if ($chosen === null && count($records) > 0) {
            $chosen = $records[0];
        }
        if ($chosen === null) {
            return false;
        }

        $this->WriteAttributeString('Did', (string) ($chosen['did'] ?? ''));
        $this->WriteAttributeString('Host', (string) ($chosen['bindDomain'] ?? ''));
        $this->WriteAttributeString('Model', (string) ($chosen['model'] ?? ''));
        $this->WriteAttributeString('Uid', (string) ($chosen['masterUid'] ?? ''));
        $this->WriteAttributeString('Ver', (string) ($chosen['ver'] ?? ''));
        return true;
    }

    private function sendCommandPath(): string
    {
        $host = $this->ReadAttributeString('Host');
        $prefix = '';
        if ($host !== '') {
            $parts = explode('.', $host);
            $prefix = '-' . $parts[0];
        }
        return 'dreame-iot-com' . $prefix . '/device/sendCommand';
    }

    private function sendCommand(string $method, $params)
    {
        $did = $this->ReadAttributeString('Did');
        $id  = random_int(1, 1000000);
        $payload = [
            'did'  => $did,
            'id'   => $id,
            'data' => ['did' => $did, 'id' => $id, 'method' => $method, 'params' => $params],
        ];
        $resp = $this->apiRequest($this->sendCommandPath(), $payload);
        if (!is_array($resp)) {
            return null;
        }
        if (($resp['code'] ?? null) === 80001) {
            // Gerät offline / Timeout (Request war korrekt)
            return null;
        }
        if (!isset($resp['data']['result'])) {
            return null;
        }
        return $resp['data']['result'];
    }

    private function fetchStatus(): ?array
    {
        // Kern-Properties des LiDAX: status 2:1, battery 3:1, charging 3:2
        $props  = [['siid' => 2, 'piid' => 1], ['siid' => 3, 'piid' => 1], ['siid' => 3, 'piid' => 2]];
        $result = $this->sendCommand('get_properties', $props);
        if (!is_array($result)) {
            return null;
        }
        $out = ['online' => true];
        foreach ($result as $e) {
            if (!is_array($e) || ($e['code'] ?? -1) !== 0 || !isset($e['value'])) {
                continue;
            }
            $key = ($e['siid'] ?? 0) . ':' . ($e['piid'] ?? 0);
            if ($key === '2:1') {
                $out['state'] = $e['value'];
            } elseif ($key === '3:1') {
                $out['battery'] = $e['value'];
            } elseif ($key === '3:2') {
                $out['charging'] = $e['value'];
            }
        }
        $ver = $this->ReadAttributeString('Ver');
        if ($ver !== '') {
            $out['firmware'] = $ver;
        }
        return $out;
    }

    // ------------------------------------------------------------------ //
    //  HTTP / Profile
    // ------------------------------------------------------------------ //
    private function httpPost(string $url, array $headers, ?string $body): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body ?? '',
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resBody = curl_exec($ch);
        if ($resBody === false) {
            $this->LogMessage('HTTP-Fehler: ' . curl_error($ch), KL_WARNING);
            curl_close($ch);
            return null;
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => (string) $resBody];
    }

    private function createProfiles(): void
    {
        if (!IPS_VariableProfileExists('MOVA.Status')) {
            IPS_CreateVariableProfile('MOVA.Status', VARIABLETYPE_INTEGER);
            foreach (self::STATUS_MAP as $value => $text) {
                $color = -1;
                if ($value === 1) {
                    $color = 0x4CAF50; // mäht = grün
                } elseif ($value === 4) {
                    $color = 0xF44336; // fehler = rot
                } elseif ($value === 6) {
                    $color = 0x03A9F4; // lädt = blau
                }
                IPS_SetVariableProfileAssociation('MOVA.Status', $value, $this->Translate($text), '', $color);
            }
        }
        if (!IPS_VariableProfileExists('MOVA.Charging')) {
            IPS_CreateVariableProfile('MOVA.Charging', VARIABLETYPE_INTEGER);
            foreach (self::CHARGING_MAP as $value => $text) {
                IPS_SetVariableProfileAssociation('MOVA.Charging', $value, $this->Translate($text), '', -1);
            }
        }
        if (!IPS_VariableProfileExists('MOVA.Action')) {
            IPS_CreateVariableProfile('MOVA.Action', VARIABLETYPE_INTEGER);
            $actions = [
                0  => '–',
                1  => $this->Translate('Mow whole area'),
                2  => $this->Translate('Mow edge'),
                10 => $this->Translate('Stop'),
                11 => $this->Translate('Dock (keep task)'),
                12 => $this->Translate('Pause'),
                13 => $this->Translate('Cancel & return to dock'),
            ];
            foreach ($actions as $value => $text) {
                IPS_SetVariableProfileAssociation('MOVA.Action', $value, $text, '', -1);
            }
        }
        if (!IPS_VariableProfileExists('MOVA.Zones')) {
            IPS_CreateVariableProfile('MOVA.Zones', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('MOVA.Zones', 0, '–', '', -1);
        }
    }
}
