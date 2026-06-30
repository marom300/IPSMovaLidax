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

        $mainID   = $this->ReadPropertyInteger('MainInstanceID');
        $haveMain = ($mainID > 0 && IPS_InstanceExists($mainID));
        $st = -1; $bat = -1; $chg = -1;

        foreach ($params as $p) {
            if (!is_array($p)) {
                continue;
            }
            $siid = (int) ($p['siid'] ?? -1);
            $piid = (int) ($p['piid'] ?? -1);
            $val  = $p['value'] ?? null;

            if ($siid === 1 && $piid === 4) {            // Pose + Mäh-Fortschritt (1:4)
                $dec = $this->decodePose1_4($val);
                if ($haveMain) {
                    if ($dec['pose'] !== null) {
                        @MOVA_UpdatePosition($mainID, $dec['pose']['x'], $dec['pose']['y'], $dec['pose']['h']);
                    }
                    if ($dec['task'] !== null) {
                        @MOVA_UpdateProgress($mainID, $dec['task']['percent'], $dec['task']['current'], $dec['task']['total']);
                    }
                }
            } elseif ($siid === 2 && $piid === 1 && is_numeric($val)) { // Status
                $st = (int) $val;
            } elseif ($siid === 3 && $piid === 1 && is_numeric($val)) { // Akku
                $bat = (int) $val;
            } elseif ($siid === 3 && $piid === 2 && is_numeric($val)) { // Ladezustand
                $chg = (int) $val;
            }
        }
        if ($haveMain && ($st >= 0 || $bat >= 0 || $chg >= 0)) {
            @MOVA_UpdateLiveStatus($mainID, $st, $bat, $chg);
        }
        return '';
    }

    // ------------------------------------------------------------------ //
    //  1:4-Decoder (Port aus pose_coverage.py): Pose (Position) + Task (Fortschritt)
    //  Frame 0xCE-gerahmt. Längen: 33/44 = Pose(6)+Trace+Task(10) → Task@22;
    //  22/13 = nur Pose; 11-Alt (kein führendes 0xCE) = nur Task@0.
    // ------------------------------------------------------------------ //
    private function decodePose1_4($value): array
    {
        $out = ['pose' => null, 'task' => null];
        $d = $this->toByteList($value);
        if ($d === null) {
            return $out;
        }
        $n = count($d);
        if ($n < 8) {
            return $out;
        }
        // Alt-Format: kein führendes 0xCE, Task(10) am Anfang, 0xCE am Ende
        if ($d[0] !== 0xCE && $d[$n - 1] === 0xCE) {
            if ($n >= 11) {
                $out['task'] = $this->parseTask($d, 0);
            }
            return $out;
        }
        if ($d[0] !== 0xCE) {
            return $out;
        }
        $out['pose'] = $this->parsePose($d, 1);
        if ($n === 33 || $n === 44) {
            $out['task'] = $this->parseTask($d, 22);
        }
        return $out;
    }

    /** Pose (6 Bytes ab Offset): 20-Bit-überlappend signed x/y, Heading. ×10 = Karten-Einheiten. */
    private function parsePose(array $d, int $o): array
    {
        $b0 = $d[$o]; $b1 = $d[$o + 1]; $b2 = $d[$o + 2];
        $b3 = $d[$o + 3]; $b4 = $d[$o + 4]; $b5 = $d[$o + 5];

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

        return ['x' => $x * 10, 'y' => $y * 10, 'h' => round($b5 / 255.0 * 360.0, 1)];
    }

    /**
     * Task (10 Bytes ab Offset): [2:4] Prozent (uint16 LE, ×100), [4:7] Gesamtfläche
     * (uint24 LE, centi-m²), [7:10] gemähte Fläche (uint24 LE, centi-m²).
     */
    private function parseTask(array $d, int $o): ?array
    {
        if ($o + 9 >= count($d)) {
            return null;
        }
        $rawPercent = $d[$o + 2] | ($d[$o + 3] << 8);
        $total      = $d[$o + 4] | ($d[$o + 5] << 8) | ($d[$o + 6] << 16);
        $finish     = $d[$o + 7] | ($d[$o + 8] << 8) | ($d[$o + 9] << 16);
        return [
            'percent' => min(100.0, $rawPercent / 100.0),
            'total'   => $total / 100.0,
            'current' => $finish / 100.0,
        ];
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
