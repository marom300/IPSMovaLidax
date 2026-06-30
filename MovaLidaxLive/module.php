<?php

declare(strict_types=1);

/**
 * MOVA LiDAX Live — Live-Position über MQTT (Stufe 4).
 *
 * Kindmodul unter Symcons MQTT Client (Splitter auf Client Socket). Empfängt die
 * properties_changed-Pushes des MOVAhome-Brokers, dekodiert die Pose (1:4) und
 * meldet die Position an das REST-Hauptmodul (MOVA_UpdatePosition → roter Punkt).
 *
 * Das Passwort am MQTT-Broker ist der Access-Token und läuft ~2 h ab; ein Timer
 * holt regelmäßig einen frischen Token vom Hauptmodul und schreibt ihn in den
 * Parent-MQTT-Client (Reconnect).
 *
 * Parent (MQTT Client + Client Socket) wird vom Benutzer angelegt/konfiguriert:
 *   Client Socket: Host/Port aus bindDomain, SSL AN, Überprüfe Peer/Host AUS.
 *   MQTT Client:   ClientID ≤23 Zeichen (p_<uid>_…), Benutzername/Passwort/Topic.
 */
class MovaLidaxLive extends IPSModule
{
    // Datenfluss-GUID MQTT (Kind ⇄ MQTT Client/Server)
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('MainInstanceID', 0);
        $this->RegisterPropertyInteger('TokenRefreshMin', 100);
        $this->RegisterPropertyBoolean('ManageParent', true);
        $this->RegisterTimer('TokenRefresh', 0, 'MOVAL_RefreshToken($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $mainID = $this->ReadPropertyInteger('MainInstanceID');
        if ($mainID <= 0) {
            $this->SetStatus(104); // Hauptmodul nicht gewählt
            $this->SetTimerInterval('TokenRefresh', 0);
            return;
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->setupConnection();
        }

        $min = max(10, $this->ReadPropertyInteger('TokenRefreshMin'));
        $this->SetTimerInterval('TokenRefresh', $min * 60 * 1000);
        $this->SetStatus(102);
    }

    /** Holt MQTT-Auth vom Hauptmodul, setzt Empfangsfilter + frischen Token. */
    private function setupConnection(): void
    {
        $a = $this->getAuth();
        if ($a === null) {
            $this->LogMessage('Live: MQTT-Auth vom Hauptmodul nicht erhalten (eingeloggt?).', KL_WARNING);
            return;
        }
        // Nur Nachrichten unseres Status-Topics durchlassen
        $this->SetReceiveDataFilter('.*' . preg_quote('/status/' . $a['did'] . '/') . '.*');
        $this->pushToken($a);
    }

    /** MQTT-Verbindungsdaten (inkl. frischem Token) vom REST-Hauptmodul. */
    private function getAuth(): ?array
    {
        $mainID = $this->ReadPropertyInteger('MainInstanceID');
        if ($mainID <= 0 || !IPS_InstanceExists($mainID)) {
            return null;
        }
        $json = @MOVA_GetMqttAuth($mainID);
        $a = json_decode((string) $json, true);
        return (is_array($a) && ($a['ok'] ?? false)) ? $a : null;
    }

    /** Schreibt Token/Benutzer in den Parent-MQTT-Client und reconnectet bei Änderung. */
    private function pushToken(array $a): void
    {
        if (!$this->ReadPropertyBoolean('ManageParent')) {
            return;
        }
        $parent = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($parent <= 0) {
            $this->LogMessage('Live: kein MQTT-Client als Parent verbunden.', KL_WARNING);
            return;
        }
        // Nur den Token (Passwort) rotieren — Benutzername/ClientID/SSL stellt der
        // Benutzer einmalig am MQTT-Client/Client-Socket ein.
        if ((string) @IPS_GetProperty($parent, 'Password') !== (string) $a['password']) {
            IPS_SetProperty($parent, 'Password', (string) $a['password']);
            IPS_ApplyChanges($parent);
        }
    }

    /** Öffentlich (MOVAL_RefreshToken): Timer — frischen Token in den Parent schreiben. */
    public function RefreshToken(): void
    {
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $a = $this->getAuth();
        if ($a !== null) {
            $this->pushToken($a);
        }
    }

    /** Eingehende MQTT-Nachrichten vom Parent (gefiltert auf unser Topic). */
    public function ReceiveData($JSONString)
    {
        $data = json_decode((string) $JSONString, true);
        if (!is_array($data) || !isset($data['Payload'])) {
            return '';
        }
        $payload = (string) $data['Payload'];
        $msg = json_decode($payload, true);
        if (!is_array($msg)) {
            $msg = json_decode(utf8_decode($payload), true);
        }
        if (!is_array($msg)) {
            return '';
        }

        $d = $msg['data'] ?? null;
        if (!is_array($d)) {
            return '';
        }
        $params = null;
        if (($d['method'] ?? '') === 'properties_changed') {
            $params = $d['params'] ?? null;
        } elseif (isset($d['params'])) {
            $params = $d['params'];
        } elseif (isset($d['siid'])) {
            $params = [$d];
        }
        if (!is_array($params)) {
            return '';
        }

        foreach ($params as $p) {
            if (!is_array($p)) {
                continue;
            }
            $siid = (int) ($p['siid'] ?? -1);
            $piid = (int) ($p['piid'] ?? -1);
            if ($siid === 1 && $piid === 4) { // Pose / Live-Position
                $pose = $this->decodeRobotPose($p['value'] ?? null);
                if ($pose !== null) {
                    $mainID = $this->ReadPropertyInteger('MainInstanceID');
                    if ($mainID > 0 && IPS_InstanceExists($mainID)) {
                        @MOVA_UpdatePosition($mainID, $pose['x'], $pose['y'], $pose['h']);
                    }
                }
            }
        }
        return '';
    }

    // ------------------------------------------------------------------ //
    //  Pose-Decoder (Port aus pose_decode.py) — 0xCE-Frame, 20-Bit, ×10
    // ------------------------------------------------------------------ //
    private function decodeRobotPose($value): ?array
    {
        $data = $this->toByteList($value);
        if ($data === null || count($data) < 8 || $data[0] !== 0xCE) {
            return null;
        }
        [$b0, $b1, $b2, $b3, $b4, $b5] = [$data[1], $data[2], $data[3], $data[4], $data[5], $data[6]];

        $rawX = (($b2 << 28) | ($b1 << 20) | ($b0 << 12)) & 0xFFFFFFFF;
        if ($rawX & 0x80000000) {
            $rawX -= 0x100000000;
        }
        $x = $rawX >> 12;

        $rawY = (($b4 << 24) | ($b3 << 16) | ($b2 << 8)) & 0xFFFFFFFF;
        if ($rawY & 0x80000000) {
            $rawY -= 0x100000000;
        }
        $y = $rawY >> 12;

        $heading = round($b5 / 255.0 * 360.0, 1);
        return ['x' => $x * 10, 'y' => $y * 10, 'h' => $heading];
    }

    /** Wertformen (Byte-Liste oder Base64-String) auf eine Liste von Bytes bringen. */
    private function toByteList($value): ?array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $b) {
                $out[] = ((int) $b) & 0xFF;
            }
            return $out;
        }
        if (is_string($value) && $value !== '') {
            $dec = base64_decode($value, true);
            if ($dec !== false && $dec !== '') {
                $out = [];
                $len = strlen($dec);
                for ($i = 0; $i < $len; $i++) {
                    $out[] = ord($dec[$i]);
                }
                return $out;
            }
        }
        return null;
    }
}
