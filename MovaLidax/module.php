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
        $this->RegisterPropertyBoolean('ShowMowPath', true); // gemähte Spur (orange) in der Karte
        $this->RegisterPropertyInteger('MapBackground', 0xFFFFFF);
        $this->RegisterPropertyInteger('LabelSize', 26);
        $this->RegisterPropertyInteger('LegendSize', 17);
        $this->RegisterPropertyInteger('MapMaxHeight', 75);
        $this->RegisterPropertyString('DashboardLayout', 'split'); // split | right | bottom
        // Eigene Zonennamen (überschreiben die Cloud-Defaults) — [{ZoneID,Name}, ...]
        $this->RegisterPropertyString('ZoneNames', '[]');
        // Anordnung/Sichtbarkeit der Steuer-Buttons im Dashboard (Reihenfolge = Listenreihenfolge)
        $this->RegisterPropertyString('ControlButtons', json_encode([
            ['Key' => 'all',   'Label' => '', 'Show' => true],
            ['Key' => 'edge',  'Label' => '', 'Show' => true],
            ['Key' => 'pause', 'Label' => '', 'Show' => true],
            ['Key' => 'stop',  'Label' => '', 'Show' => true],
            ['Key' => 'dock',  'Label' => '', 'Show' => true],
            ['Key' => 'home',  'Label' => '', 'Show' => true],
        ]));
        // Anordnung/Sichtbarkeit der Zonen-Buttons im Dashboard
        $this->RegisterPropertyString('ZoneButtons', json_encode([
            ['Key' => 'zonestart',  'Label' => '', 'Show' => true],
            ['Key' => 'queueadd',   'Label' => '', 'Show' => true],
            ['Key' => 'queuerun',   'Label' => '', 'Show' => true],
            ['Key' => 'queueclear', 'Label' => '', 'Show' => true],
        ]));

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
        $this->RegisterAttributeString('MapRaw', '');      // dekodierte Karte (JSON) für Live-Neurender
        $this->RegisterAttributeString('MapPath', '[]');   // gemähte Spur (M_PATH-Segmente)
        $this->RegisterAttributeString('ZoneQueue', '[]'); // Mäh-Reihenfolge [zoneId,...]
        $this->RegisterAttributeString('ConnHash', '');    // Hash der Verbindungsdaten (Cache-Invalidierung)
        $this->RegisterAttributeString('WorkLogData', '[]'); // Arbeitsprotokoll (Mäh-Sitzungen)

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
        $this->RegisterVariableString('LastCommand', $this->Translate('Last command'), '', 72);
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
        // Live-Position (wird vom MQTT-Kindmodul über UpdatePosition gefüttert)
        $this->RegisterVariableInteger('RobotX', $this->Translate('Robot X'), '', 96);
        $this->RegisterVariableInteger('RobotY', $this->Translate('Robot Y'), '', 97);
        $this->RegisterVariableInteger('RobotHeading', $this->Translate('Heading'), '', 98);
        $this->RegisterVariableInteger('PositionUpdate', $this->Translate('Position update'), '~UnixTimestamp', 99);
        // Mäh-Fortschritt (aus der Pose-Property 1:4, Task-Block)
        $this->RegisterVariableInteger('Progress', $this->Translate('Progress'), '~Progress', 100);
        $this->RegisterVariableFloat('AreaDone', $this->Translate('Area mowed'), 'MOVA.Area', 101);
        $this->RegisterVariableFloat('AreaTotal', $this->Translate('Area total'), 'MOVA.Area', 102);
        $this->RegisterVariableInteger('RemainingTime', $this->Translate('Remaining (est.)'), 'MOVA.Minutes', 103);
        // Arbeitsprotokoll (frei platzierbare HTML-Box, z. B. in IPSView)
        $this->RegisterVariableString('WorkLog', $this->Translate('Work log'), '~HTMLBox', 110);
        $this->renderWorkLog();
        $this->updateOrderDisplay();

        // WebHook für das HTML-Dashboard (in IPSView per URL aufrufbar)
        $this->RegisterHook('/hook/movalidax' . $this->InstanceID);

        // Geräte-/Token-Cache nur verwerfen, wenn sich die Verbindungsdaten geändert haben.
        // (Sonst würde ein programmatischer ApplyChanges — z. B. beim Zonen-Namen-Sync —
        //  unnötig Gerät + Token neu auflösen.)
        $connHash = md5(
            $this->ReadPropertyString('Email') . '|' . $this->ReadPropertyString('Region')
            . '|' . $this->ReadPropertyString('AccountType') . '|' . $this->ReadPropertyString('DeviceID')
        );
        if ($connHash !== $this->ReadAttributeString('ConnHash')) {
            $this->WriteAttributeString('Did', '');
            $this->WriteAttributeString('Host', '');
            $this->WriteAttributeString('AccessToken', '');
            $this->WriteAttributeString('RefreshToken', '');
            $this->WriteAttributeInteger('KeyExpire', 0);
            $this->WriteAttributeString('ConnHash', $connHash);
        }

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
        $discovered = [];
        foreach ($this->mapList($map, 'mowingAreas') as $entry) {
            $zid  = (int) ($entry[0] ?? 0);
            $name = (is_array($entry[1] ?? null)) ? (string) ($entry[1]['name'] ?? '') : '';
            if ($name === '') {
                $name = $this->Translate('Zone') . ' ' . $zid;
            }
            $discovered[] = $zid;
            $zones[] = ['id' => $zid, 'name' => $this->displayZoneName($zid, $name)];
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

        // Dekodierte Karte für spätere Live-Neurender (roter Punkt) zwischenspeichern
        $this->WriteAttributeString('MapRaw', json_encode($map));
        // Gemähte Spur (M_PATH) aus denselben Batch-Daten mitnehmen
        $this->WriteAttributeString('MapPath', json_encode($this->parseMowPath($data)));

        // Karte als SVG rendern und in die HTMLBox-Variable schreiben
        $svg = $this->buildMapSvg($map);
        if ($svg !== '') {
            $this->SetValue('Map', $svg);
        }

        // Neu gefundene Zonen in die editierbare Namensliste übernehmen (vorhandene Namen bleiben).
        $this->syncZoneNameList($discovered);

        $msg = sprintf($this->Translate('Map loaded: %d zones, %d contours.'), count($zones), count($contours));
        $this->LogMessage($msg, KL_NOTIFY);
        echo $msg;
        return true;
    }

    /** Liefert den eigenen Zonennamen (falls gesetzt), sonst den Cloud-/Fallback-Namen. */
    private function zoneNameOverride(int $id): string
    {
        $list = json_decode($this->ReadPropertyString('ZoneNames'), true);
        if (is_array($list)) {
            foreach ($list as $row) {
                if ((int) ($row['ZoneID'] ?? 0) === $id) {
                    $n = trim((string) ($row['Name'] ?? ''));
                    if ($n !== '') {
                        return $n;
                    }
                }
            }
        }
        return '';
    }

    private function displayZoneName(int $id, string $fallback): string
    {
        $o = $this->zoneNameOverride($id);
        return $o !== '' ? $o : $fallback;
    }

    /**
     * Ergänzt neu entdeckte Zonen-IDs in der Property 'ZoneNames', damit sie in der
     * Konfiguration zum Umbenennen erscheinen. Bereits vergebene Namen bleiben erhalten.
     */
    private function syncZoneNameList(array $discoveredIds): void
    {
        $list = json_decode($this->ReadPropertyString('ZoneNames'), true);
        if (!is_array($list)) {
            $list = [];
        }
        $existing = [];
        foreach ($list as $row) {
            $id = (int) ($row['ZoneID'] ?? 0);
            if ($id > 0) {
                $existing[$id] = (string) ($row['Name'] ?? '');
            }
        }
        $changed = false;
        foreach ($discoveredIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !array_key_exists($id, $existing)) {
                $existing[$id] = '';
                $changed = true;
            }
        }
        if (!$changed) {
            return;
        }
        ksort($existing);
        $new = [];
        foreach ($existing as $id => $name) {
            $new[] = ['ZoneID' => $id, 'Name' => $name];
        }
        IPS_SetProperty($this->InstanceID, 'ZoneNames', json_encode(array_values($new)));
        IPS_ApplyChanges($this->InstanceID);
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
            $this->setLastCommand($label, false, $this->Translate('Not connected.'));
            return false;
        }
        $res = $this->sendAction(2, 50, [$payload]);
        $ok  = $res !== null;
        $this->LogMessage('Maehbefehl "' . $label . '": ' . ($ok ? 'OK' : 'fehlgeschlagen'),
            $ok ? KL_NOTIFY : KL_WARNING);
        $this->setLastCommand($label, $ok);
        return $ok;
    }

    private function simpleAction(int $siid, int $aiid, string $label)
    {
        if (!$this->ensureLogin() || !$this->ensureDevice()) {
            echo $this->Translate('Not connected.');
            $this->setLastCommand($label, false, $this->Translate('Not connected.'));
            return false;
        }
        $res = $this->sendAction($siid, $aiid, []);
        $ok  = $res !== null;
        $this->LogMessage('Befehl "' . $label . '": ' . ($ok ? 'OK' : 'fehlgeschlagen'),
            $ok ? KL_NOTIFY : KL_WARNING);
        $this->setLastCommand($label, $ok);
        return $ok;
    }

    /** Schreibt die Sofort-Rückmeldung des letzten Steuerbefehls (Cloud-Annahme). */
    private function setLastCommand(string $label, bool $ok, string $note = ''): void
    {
        $time = date('H:i:s');
        if ($ok) {
            $msg = '✓ ' . $label . ' – gesendet (' . $time . ')';
        } else {
            $msg = '✗ ' . $label . ' – '
                 . ($note !== '' ? $note : $this->Translate('failed')) . ' (' . $time . ')';
        }
        $this->SetValue('LastCommand', $msg);
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

    /**
     * M_PATH.* (gemähte Spur) aus Batch-Daten parsen → Segmente [[[x,y],…], …].
     * Chunks zusammensetzen, [x,y]-Paare extrahieren, Sentinel [32767,-32768] = Segmentbruch,
     * Koordinaten ×10 (Karten-Einheiten). Bei sehr vielen Punkten wird ausgedünnt.
     */
    private function parseMowPath(array $batch): array
    {
        $chunks = [];
        foreach ($batch as $k => $v) {
            if (preg_match('/^M_PATH\.(\d+)$/', (string) $k, $m)) {
                $chunks[(int) $m[1]] = (string) $v;
            }
        }
        if (count($chunks) === 0) {
            return [];
        }
        ksort($chunks);
        $raw  = implode('', $chunks);
        $info = $batch['M_PATH.info'] ?? '';
        if (is_string($info) && ctype_digit($info)) {
            $split = (int) $info;
            if ($split > 0 && $split < strlen($raw)) {
                $raw = substr($raw, $split);
            }
        }
        if (!preg_match_all('/\[(-?\d+),(-?\d+)\]/', $raw, $mm, PREG_SET_ORDER)) {
            return [];
        }
        $segments = [];
        $cur = [];
        foreach ($mm as $p) {
            $x = (int) $p[1];
            $y = (int) $p[2];
            if ($x === 32767 && $y === -32768) { // Segmentbruch
                if (count($cur) >= 2) {
                    $segments[] = $cur;
                }
                $cur = [];
            } else {
                $cur[] = [$x * 10, $y * 10];
            }
        }
        if (count($cur) >= 2) {
            $segments[] = $cur;
        }
        return $this->downsamplePath($segments, 4000);
    }

    /** Dünnt die Spur aus, falls sie mehr als $maxPts Punkte hat (Segment-Enden bleiben). */
    private function downsamplePath(array $segments, int $maxPts): array
    {
        $total = 0;
        foreach ($segments as $s) {
            $total += count($s);
        }
        if ($total <= $maxPts || $total === 0) {
            return $segments;
        }
        $step = (int) ceil($total / $maxPts);
        $out  = [];
        foreach ($segments as $s) {
            $n  = count($s);
            $ds = [];
            for ($i = 0; $i < $n; $i += $step) {
                $ds[] = $s[$i];
            }
            if (($n - 1) % $step !== 0) {
                $ds[] = $s[$n - 1];
            }
            if (count($ds) >= 2) {
                $out[] = $ds;
            }
        }
        return $out;
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
                    $name = $this->displayZoneName($id, $name);
                    $out[] = ['name' => $name, 'path' => $coords];
                }
            }
        }
        return $out;
    }

    /**
     * Baut aus dem Karten-JSON ein responsives SVG (Zonen farbig + beschriftet).
     * $robot (optional): ['x'=>int,'y'=>int,'h'=>float] in Karten-Einheiten zeichnet
     * die Live-Position als roten Punkt mit Richtungsstrich.
     */
    private function buildMapSvg(array $map, ?array $robot = null): string
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

        // Breiteste Beschriftung grob schätzen (0,62 em/Zeichen) → Rand danach bemessen,
        // damit ausgelagerte Labels bei JEDER Karte/Namenlänge komplett hineinpassen.
        $charW    = $labelSize * 0.62;
        $maxTextW = 0.0;
        foreach ($zones as $z) {
            $tw = mb_strlen($z['name']) * $charW;
            if ($tw > $maxTextW) {
                $maxTextW = $tw;
            }
        }
        $marginX = (int) round(max($labelSize * 2.2, $maxTextW + $labelSize));
        $marginY = (int) round($labelSize * 2.4);

        $scale  = ($target - 2 * $pad) / max($w, $h);
        $offX   = $pad + $marginX;
        $offTop = $header + $marginY;
        $width  = $w * $scale + 2 * $offX;
        $height = $offTop + $h * $scale + $pad + $marginY;

        $tx = function (array $pt) use ($minx, $maxy, $scale, $offX, $offTop) {
            return [
                $offX + ($pt[0] - $minx) * $scale,
                $offTop + ($maxy - $pt[1]) * $scale, // Y spiegeln, unter der Legende
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
        // Gemähte Spur (orange) — über dem Rasen, unter den Beschriftungen
        if ($this->ReadPropertyBoolean('ShowMowPath')) {
            $mowPath = json_decode($this->ReadAttributeString('MapPath'), true);
            if (is_array($mowPath)) {
                foreach ($mowPath as $seg) {
                    if (!is_array($seg) || count($seg) < 2) {
                        continue;
                    }
                    $sp = [];
                    foreach ($seg as $pt) {
                        if (is_array($pt) && isset($pt[0], $pt[1])) {
                            $p = $tx([(int) $pt[0], (int) $pt[1]]);
                            $sp[] = round($p[0], 1) . ',' . round($p[1], 1);
                        }
                    }
                    if (count($sp) >= 2) {
                        $svg .= '<polyline points="' . implode(' ', $sp) . '" fill="none" '
                              . 'stroke="#ef8b2c" stroke-width="1.4" stroke-opacity="0.55" '
                              . 'stroke-linejoin="round" stroke-linecap="round"/>';
                    }
                }
            }
        }
        // Zonen-Namen als Callouts AUSSERHALB der Zonen: Punkt am Schwerpunkt + Linie zur
        // nach außen ausgelagerten Beschriftung. So bleiben Zonen und Sperrflächen sichtbar.
        $cCenter = $tx([($minx + $maxx) / 2, ($miny + $maxy) / 2]);
        $halo = max(2, (int) round($labelSize * 0.22));
        foreach ($zones as $z) {
            $cx = 0;
            $cy = 0;
            $n = count($z['path']);
            foreach ($z['path'] as $pt) {
                $cx += $pt[0];
                $cy += $pt[1];
            }
            $dot = $tx([$cx / $n, $cy / $n]);

            // Richtung vom Kartenmittelpunkt nach außen bestimmen
            $vx = $dot[0] - $cCenter[0];
            $vy = $dot[1] - $cCenter[1];
            $len = sqrt($vx * $vx + $vy * $vy);
            if ($len < 1) {
                $vx = 0;
                $vy = -1;
                $len = 1;
            }
            $nx = $vx / $len;
            $ny = $vy / $len;

            $anchor = $nx < -0.3 ? 'end' : ($nx > 0.3 ? 'start' : 'middle');
            $tw     = mb_strlen($z['name']) * $charW;

            // Wie weit reicht die Zone vom Schwerpunkt in Richtung (nx,ny)? Label knapp
            // dahinter setzen → große Zonen (z. B. Hauptzone) schieben ihr Label weiter raus.
            $reach = 0.0;
            foreach ($z['path'] as $pt) {
                $sp   = $tx($pt);
                $proj = ($sp[0] - $dot[0]) * $nx + ($sp[1] - $dot[1]) * $ny;
                if ($proj > $reach) {
                    $reach = $proj;
                }
            }
            $off = $reach + $labelSize * 1.0 + 14;
            $lx  = $dot[0] + $nx * $off;
            $ly  = $dot[1] + $ny * $off;

            // Kompletten Text (je nach Anker) im Rahmen halten – nichts abschneiden
            $edge = 6;
            if ($anchor === 'start') {           // Text läuft nach rechts
                $lx = max($edge, min($width - $edge - $tw, $lx));
            } elseif ($anchor === 'end') {       // Text läuft nach links
                $lx = min($width - $edge, max($edge + $tw, $lx));
            } else {                             // zentriert
                $lx = max($edge + $tw / 2, min($width - $edge - $tw / 2, $lx));
            }
            // untere Grenze = unterhalb der Legende (oben), damit Labels nicht hineinrutschen
            $ly = max($header + $labelSize * 0.9, min($height - $labelSize * 0.5, $ly));

            $label = htmlspecialchars($z['name'], ENT_QUOTES);

            $svg .= '<line x1="' . round($dot[0], 1) . '" y1="' . round($dot[1], 1) . '" '
                  . 'x2="' . round($lx, 1) . '" y2="' . round($ly, 1) . '" '
                  . 'stroke="' . $contourCol . '" stroke-width="1.4" stroke-opacity="0.75"/>';
            $svg .= '<circle cx="' . round($dot[0], 1) . '" cy="' . round($dot[1], 1) . '" r="3.4" '
                  . 'fill="' . $contourCol . '" stroke="#ffffff" stroke-width="1" stroke-opacity="0.7"/>';
            $svg .= '<text x="' . round($lx, 1) . '" y="' . round($ly + $labelSize * 0.35, 1) . '" '
                  . 'font-size="' . $labelSize . '" font-weight="700" fill="#243018" text-anchor="' . $anchor . '" '
                  . 'paint-order="stroke" stroke="#ffffff" stroke-width="' . $halo . '" stroke-opacity="0.85">'
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
              . '<text x="' . ($gx + (int) round($ls * 1.2) + 8) . '" y="' . $lyT . '" font-size="' . $ls . '" fill="' . $textCol . '">Grenze</text>';
        if ($this->ReadPropertyBoolean('ShowMowPath')) {
            $gx2 = $gx + (int) round($ls * 1.2) + 8 + (int) round(strlen('Grenze') * $ls * 0.6) + 26;
            $svg .= '<line x1="' . $gx2 . '" y1="' . ($lyT - (int) round($ls * 0.28)) . '" x2="' . ($gx2 + (int) round($ls * 1.4))
                  . '" y2="' . ($lyT - (int) round($ls * 0.28)) . '" stroke="#ef8b2c" stroke-width="3" stroke-opacity="0.85"/>'
                  . '<text x="' . ($gx2 + (int) round($ls * 1.4) + 8) . '" y="' . $lyT . '" font-size="' . $ls . '" fill="' . $textCol . '">Gemäht</text>';
        }
        $svg .= '</g>';

        // Live-Position (roter Punkt + Richtungsstrich)
        if ($robot !== null && isset($robot['x'], $robot['y'])
            && $robot['x'] >= $minx && $robot['x'] <= $maxx
            && $robot['y'] >= $miny && $robot['y'] <= $maxy) {
            $rp = $tx([$robot['x'], $robot['y']]);
            $rx = round($rp[0], 1);
            $ry = round($rp[1], 1);
            $r  = max(5, (int) round($legendSize * 0.45));
            if (isset($robot['h'])) {
                // Karten-Richtung (cos,sin) → Bildschirm (x rechts, y unten ⇒ -sin)
                $rad = deg2rad((float) $robot['h']);
                $len = $r * 2.4;
                $ex = round($rx + $len * cos($rad), 1);
                $ey = round($ry - $len * sin($rad), 1);
                $svg .= '<line x1="' . $rx . '" y1="' . $ry . '" x2="' . $ex . '" y2="' . $ey . '" '
                      . 'stroke="#e53935" stroke-width="' . max(2, (int) round($r * 0.4)) . '" stroke-linecap="round"/>';
            }
            $svg .= '<circle cx="' . $rx . '" cy="' . $ry . '" r="' . $r . '" '
                  . 'fill="#e53935" stroke="#ffffff" stroke-width="' . max(2, (int) round($r * 0.35)) . '"/>';
        }

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
            // Stray-Ausgaben der Befehle (z. B. "Steuerung gesperrt") einfangen,
            // damit die JSON-Antwort sauber bleibt — und als Toast-Text nutzen.
            ob_start();
            $ok = $this->handleHookAction((string) $action, array_merge($_GET, $_POST));
            $inline = trim((string) ob_get_clean());
            $msg = $inline !== '' ? $inline : (string) $this->GetValue('LastCommand');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'msg' => $msg]);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->buildDashboardHtml();
    }

    private function handleHookAction(string $action, array $p): bool
    {
        switch ($action) {
            case 'all':    return $this->StartAll();
            case 'edge':   return $this->StartEdge();
            case 'pause':  return $this->MowerPause();
            case 'stop':   return $this->MowerStop();
            case 'dock':   return $this->MowerDock();
            case 'home':   return $this->ReturnHome();
            case 'zone':   return $this->StartZone((int) ($p['id'] ?? 0));
            case 'queueadd':
                $zid = (int) ($p['id'] ?? 0);
                if ($zid > 0) {
                    $q = $this->getQueue();
                    $q[] = $zid;
                    $this->setQueue($q);
                    echo sprintf($this->Translate('%s added to mow order'), $this->zoneName($zid));
                }
                return true;
            case 'queuerun':   return $this->QueueRun();
            case 'queueclear': $this->QueueClear(); echo $this->Translate('Mow order cleared'); return true;
            case 'poll':       $this->Poll();       echo $this->Translate('Status updated');   return true;
            case 'loadmap':    return $this->LoadMap();
        }
        return true;
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
            'progress'  => (int) $this->GetValue('Progress'),
            'areaDone'  => round((float) $this->GetValue('AreaDone'), 1),
            'areaTotal' => round((float) $this->GetValue('AreaTotal'), 1),
            'remaining' => (int) $this->GetValue('RemainingTime'),
            'lastCmd'   => (string) $this->GetValue('LastCommand'),
            'updated'  => $lu > 0 ? date('d.m.Y H:i:s', $lu) : '–',
            'order'      => (string) $this->GetValue('ZoneOrder'),
            'orderItems' => $orderItems,
            'map'        => (string) $this->GetValue('Map'),
            'zones'    => json_decode($this->ReadAttributeString('MapZones'), true) ?: [],
            'allow'    => (bool) $this->ReadPropertyBoolean('AllowControl'),
            'layout'      => $this->ReadPropertyString('DashboardLayout'),
            'controls'    => $this->buttonList('ControlButtons'),
            'zoneButtons' => $this->buttonList('ZoneButtons'),
        ];
    }

    /** Sichtbare Buttons einer Button-Property in konfigurierter Reihenfolge (fürs Dashboard). */
    private function buttonList(string $property): array
    {
        $cfg = json_decode($this->ReadPropertyString($property), true);
        $out = [];
        if (is_array($cfg)) {
            foreach ($cfg as $b) {
                if (!is_array($b) || !($b['Show'] ?? true)) {
                    continue;
                }
                $key = (string) ($b['Key'] ?? '');
                if ($key !== '') {
                    $out[] = ['key' => $key, 'label' => (string) ($b['Label'] ?? '')];
                }
            }
        }
        return $out;
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
html,body{height:100%;margin:0;overflow:hidden}
body{font-family:'Segoe UI',Roboto,Arial,sans-serif;background:#1c1f23;color:#e7ebe6}
.wrap{height:100%;display:flex;flex-direction:column;padding:1.2vh 1vw;gap:1.1vh}
.top{display:flex;align-items:center;justify-content:space-between;flex:0 0 auto}
.top h1{font-size:clamp(15px,2.6vh,20px);font-weight:600;margin:0}
.dot{width:11px;height:11px;border-radius:50%;display:inline-block;margin-right:6px;background:#888;vertical-align:middle}
.dot.on{background:#4caf50}.dot.off{background:#e74c3c}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:.8vw;flex:0 0 auto}
.card{background:#272b30;border-radius:10px;padding:1vh 1vw;min-width:0}
.card .lbl{font-size:clamp(10px,1.4vh,12px);color:#9aa3a0;text-transform:uppercase;letter-spacing:.04em}
.card .val{font-size:clamp(15px,2.6vh,22px);font-weight:600;margin-top:.3vh;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bat{height:7px;border-radius:4px;background:#3a3f45;margin-top:.7vh;overflow:hidden}
.bat>i{display:block;height:100%;background:#4caf50;transition:width .4s}
.prog{flex:0 0 auto;background:#272b30;border-radius:10px;padding:.8vh 1vw;display:none}
.prog .ptop{display:flex;justify-content:space-between;align-items:baseline;font-size:clamp(11px,1.5vh,13px);color:#c8d0cb;margin-bottom:.6vh}
.prog .ptop b{font-weight:600}
.prog .pbar{height:9px;border-radius:5px;background:#3a3f45;overflow:hidden}
.prog .pbar>i{display:block;height:100%;background:linear-gradient(90deg,#4caf50,#7ec96a);transition:width .5s}
.main{flex:1;min-height:0;display:flex;gap:1vw}
.map{flex:1;min-height:0;order:2;background:#23272b;border-radius:12px;padding:.8vh;display:flex;align-items:center;justify-content:center;overflow:hidden}
.map svg{max-width:100%!important;max-height:100%!important;width:auto!important;height:auto!important}
.grp{flex:0 0 clamp(180px,22vw,300px);min-height:0;display:flex;flex-direction:column;gap:.9vh;overflow:auto}
.grp.ctrl{order:1}.grp.zones{order:3}
.main.right .map{order:1}
.main.right .grp{order:2;flex-basis:clamp(210px,26vw,320px)}
.main.right .grp.zones{order:3}
.main.bottom{flex-direction:column}
.main.bottom .map{order:1}
.main.bottom .grp{order:2;flex:0 0 auto;width:100%;overflow:visible}
.main.bottom .btns,.main.bottom .row{flex-direction:row;flex-wrap:wrap}
.main.bottom button,.main.bottom select{width:auto}
.sec{background:#272b30;border-radius:10px;padding:1vh;display:flex;flex-direction:column;gap:.7vh}
.sec h2{font-size:clamp(11px,1.6vh,14px);font-weight:600;margin:0;color:#c8d0cb}
.btns,.row{display:flex;flex-direction:column;gap:.6vh}
.main.bottom .btns,.main.bottom .row{flex-direction:row;flex-wrap:wrap}
button,select{font:inherit;border:none;border-radius:9px;padding:clamp(6px,1.2vh,11px) 10px;cursor:pointer;width:100%;font-size:clamp(12px,1.7vh,15px)}
.main.bottom button,.main.bottom select{width:auto}
button{background:#3a4047;color:#e7ebe6}button:hover{background:#454c54}
button.go{background:#3f7d34}button.go:hover{background:#4a9140}
button.stop{background:#9a3b32}button.stop:hover{background:#b5453a}
select{background:#2f343a;color:#e7ebe6}
.zlist{display:flex;flex-direction:column;gap:.5vh}
.zi{background:#2f343a;color:#e7ebe6;border:2px solid transparent;border-radius:9px;padding:clamp(6px,1.1vh,10px) 10px;cursor:pointer;text-align:center;font-size:clamp(12px,1.7vh,15px)}
.zi.sel{border-color:#7ec96a;background:#36433a}
.zrow{display:flex;gap:.5vh;align-items:stretch}
.zrow .zi{flex:1;min-width:0}
.zadd{flex:0 0 auto;width:auto;padding:0 12px;background:#3a4047;color:#e7ebe6;border:none;border-radius:9px;cursor:pointer;font-size:clamp(14px,2vh,18px);font-weight:600;line-height:1}
.zadd:hover{background:#4a9140}
.order{font-size:clamp(12px,1.6vh,14px);color:#bcd0b0;background:#23282c;border-radius:8px;padding:.6vh .8vh}
.order div{padding:2px 2px;border-bottom:1px solid #2c3338}
.order div:last-child{border-bottom:none}
.warn{background:#5a3a14;color:#ffd9a0;border-radius:9px;padding:.7vh 1vh;font-size:clamp(12px,1.6vh,14px);flex:0 0 auto;display:none}
.upd{color:#8a938e;font-size:clamp(11px,1.4vh,13px)}
.toast{position:fixed;left:50%;bottom:2.5vh;transform:translateX(-50%) translateY(20px);background:#2f343a;color:#e7ebe6;padding:1vh 2vw;border-radius:10px;font-size:clamp(12px,1.7vh,15px);opacity:0;pointer-events:none;transition:opacity .25s,transform .25s;max-width:90vw;text-align:center;box-shadow:0 4px 16px rgba(0,0,0,.45);z-index:50}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.ok{border-left:4px solid #4caf50}.toast.err{border-left:4px solid #e74c3c}
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
 <div class="prog" id="prog">
  <div class="ptop"><span>Fortschritt</span><span id="progTxt">–</span></div>
  <div class="pbar"><i id="progBar" style="width:0%"></i></div>
 </div>
 <div class="main split" id="main">
  <div class="grp ctrl">
   <div class="sec"><h2>Steuerung</h2>
    <div class="btns" id="ctrlBtns"></div>
   </div>
   <div class="sec"><div class="row">
     <button onclick="cmd('poll')">Status aktualisieren</button>
     <button onclick="cmd('loadmap')">Karte neu laden</button>
     <span id="upd" class="upd"></span>
   </div></div>
  </div>
  <div class="map" id="map"></div>
  <div class="grp zones">
   <div class="sec"><h2>Zonen mähen</h2>
    <div class="zlist" id="zoneList"></div>
    <div class="row" id="zoneBtns"></div>
    <div class="order" id="order">–</div>
   </div>
  </div>
 </div>
</div>
<div id="toast" class="toast"></div>
<script>
var selZone=0,zlist=[];
function toast(msg,ok){var t=document.getElementById('toast');t.textContent=msg;t.className='toast show '+(ok?'ok':'err');clearTimeout(window._tt);window._tt=setTimeout(function(){t.className='toast'},3500);}
var CTRL={all:{t:'Gesamtes Gebiet',c:'go'},edge:{t:'Begrenzung',c:'go'},pause:{t:'Pause',c:''},stop:{t:'Stop',c:'stop'},dock:{t:'Andocken',c:''},home:{t:'Stopp & Heim',c:'stop'}};
var ctrlKey='';
function renderControls(list){var el=document.getElementById('ctrlBtns');if(!el)return;el.innerHTML='';(list||[]).forEach(function(b){var m=CTRL[b.key];if(!m)return;var btn=document.createElement('button');btn.className=m.c;btn.textContent=b.label||m.t;btn.onclick=function(){cmd(b.key)};el.appendChild(btn);});}
var ZBTN={zonestart:{t:'Diese Zone mähen',c:'go',f:function(){cmd('zone&id='+zv())}},queueadd:{t:'+ zur Reihenfolge',c:'',f:function(){addSel()}},queuerun:{t:'Reihenfolge mähen',c:'go',f:function(){cmd('queuerun')}},queueclear:{t:'Leeren',c:'',f:function(){clearSel()}}};
var zbtnKey='';
function renderZoneBtns(list){var el=document.getElementById('zoneBtns');if(!el)return;el.innerHTML='';(list||[]).forEach(function(b){var m=ZBTN[b.key];if(!m)return;var btn=document.createElement('button');btn.className=m.c;btn.textContent=b.label||m.t;btn.onclick=m.f;el.appendChild(btn);});}
function zv(){return selZone}
function renderZones(){var el=document.getElementById('zoneList');if(!el)return;el.innerHTML='';if(selZone===0&&zlist.length)selZone=zlist[0].id;zlist.forEach(function(z){var row=document.createElement('div');row.className='zrow';var b=document.createElement('div');b.className='zi'+(z.id==selZone?' sel':'');b.textContent=z.name;b.onclick=function(){selZone=z.id;renderZones()};var p=document.createElement('button');p.className='zadd';p.textContent='+';p.title='Zur Reihenfolge hinzufügen';p.onclick=function(ev){ev.stopPropagation();cmd('queueadd&id='+z.id);};row.appendChild(b);row.appendChild(p);el.appendChild(row)})}
function addSel(){if(selZone>0){cmd('queueadd&id='+selZone);selZone=0;renderZones()}}
function clearSel(){selZone=0;cmd('queueclear');renderZones()}
async function cmd(a){try{var r=await (await fetch('?action='+a)).json();toast(r.msg||(r.ok?'Befehl gesendet':'Fehlgeschlagen'),r.ok);}catch(e){toast('Verbindungsfehler',false);} setTimeout(refresh,600)}
let zonesKey='';
async function refresh(){
 let d; try{ d=await (await fetch('?action=status')).json() }catch(e){return}
 document.getElementById('bat').textContent=d.battery;
 document.getElementById('batBar').style.width=Math.max(0,Math.min(100,d.battery))+'%';
 document.getElementById('state').textContent=d.state||'–';
 document.getElementById('charge').textContent=d.charging||'–';
 document.getElementById('fw').textContent=d.firmware||'–';
 var pr=document.getElementById('prog');
 if(d.progress>0||(d.areaTotal&&d.areaTotal>0)){
  pr.style.display='block';
  document.getElementById('progBar').style.width=Math.max(0,Math.min(100,d.progress))+'%';
  var pt='<b>'+d.progress+'%</b>';
  if(d.areaTotal>0)pt+=' · '+d.areaDone+'/'+d.areaTotal+' m²';
  if(d.remaining>0)pt+=' · ca. '+d.remaining+' min';
  document.getElementById('progTxt').innerHTML=pt;
 }else{pr.style.display='none'}
 var ord=document.getElementById('order');
 if(d.orderItems&&d.orderItems.length){ord.innerHTML=d.orderItems.map(function(t){return '<div>'+t+'</div>'}).join('')}else{ord.textContent='–'}
 document.getElementById('upd').textContent='aktualisiert: '+d.updated;
 var dot=document.getElementById('dot'); dot.className='dot '+(d.online?'on':'off');
 document.getElementById('onTxt').textContent=d.online?'online':'offline';
 document.getElementById('warn').style.display=d.allow?'none':'block';
 document.getElementById('map').innerHTML=d.map||'';
 document.getElementById('main').className='main '+(d.layout||'split');
 var k=JSON.stringify(d.zones||[]);
 if(k!==zonesKey){zonesKey=k;zlist=d.zones||[];renderZones();}
 var ck=JSON.stringify(d.controls||[]);
 if(ck!==ctrlKey){ctrlKey=ck;renderControls(d.controls);}
 var zk=JSON.stringify(d.zoneButtons||[]);
 if(zk!==zbtnKey){zbtnKey=zk;renderZoneBtns(d.zoneButtons);}
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
    //  Live-Position (Stufe 4) — gefüttert vom MQTT-Kindmodul
    // ------------------------------------------------------------------ //

    /**
     * Öffentlich (MOVA_UpdatePosition): Live-Position des Roboters setzen und den
     * roten Punkt in die Karte zeichnen. x/y in Karten-Einheiten, heading in Grad.
     * Das SVG-Neurender ist auf ~alle 2 s gedrosselt (Schonung).
     */
    public function UpdatePosition(int $X, int $Y, float $Heading): void
    {
        $this->SetValue('RobotX', $X);
        $this->SetValue('RobotY', $Y);
        $this->SetValue('RobotHeading', (int) round($Heading));
        $this->SetValue('PositionUpdate', time());

        $now  = time();
        $last = (int) $this->GetBuffer('LastMapRender');
        if ($now - $last < 2) {
            return; // Render drosseln
        }
        $raw = $this->ReadAttributeString('MapRaw');
        if ($raw === '') {
            return; // noch keine Karte geladen
        }
        $map = json_decode($raw, true);
        if (!is_array($map)) {
            return;
        }
        $svg = $this->buildMapSvg($map, ['x' => $X, 'y' => $Y, 'h' => $Heading]);
        if ($svg !== '') {
            $this->SetValue('Map', $svg);
            $this->SetBuffer('LastMapRender', (string) $now);
        }
    }

    /**
     * Öffentlich (MOVA_UpdateProgress): Mäh-Fortschritt setzen (aus 1:4-Task-Block).
     * Prozent + gemähte/Gesamt-Fläche kommen direkt vom Gerät; die Restzeit wird aus
     * der Fortschrittsrate seit einem Anker geschätzt (keine Geräte-Property dafür).
     */
    public function UpdateProgress(float $Percent, float $CurrentArea, float $TotalArea): void
    {
        $pct = max(0.0, min(100.0, $Percent));
        $this->SetValue('Progress', (int) round($pct));
        $this->SetValue('AreaDone', round($CurrentArea, 2));
        if ($TotalArea > 0) {
            $this->SetValue('AreaTotal', round($TotalArea, 2));
        }

        // Restzeit: lineare Schätzung aus der Rate seit dem Anker.
        $now    = time();
        $anchor = json_decode((string) $this->GetBuffer('ProgAnchor'), true);
        if (!is_array($anchor) || $pct <= 1.0 || $pct + 0.5 < (float) ($anchor['p'] ?? 0)) {
            // Sitzungsstart / Reset / neuer Auftrag → Anker neu setzen
            $anchor = ['t' => $now, 'p' => $pct];
            $this->SetBuffer('ProgAnchor', json_encode($anchor));
        }
        if ($pct >= 100.0) {
            $this->SetValue('RemainingTime', 0);
            return;
        }
        $elapsed = $now - (int) $anchor['t'];
        $dp      = $pct - (float) $anchor['p'];
        if ($elapsed >= 30 && $dp >= 1.0) {
            $remainingMin = (100.0 - $pct) / ($dp / ($elapsed / 60.0));
            $this->SetValue('RemainingTime', (int) max(0, min(1440, round($remainingMin))));
        }
        // sonst: zu wenig Datenbasis — letzten Schätzwert behalten
    }

    /**
     * Öffentlich (MOVA_UpdateLiveStatus): Echtzeit-Status aus MQTT-Pushes (2:1/3:1/3:2).
     * Werte < 0 = „keine Änderung". So springt der Status ~sofort auf „Mäht", sobald der
     * Mäher wirklich startet — die echte Bestätigung eines Steuerbefehls.
     */
    public function UpdateLiveStatus(int $State, int $Battery, int $Charging): void
    {
        if ($State >= 0) {
            $this->SetValue('State', $State);
        }
        if ($Battery >= 0) {
            $this->SetValue('Battery', $Battery);
        }
        if ($Charging >= 0) {
            $this->SetValue('Charging', $Charging);
        }
        $this->SetValue('Online', true);
        $this->SetValue('LastUpdate', time());
    }

    /**
     * Öffentlich (MOVA_LogMission): abgeschlossene Mäh-Sitzung ins Arbeitsprotokoll
     * aufnehmen (Live-Kind bei Event 4:1). Doppelte (gleiche Startzeit) werden übersprungen.
     */
    public function LogMission(string $Json): void
    {
        $e = json_decode($Json, true);
        if (!is_array($e)) {
            return;
        }
        $log = json_decode($this->ReadAttributeString('WorkLogData'), true);
        if (!is_array($log)) {
            $log = [];
        }
        $ts = (int) ($e['ts'] ?? 0);
        foreach ($log as $x) {
            if ($ts > 0 && (int) ($x['ts'] ?? 0) === $ts) {
                return; // schon protokolliert
            }
        }
        array_unshift($log, $e);
        if (count($log) > 50) {
            $log = array_slice($log, 0, 50);
        }
        $this->WriteAttributeString('WorkLogData', json_encode($log));
        $this->renderWorkLog();
        $this->LogMessage('Arbeitsprotokoll: Sitzung ergänzt (' . date('d.m.Y H:i', $ts > 0 ? $ts : time()) . ')', KL_NOTIFY);
    }

    /** Rendert das Arbeitsprotokoll als HTML-Tabelle in die WorkLog-Variable. */
    private function renderWorkLog(): void
    {
        $log = json_decode($this->ReadAttributeString('WorkLogData'), true);
        if (!is_array($log)) {
            $log = [];
        }
        $statusMap = [1 => 'Abgeschlossen', 2 => 'Unvollständig', 3 => 'Unterbrochen'];
        $rows = '';
        foreach ($log as $e) {
            $ts   = (int) ($e['ts'] ?? 0);
            $when = $ts > 0 ? date('d.m.Y H:i', $ts) : '–';
            $dur  = (int) ($e['dur'] ?? 0);
            $area = round((float) ($e['area'] ?? 0), 1);
            $pct  = (int) round((float) ($e['pct'] ?? 0));
            $st   = $statusMap[(int) ($e['status'] ?? 0)] ?? '–';
            if ((int) ($e['reason'] ?? 0) === 101) {
                $st .= ' (Akku)';
            }
            // Sitzungs-Soll = gemäht / Fortschritt (der Wert vom Gerät ist die Gesamtkarte)
            $soll     = $pct > 0 ? (int) round($area * 100.0 / $pct) : 0;
            $sollCell = $soll > 0 ? ($soll . ' m²') : '–';
            $rows .= '<tr><td>' . $when . '</td><td>' . $dur . ' min</td><td>' . $area
                   . ' m²</td><td>' . $sollCell . '</td><td>' . $pct . '%</td><td>'
                   . htmlspecialchars($st, ENT_QUOTES) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" style="text-align:center;color:#8a938e;padding:10px">'
                  . $this->Translate('No entries yet — filled after the next completed mowing session.')
                  . '</td></tr>';
        }
        $html = '<style>.mwl{font-family:Segoe UI,Arial,sans-serif;background:#23272b;border-radius:10px;'
              . 'padding:12px;color:#e7ebe6}.mwl table{width:100%;border-collapse:collapse;font-size:14px}'
              . '.mwl th{color:#9aa3a0;text-align:left;font-weight:600;padding:5px 8px;border-bottom:2px solid #333}'
              . '.mwl td{padding:5px 8px;border-bottom:1px solid #2c3338}.mwl tr:last-child td{border-bottom:none}'
              . '.mwl h3{margin:0 0 8px;font-size:16px}</style>'
              . '<div class="mwl"><h3>' . $this->Translate('Work log') . '</h3><table>'
              . '<tr><th>' . $this->Translate('Date') . '</th><th>' . $this->Translate('Duration')
              . '</th><th>' . $this->Translate('Area') . '</th><th>' . $this->Translate('Target')
              . '</th><th>' . $this->Translate('Progress')
              . '</th><th>' . $this->Translate('Status') . '</th></tr>' . $rows . '</table></div>';
        $this->SetValue('WorkLog', $html);
    }

    /**
     * Öffentlich (MOVA_GetMqttAuth): liefert dem Live-Kindmodul die aktuellen
     * MQTT-Verbindungsdaten als JSON (inkl. frischem Token). Loggt ggf. ein.
     */
    public function GetMqttAuth(): string
    {
        if (!$this->ensureLogin() || !$this->ensureDevice()) {
            return json_encode(['ok' => false]);
        }
        $host     = $this->ReadAttributeString('Host'); // bindDomain "hostname:port"
        $hostname = $host;
        $port     = 0;
        if (strpos($host, ':') !== false) {
            $parts    = explode(':', $host, 2);
            $hostname = $parts[0];
            $port     = (int) $parts[1];
        }
        $did    = $this->ReadAttributeString('Did');
        $uid    = $this->ReadAttributeString('Uid');   // masterUid (Topic/ClientID)
        $uuid   = $this->ReadAttributeString('Uuid');  // Login-uid (MQTT-Benutzername)
        $model  = $this->ReadAttributeString('Model');
        $region = $this->ReadPropertyString('Region');
        $token  = $this->ReadAttributeString('AccessToken');

        return json_encode([
            'ok'       => true,
            'host'     => $hostname,
            'port'     => $port,
            'username' => $uuid,
            'password' => $token,
            'clientid' => $this->shortClientId($uid),
            'topic'    => '/status/' . $did . '/' . $uid . '/' . $model . '/' . $region . '/',
            'did'      => $did,
            'uid'      => $uid,
            'model'    => $model,
            'region'   => $region,
        ]);
    }

    /** Kurze, broker-taugliche ClientID (Symcon/MQTT 3.1: max. 23 Zeichen). */
    private function shortClientId(string $uid): string
    {
        $id = 'p_' . $uid . '_' . substr(md5($uid . $this->InstanceID), 0, 6);
        return strlen($id) > 23 ? substr($id, 0, 23) : $id;
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
        if (!IPS_VariableProfileExists('MOVA.Area')) {
            IPS_CreateVariableProfile('MOVA.Area', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('MOVA.Area', '', ' m²');
            IPS_SetVariableProfileDigits('MOVA.Area', 1);
        }
        if (!IPS_VariableProfileExists('MOVA.Minutes')) {
            IPS_CreateVariableProfile('MOVA.Minutes', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('MOVA.Minutes', '', ' min');
        }
    }
}
