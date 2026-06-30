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
        $this->RegisterPropertyBoolean('MapTransparent', true);
        $this->RegisterPropertyInteger('MapBackground', 0xFFFFFF);
        $this->RegisterPropertyInteger('LabelSize', 26);
        $this->RegisterPropertyInteger('LegendSize', 17);
        $this->RegisterPropertyInteger('MapMaxHeight', 75);
        $this->RegisterPropertyString('DashboardLayout', 'right'); // right | bottom

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
        $this->RegisterAttributeString('ZoneQueue', '[]'); // Mäh-Reihenfolge [zoneId,...]

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
        $this->RegisterVariableBoolean('ZoneStart', $this->Translate('Mow selected zone'), '~Switch', 85);
        $this->EnableAction('ZoneStart');
        // Mäh-Reihenfolge (mehrere Zonen in Wunsch-Reihenfolge)
        $this->RegisterVariableString('ZoneOrder', $this->Translate('Mow order'), '', 86);
        $this->RegisterVariableBoolean('ZoneAdd', $this->Translate('Add zone to order'), '~Switch', 87);
        $this->EnableAction('ZoneAdd');
        $this->RegisterVariableBoolean('ZoneRun', $this->Translate('Mow order (start)'), '~Switch', 88);
        $this->EnableAction('ZoneRun');
        $this->RegisterVariableBoolean('ZoneClear', $this->Translate('Clear order'), '~Switch', 89);
        $this->EnableAction('ZoneClear');
        $this->RegisterVariableString('Map', $this->Translate('Map'), '~HTMLBox', 95);
        $this->updateOrderDisplay();

        // WebHook für das HTML-Dashboard (in IPSView per URL aufrufbar)
        $this->RegisterHook('/hook/movalidax' . $this->InstanceID);

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
                // Nur auswählen — gestartet wird über den ZoneStart-Button
                $this->SetValue('Zone', (int) $Value);
                break;
            case 'ZoneStart':
                if ($Value) {
                    $this->StartSelectedZone();
                }
                $this->SetValue('ZoneStart', false);
                break;
            case 'ZoneAdd':
                if ($Value) {
                    $this->QueueAdd();
                }
                $this->SetValue('ZoneAdd', false);
                break;
            case 'ZoneRun':
                if ($Value) {
                    $this->QueueRun();
                }
                $this->SetValue('ZoneRun', false);
                break;
            case 'ZoneClear':
                $this->QueueClear();
                $this->SetValue('ZoneClear', false);
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

    /** Mäht die aktuell im Dropdown 'Mähzone' gewählte Zone (Start-Button). */
    public function StartSelectedZone(): bool
    {
        $zoneId = (int) $this->GetValue('Zone');
        if ($zoneId <= 0) {
            echo $this->Translate('Please select a zone first.');
            return false;
        }
        return $this->StartZone($zoneId);
    }

    // --- Mäh-Reihenfolge (mehrere Zonen geordnet) --- //

    /** Fügt die aktuell gewählte Zone der Reihenfolge hinzu. */
    public function QueueAdd(): void
    {
        $zid = (int) $this->GetValue('Zone');
        if ($zid <= 0) {
            echo $this->Translate('Please select a zone first.');
            return;
        }
        $q = $this->getQueue();
        $q[] = $zid;
        $this->setQueue($q);
    }

    /** Leert die Mäh-Reihenfolge. */
    public function QueueClear(): void
    {
        $this->setQueue([]);
    }

    /** Mäht alle Zonen der Reihenfolge in genau dieser Reihenfolge. */
    public function QueueRun(): bool
    {
        return $this->StartZones($this->getQueue());
    }

    /** Mäht mehrere Zonen (Opcode 102, region = Liste in Reihenfolge). */
    public function StartZones(array $ZoneIDs): bool
    {
        if (!$this->requireControl()) {
            return false;
        }
        $ids = [];
        foreach ($ZoneIDs as $z) {
            $z = (int) $z;
            if ($z > 0) {
                $ids[] = $z;
            }
        }
        if (count($ids) === 0) {
            echo $this->Translate('The mow order is empty.');
            return false;
        }
        return $this->mowTask('Zonen ' . implode(',', $ids),
            ['m' => 'a', 'p' => 0, 'o' => 102, 'd' => ['region' => $ids]]);
    }

    private function getQueue(): array
    {
        $q = json_decode($this->ReadAttributeString('ZoneQueue'), true);
        return is_array($q) ? $q : [];
    }

    private function setQueue(array $q): void
    {
        $this->WriteAttributeString('ZoneQueue', json_encode(array_values($q)));
        $this->updateOrderDisplay();
    }

    private function zoneName(int $id): string
    {
        $zones = json_decode($this->ReadAttributeString('MapZones'), true);
        if (is_array($zones)) {
            foreach ($zones as $z) {
                if ((int) ($z['id'] ?? 0) === $id) {
                    return (string) ($z['name'] ?? ('Zone ' . $id));
                }
            }
        }
        return 'Zone ' . $id;
    }

    private function updateOrderDisplay(): void
    {
        $q = $this->getQueue();
        if (count($q) === 0) {
            $this->SetValue('ZoneOrder', '–');
            return;
        }
        $parts = [];
        $i = 1;
        foreach ($q as $id) {
            $parts[] = $i . '. ' . $this->zoneName((int) $id);
            $i++;
        }
        $this->SetValue('ZoneOrder', implode('   ', $parts));
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

        // Karte als SVG rendern und in die HTMLBox-Variable schreiben
        $svg = $this->buildMapSvg($map);
        if ($svg !== '') {
            $this->SetValue('Map', $svg);
        }

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

    /** Pfad-Koordinatenlisten aus einem Karten-Abschnitt (Polygone). */
    private function extractPaths(array $map, string $key): array
    {
        $out = [];
        foreach ($this->mapList($map, $key) as $entry) {
            $data = $entry[1] ?? null;
            if (is_array($data) && isset($data['path']) && is_array($data['path'])) {
                $coords = [];
                foreach ($data['path'] as $p) {
                    if (is_array($p) && isset($p['x'], $p['y'])) {
                        $coords[] = [(int) $p['x'], (int) $p['y']];
                    }
                }
                if (count($coords) >= 2) {
                    $out[] = $coords;
                }
            }
        }
        return $out;
    }

    /** Zonen mit Name + Pfad (für Einfärbung und Beschriftung). */
    private function extractZones(array $map): array
    {
        $out = [];
        foreach ($this->mapList($map, 'mowingAreas') as $entry) {
            $id   = (int) ($entry[0] ?? 0);
            $data = $entry[1] ?? null;
            if (is_array($data) && isset($data['path']) && is_array($data['path'])) {
                $coords = [];
                foreach ($data['path'] as $p) {
                    if (is_array($p) && isset($p['x'], $p['y'])) {
                        $coords[] = [(int) $p['x'], (int) $p['y']];
                    }
                }
                if (count($coords) >= 2) {
                    $name = (string) ($data['name'] ?? '');
                    if ($name === '') {
                        $name = 'Zone ' . $id;
                    }
                    $out[] = ['name' => $name, 'path' => $coords];
                }
            }
        }
        return $out;
    }

    /** Baut aus dem Karten-JSON ein responsives SVG (Zonen farbig + beschriftet). */
    private function buildMapSvg(array $map): string
    {
        $zones     = $this->extractZones($map);
        $forbidden = $this->extractPaths($map, 'forbiddenAreas');
        $contours  = $this->extractPaths($map, 'contours');

        $allPolys = $forbidden;
        foreach ($zones as $z) {
            $allPolys[] = $z['path'];
        }
        foreach ($contours as $c) {
            $allPolys[] = $c;
        }
        if (count($allPolys) === 0) {
            return '';
        }

        $minx = $miny = PHP_INT_MAX;
        $maxx = $maxy = PHP_INT_MIN;
        foreach ($allPolys as $poly) {
            foreach ($poly as $pt) {
                $minx = min($minx, $pt[0]);
                $maxx = max($maxx, $pt[0]);
                $miny = min($miny, $pt[1]);
                $maxy = max($maxy, $pt[1]);
            }
        }
        // Konfigurierbare Darstellung
        $labelSize  = min(80, max(8, $this->ReadPropertyInteger('LabelSize')));
        $legendSize = min(60, max(8, $this->ReadPropertyInteger('LegendSize')));
        $maxH       = min(100, max(20, $this->ReadPropertyInteger('MapMaxHeight')));

        $w = max($maxx - $minx, 1);
        $h = max($maxy - $miny, 1);
        $target = 900;
        $pad = 24;
        $header = max(46, $legendSize + 30); // Platz oben für die Legende
        $scale = ($target - 2 * $pad) / max($w, $h);
        $width = $w * $scale + 2 * $pad;
        $height = $header + $h * $scale + 2 * $pad;

        $tx = function (array $pt) use ($minx, $maxy, $scale, $pad, $header) {
            return [
                $pad + ($pt[0] - $minx) * $scale,
                $header + $pad + ($maxy - $pt[1]) * $scale, // Y spiegeln, unter der Legende
            ];
        };
        $points = function (array $poly) use ($tx) {
            $s = [];
            foreach ($poly as $pt) {
                $p = $tx($pt);
                $s[] = round($p[0], 1) . ',' . round($p[1], 1);
            }
            return implode(' ', $s);
        };

        // Hintergrund + adaptive Schrift-/Konturfarbe
        if ($this->ReadPropertyBoolean('MapTransparent')) {
            $bg = 'transparent';
            $dark = true;
        } else {
            $c = $this->ReadPropertyInteger('MapBackground');
            $bg = sprintf('#%06X', $c & 0xFFFFFF);
            $lum = 0.2126 * (($c >> 16) & 0xFF) + 0.7152 * (($c >> 8) & 0xFF) + 0.0722 * ($c & 0xFF);
            $dark = $lum < 140;
        }
        $textCol    = $dark ? '#e8eee5' : '#33372c';
        $contourCol = $dark ? '#9ccc79' : '#2f6d24';

        // Farbpalette für die Zonen (unterscheidbar)
        $palette = ['#cde6c0', '#a8d8b9', '#dbe7a6', '#bcddee', '#e8d6a8', '#d3c2e0', '#f0c9b8'];

        $svg = '<svg viewBox="0 0 ' . round($width) . ' ' . round($height) . '" '
             . 'xmlns="http://www.w3.org/2000/svg" font-family="Segoe UI, Arial, sans-serif" '
             . 'style="width:100%;height:auto;max-height:' . $maxH . 'vh;background:' . $bg . ';border-radius:8px">';

        // Zonen eingefärbt
        $i = 0;
        foreach ($zones as $z) {
            $fill = $palette[$i % count($palette)];
            $i++;
            $svg .= '<polygon points="' . $points($z['path']) . '" fill="' . $fill . '" fill-opacity="0.92" '
                  . 'stroke="#4f9a3f" stroke-width="1.2" stroke-linejoin="round"/>';
        }
        // Sperrzonen
        foreach ($forbidden as $poly) {
            $svg .= '<polygon points="' . $points($poly) . '" fill="#e74c3c" fill-opacity="0.30" '
                  . 'stroke="#b53224" stroke-width="1.5" stroke-linejoin="round"/>';
        }
        // Kontur
        foreach ($contours as $poly) {
            $svg .= '<polyline points="' . $points($poly) . '" fill="none" stroke="' . $contourCol . '" '
                  . 'stroke-width="2.5" stroke-linejoin="round"/>';
        }
        // Zonen-Namen im Schwerpunkt (mit weißem Halo für Lesbarkeit)
        foreach ($zones as $z) {
            $cx = 0;
            $cy = 0;
            $n = count($z['path']);
            foreach ($z['path'] as $pt) {
                $cx += $pt[0];
                $cy += $pt[1];
            }
            $ctr = $tx([$cx / $n, $cy / $n]);
            $label = htmlspecialchars($z['name'], ENT_QUOTES);
            $halo = max(2, (int) round($labelSize * 0.2));
            $svg .= '<text x="' . round($ctr[0], 1) . '" y="' . round($ctr[1] + $labelSize * 0.35, 1) . '" '
                  . 'font-size="' . $labelSize . '" font-weight="700" fill="#243018" text-anchor="middle" '
                  . 'paint-order="stroke" stroke="#ffffff" stroke-width="' . $halo . '" stroke-opacity="0.8">'
                  . $label . '</text>';
        }

        // Legende (oben)
        $ls  = $legendSize;
        $lyT = $header - 14;                                // Text-Grundlinie
        $lyS = $lyT - $ls + 2;                              // Swatch oben
        $gx  = 16 + $ls + 6 + (int) round(strlen('Sperrzone') * $ls * 0.6) + 26;
        $svg .= '<g>'
              . '<rect x="16" y="' . $lyS . '" width="' . $ls . '" height="' . $ls . '" rx="3" fill="#e74c3c" fill-opacity="0.3" stroke="#b53224"/>'
              . '<text x="' . (16 + $ls + 6) . '" y="' . $lyT . '" font-size="' . $ls . '" fill="' . $textCol . '">Sperrzone</text>'
              . '<rect x="' . $gx . '" y="' . ($lyT - (int) round($ls * 0.28)) . '" width="' . (int) round($ls * 1.2) . '" height="' . max(3, (int) round($ls * 0.22)) . '" rx="2" fill="' . $contourCol . '"/>'
              . '<text x="' . ($gx + (int) round($ls * 1.2) + 8) . '" y="' . $lyT . '" font-size="' . $ls . '" fill="' . $textCol . '">Grenze</text>'
              . '</g>';

        $svg .= '</svg>';
        return $svg;
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
    //  WebHook / HTML-Dashboard
    // ------------------------------------------------------------------ //
    private function RegisterHook(string $Hook): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'); // WebHook Control
        if (count($ids) === 0) {
            return;
        }
        $hookID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookID, 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $h) {
            if (($h['Hook'] ?? '') === $Hook && (int) ($h['TargetID'] ?? 0) === $this->InstanceID) {
                return; // schon vorhanden
            }
        }
        $hooks = array_values(array_filter($hooks, fn($h) => ($h['Hook'] ?? '') !== $Hook));
        $hooks[] = ['Hook' => $Hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookID);
    }

    /** Wird von der WebHook-Control bei Aufruf der Hook-URL gerufen. */
    public function ProcessHookData()
    {
        $action = $_GET['action'] ?? ($_POST['action'] ?? '');

        if ($action === 'status') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->buildStatusData());
            return;
        }
        if ($action !== '') {
            header('Content-Type: application/json; charset=utf-8');
            $this->handleHookAction((string) $action, array_merge($_GET, $_POST));
            echo json_encode(['ok' => true]);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->buildDashboardHtml();
    }

    private function handleHookAction(string $action, array $p): void
    {
        switch ($action) {
            case 'all':    $this->StartAll();    break;
            case 'edge':   $this->StartEdge();   break;
            case 'pause':  $this->MowerPause();  break;
            case 'stop':   $this->MowerStop();   break;
            case 'dock':   $this->MowerDock();   break;
            case 'home':   $this->ReturnHome();  break;
            case 'zone':   $this->StartZone((int) ($p['id'] ?? 0)); break;
            case 'queueadd':
                $zid = (int) ($p['id'] ?? 0);
                if ($zid > 0) {
                    $q = $this->getQueue();
                    $q[] = $zid;
                    $this->setQueue($q);
                }
                break;
            case 'queuerun':   $this->QueueRun();   break;
            case 'queueclear': $this->QueueClear(); break;
            case 'poll':       $this->Poll();       break;
            case 'loadmap':    $this->LoadMap();    break;
        }
    }

    private function buildStatusData(): array
    {
        $lu = (int) $this->GetValue('LastUpdate');
        $orderItems = [];
        $i = 1;
        foreach ($this->getQueue() as $id) {
            $orderItems[] = $i . '. ' . $this->zoneName((int) $id);
            $i++;
        }
        return [
            'online'   => (bool) $this->GetValue('Online'),
            'battery'  => (int) $this->GetValue('Battery'),
            'state'    => GetValueFormatted($this->GetIDForIdent('State')),
            'charging' => GetValueFormatted($this->GetIDForIdent('Charging')),
            'firmware' => (string) $this->GetValue('Firmware'),
            'updated'  => $lu > 0 ? date('d.m.Y H:i:s', $lu) : '–',
            'order'      => (string) $this->GetValue('ZoneOrder'),
            'orderItems' => $orderItems,
            'map'        => (string) $this->GetValue('Map'),
            'zones'    => json_decode($this->ReadAttributeString('MapZones'), true) ?: [],
            'allow'    => (bool) $this->ReadPropertyBoolean('AllowControl'),
            'layout'   => $this->ReadPropertyString('DashboardLayout'),
        ];
    }

    private function buildDashboardHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MOVA LiDAX</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:'Segoe UI',Roboto,Arial,sans-serif;background:#1c1f23;color:#e7ebe6}
.wrap{max-width:1100px;margin:0 auto;padding:16px}
.top{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.top h1{font-size:20px;font-weight:600;margin:0}
.dot{width:12px;height:12px;border-radius:50%;display:inline-block;margin-right:6px;background:#888;vertical-align:middle}
.dot.on{background:#4caf50}.dot.off{background:#e74c3c}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}
.card{background:#272b30;border-radius:12px;padding:12px 14px}
.card .lbl{font-size:12px;color:#9aa3a0;text-transform:uppercase;letter-spacing:.04em}
.card .val{font-size:22px;font-weight:600;margin-top:4px}
.bat{height:8px;border-radius:4px;background:#3a3f45;margin-top:8px;overflow:hidden}
.bat>i{display:block;height:100%;background:#4caf50;transition:width .4s}
.main{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start}
.main>.left{flex:1 1 360px;min-width:280px}
.main>.right{flex:0 0 340px}
.main.bottom{flex-direction:column}
.main.bottom>.right{width:100%;flex:1 1 auto}
.main:not(.bottom)>.right .btns,.main:not(.bottom)>.right .row{flex-direction:column;align-items:stretch}
.main:not(.bottom)>.right button,.main:not(.bottom)>.right select{width:100%}
.order div{padding:3px 2px;border-bottom:1px solid #2c3338}
.order div:last-child{border-bottom:none}
.map{background:#23272b;border-radius:12px;padding:10px;margin-bottom:14px;text-align:center}
.sec{background:#272b30;border-radius:12px;padding:14px;margin-bottom:14px}
.sec h2{font-size:14px;font-weight:600;margin:0 0 10px;color:#c8d0cb}
.btns,.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
button,select{font:inherit;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
button{background:#3a4047;color:#e7ebe6}button:hover{background:#454c54}
button.go{background:#3f7d34}button.go:hover{background:#4a9140}
button.stop{background:#9a3b32}button.stop:hover{background:#b5453a}
select{background:#2f343a;color:#e7ebe6}
.order{margin-top:10px;font-size:14px;color:#bcd0b0;background:#23282c;border-radius:8px;padding:8px 10px;min-height:20px}
.warn{background:#5a3a14;color:#ffd9a0;border-radius:10px;padding:8px 12px;margin-bottom:12px;font-size:14px;display:none}
.upd{color:#8a938e;font-size:13px}
</style></head>
<body><div class="wrap">
 <div class="top"><h1>MOVA LiDAX</h1><div><span id="dot" class="dot"></span><span id="onTxt">…</span></div></div>
 <div id="warn" class="warn">Steuerung gesperrt – in Symcon „Steuerung freischalten" aktivieren.</div>
 <div class="cards">
  <div class="card"><div class="lbl">Akku</div><div class="val"><span id="bat">–</span>%</div><div class="bat"><i id="batBar" style="width:0%"></i></div></div>
  <div class="card"><div class="lbl">Status</div><div class="val" id="state">–</div></div>
  <div class="card"><div class="lbl">Laden</div><div class="val" id="charge">–</div></div>
  <div class="card"><div class="lbl">Firmware</div><div class="val" style="font-size:16px" id="fw">–</div></div>
 </div>
 <div class="main" id="main">
  <div class="left"><div class="map" id="map"></div></div>
  <div class="right">
   <div class="sec"><h2>Steuerung</h2>
    <div class="btns">
     <button class="go" onclick="cmd('all')">Gesamtes Gebiet</button>
     <button class="go" onclick="cmd('edge')">Begrenzung</button>
     <button onclick="cmd('pause')">Pause</button>
     <button class="stop" onclick="cmd('stop')">Stop</button>
     <button onclick="cmd('dock')">Andocken</button>
     <button class="stop" onclick="cmd('home')">Stopp &amp; Heim</button>
    </div>
   </div>
   <div class="sec"><h2>Zonen mähen</h2>
    <div class="row">
     <select id="zoneSel"></select>
     <button class="go" onclick="cmd('zone&id='+zv())">Diese Zone</button>
     <button onclick="cmd('queueadd&id='+zv())">+ zur Reihenfolge</button>
     <button class="go" onclick="cmd('queuerun')">Reihenfolge mähen</button>
     <button onclick="cmd('queueclear')">Leeren</button>
    </div>
    <div class="order" id="order">–</div>
   </div>
   <div class="sec"><div class="row">
     <button onclick="cmd('poll')">Status aktualisieren</button>
     <button onclick="cmd('loadmap')">Karte neu laden</button>
     <span id="upd" class="upd"></span>
   </div></div>
  </div>
 </div>
</div>
<script>
function zv(){return document.getElementById('zoneSel').value}
async function cmd(a){try{await fetch('?action='+a)}catch(e){} setTimeout(refresh,600)}
let zonesKey='';
async function refresh(){
 let d; try{ d=await (await fetch('?action=status')).json() }catch(e){return}
 document.getElementById('bat').textContent=d.battery;
 document.getElementById('batBar').style.width=Math.max(0,Math.min(100,d.battery))+'%';
 document.getElementById('state').textContent=d.state||'–';
 document.getElementById('charge').textContent=d.charging||'–';
 document.getElementById('fw').textContent=d.firmware||'–';
 var ord=document.getElementById('order');
 if(d.orderItems&&d.orderItems.length){ord.innerHTML=d.orderItems.map(function(t){return '<div>'+t+'</div>'}).join('')}else{ord.textContent='–'}
 document.getElementById('upd').textContent='aktualisiert: '+d.updated;
 var dot=document.getElementById('dot'); dot.className='dot '+(d.online?'on':'off');
 document.getElementById('onTxt').textContent=d.online?'online':'offline';
 document.getElementById('warn').style.display=d.allow?'none':'block';
 document.getElementById('map').innerHTML=d.map||'';
 document.getElementById('main').className='main'+(d.layout==='bottom'?' bottom':'');
 var k=JSON.stringify(d.zones||[]);
 if(k!==zonesKey){zonesKey=k;var s=document.getElementById('zoneSel');var cur=s.value;s.innerHTML='';
  (d.zones||[]).forEach(function(z){var o=document.createElement('option');o.value=z.id;o.textContent=z.name;s.appendChild(o)});
  if(cur)s.value=cur;}
}
refresh(); setInterval(refresh,5000);
</script></body></html>
HTML;
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
