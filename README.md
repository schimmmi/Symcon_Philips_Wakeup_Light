# IP-Symcon Modul – Philips Somneo Wake-up Light (HF3671/01)

Dieses Modul ermöglicht die Nutzung der lokalen HTTPS-API eines Philips Somneo Wake-up Lights. Es kann Alarme lesen und – je nach Konfiguration – weitere Gerätestatus auslesen sowie zentrale Funktionen steuern.

Hinweis: Die lokal bereitgestellte API unterscheidet sich je nach Firmwarestand. Standardmäßig wird ein plausibler Endpunkt für Alarme verwendet. Alle weiteren Funktionen sind über frei konfigurierbare Endpunkte und Payloads nutzbar.

## Funktionen
- Alarme (Weckzeiten) lesen
  - Rohdaten als JSON (Variable: "Weckzeiten (JSON)")
  - Aufbereitung von bis zu 4 Alarmen (aktiv/aus, Zeit) aus gängigen Feldnamen/Strukturen
- Gerätestatus (optional, wenn Endpunkte konfiguriert)
  - Geräteinfo (Variablen: Geräteinfo (JSON), Gerätename, Firmware-Version)
  - Sensoren (Variablen: Sensoren (JSON), Temperatur, Luftfeuchtigkeit, Helligkeit/Lux)
  - Lichtstatus (Variablen: Licht an, Helligkeit)
  - Audio/Volume (Variablen: Lautstärke, Stumm)
- Steuerung (Variablen mit Aktion; Endpunkte/Methoden/Payloads konfigurierbar)
  - Licht Ein/Aus (LightOn)
  - Helligkeit (%)
  - Sonnenuntergang Start/Stop (SunsetActive)
  - Lautstärke (%)
  - Stumm Ein/Aus
- Manuelles Aktualisieren und zyklisches Update

## Installation
1. Dieses Repository als IP-Symcon Modul hinzufügen (Konsole: Kern Instanzen > Module > Hinzufügen > Git-URL).
2. Instanz "Philips Somneo (HF3671/01)" anlegen.
3. Konfiguration ausfüllen:
   - IP/Hostname oder komplette URL (BaseURL)
   - Port: 443 (Standard), HTTPS verwenden, TLS-Zertifikat prüfen (ggf. aus bei self-signed)
   - (Optional) Authentifizierungs-Header Name/Wert
   - Alarme (GET) Endpunkt: Voreinstellung: `/di/v1/products/1/wualm/aalms`
   - Erweiterte Endpunkte (optional):
     - Geräteinfo (GET)
     - Sensoren (GET)
     - Lichtstatus (GET)
     - Licht Ein/Aus (PUT/POST) + Methode + Payloads für EIN/AUS
     - Helligkeit (PUT/POST) + Methode + Payload-Template (z. B. `{ "brightness":%d }`)
     - Sonnenuntergang START/STOP (POST) + Methode + Payloads
     - Audio/Lautstärke Status (GET)
     - Lautstärke setzen (PUT/POST) + Methode + Payload-Template (z. B. `{ "volume":%d }`)
     - Stumm (PUT/POST) + Methode + Payloads
   - Intervall (Sekunden): z. B. 300
4. Speichern. Über die Schaltfläche "Jetzt aktualisieren" kann ein Abruf getestet werden.

## Repository-Struktur
- Das Modul liegt im Ordner `Somneo/` auf Root-Ebene.

## Hinweise zur API
- Viele Somneo-Geräte nutzen HTTPS mit selbstsigniertem Zertifikat. In diesem Fall kann die Prüfung in den Einstellungen deaktiviert werden.
- Die genaue Struktur der API (Pfad und JSON-Felder) kann je nach Firmware variieren. Das Modul versucht, häufige Varianten zu erkennen (z. B. `on`/`power`, `brightness`/`bri`, `temperature`/`temp`).
- Endpunkte, Methoden und Payloads sind frei konfigurierbar, damit verschiedene Firmwarestände unterstützt werden können.

## Fehlerdiagnose
- In der IP-Symcon-Meldungsliste und im Debug der Instanz erscheinen Logeinträge (HTTP-Status, URL, Teile des Bodys) zur Fehlersuche.
- Wenn die API einen speziellen Header (z. B. Authorization: Bearer <token>) benötigt, kann dieser in der Instanz gesetzt werden.

## Beispiele (Payload-Templates)
- Licht Ein/Aus: `{ "on": true }` / `{ "on": false }`
- Helligkeit (%): `{ "brightness": %d }`
- Lautstärke (%): `{ "volume": %d }`
- Sunset Start/Stop: `{}`

## Hinweise zur Sicherheit
- Die Option "TLS-Zertifikat prüfen" sollte, wenn möglich, aktiviert sein. Bei selbstsignierten Zertifikaten lässt sie sich deaktivieren (nur im LAN empfohlen).
