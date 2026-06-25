# MOVA LiDAX — IP-Symcon Modul

Bindet MOVA-Mähroboter (z. B. **LiDAX Ultra 800**, Modell `mova.mower.g2529b`) über
die **MOVAhome-Cloud** in IP-Symcon ein — ohne Home Assistant, ohne Zwischendienst.

> **Stand: Stufe 1 — Statusabruf.** Steuerung, Karte und Live-Position folgen in
> weiteren Stufen. Diese Stufe liest nur (sendet keine Bewegungsbefehle).

## Funktionen (Stufe 1)
- Login gegen MOVAhome (Region wählbar), automatischer Token-Refresh
- Statusvariablen, zyklisch aktualisiert:
  - **Akku** (%)
  - **Status** (Mäht / Lädt / Rückkehr / Vollständig geladen …)
  - **Ladezustand**
  - **Online**
  - **Firmware**
  - **Letzte Aktualisierung**

## Installation
1. In IP-Symcon: **Kerninstanzen → Modules** öffnen.
2. Modul über die Git-URL dieses Repos hinzufügen (oder lokalen Pfad).
3. Neue Instanz **„MOVA LiDAX"** anlegen.
4. **E-Mail + Passwort** des MOVAhome-Kontos eintragen, Region = EU, Konto-Typ = MOVA.
5. Übernehmen → „Jetzt abrufen" testen.

## Konfiguration
| Feld | Bedeutung |
|------|-----------|
| E-Mail / Passwort | MOVAhome-Zugangsdaten |
| Region | EU (Standard), US, CN, RU, SG |
| Konto-Typ | `mova` (LiDAX) oder `dreame` |
| Geräte-ID | optional; nur nötig bei mehreren Geräten |
| Abrufintervall | Poll-Takt in Sekunden (min. 15) |

## Hinweise
- Undokumentierte/reverse-engineerte Cloud-API — kann sich serverseitig ändern.
- Das Mähen läuft autonom (LiDAR) onboard weiter; die Cloud betrifft nur Monitoring/Steuerung.

## Roadmap
- Stufe 2: Steuerung (Start/Stop/Dock/Pause)
- Stufe 3: Wiesenkarte als Bild (WebFront/Tile)
- Stufe 4: Live-Position in Echtzeit (MQTT-Push)
