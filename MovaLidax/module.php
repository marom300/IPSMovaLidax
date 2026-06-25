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
    }
}
