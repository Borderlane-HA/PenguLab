# ioBroker und Node-RED in PenguLab 2.10

## ioBroker

Entwickelt gegen die dokumentierte REST-API und den Paketquellcode von
`ioBroker.rest-api 4.0.2`. PenguLab verbindet sich mit dem API-Adapter; die
Versionsnummer des js-controller ist hierfür nicht die Schnittstellenversion.

1. Im ioBroker den Adapter **REST-API** installieren/starten.
2. In PenguLab unter **PenguHub** das Paket **ioBroker** installieren.
3. Unter **Integrationen → Verbindung hinzufügen → ioBroker** die Adapter-URL
   eintragen, normalerweise `http://HOST:8093`. Bei Betrieb als Web-Erweiterung
   den vollständigen API-Basispfad verwenden.
4. Bei aktivierter API-Authentifizierung Benutzer und Passwort angeben.
   PenguLab verwendet HTTP Basic im Authorization-Header, keine URL-Parameter.
   Für verschlüsselte Übertragung HTTPS verwenden; die Zertifikatsprüfung ist
   wie bei den vorhandenen Integrationen einstellbar.
5. **Datenpunkt-Filter** festlegen, z.B. `0_userdata.0.*`, `alias.0.*` oder
   `mqtt.0.*`. Der Filter begrenzt Auswahl, Lesen und Schreiben. Standard:
   `0_userdata.0.*`. Bei sehr großen Installationen einen engen Filter verwenden;
   die Auswahl zeigt maximal 2.000 Datenpunkte. Für unterschiedliche Bereiche
   können mehrere Verbindungen angelegt werden.
6. Verbindung testen, dann ein Widget hinzufügen und bis zu acht Datenpunkte
   auswählen. Beim Auswählen kann zunächst „unavailable“ stehen: Der Katalog lädt
   Objektmetadaten; das Widget lädt anschließend die aktuellen Werte.

Anzeigen: Zahlen, Texte und boolesche Werte mit Namen und Einheit. Steuern:
boolean als Schalter, schreibbare Zahlen als Eingabefeld, boolean mit
`common.role` beginnend mit `button` als Aktion. `common.write`, Datentyp sowie
`common.min`/`common.max` werden serverseitig geprüft. Befehle werden mit
`ack:false` geschrieben; Geräte/Adapter liefern die Bestätigung.

Die API-Berechtigungen des ioBroker-Benutzers müssen Lesen der gewählten
Objekte/Zustände und für die gewünschten Bedienelemente Schreiben erlauben.

## Node-RED

Zielversion: **Node-RED 5.0.7**, aktuelle stabile Version am 12.09.2026.
Die mitgelieferte Bridge verwendet ausschließlich Standard-Nodes und benötigt
keine zusätzliche Palette. Sie stellt eigene Werte und Aktionen bereit;
Node-RED-Adminzugang oder ein automatisches Deployment sind nicht erforderlich.

1. `addons/nodered/pengulab-bridge.json` über **Import → Datei** in Node-RED laden.
2. Die Eigenschaften des neuen Tabs **PenguLab Bridge** öffnen. Unter
   Umgebungsvariablen `PENGULAB_TOKEN` einen selbst erzeugten zufälligen Token mit
   mindestens 16 Zeichen eintragen. Ohne Token antwortet die Bridge mit 503.
3. Deploy ausführen. Die vier ausdrücklich als Demo benannten Datenpunkte
   dienen zum Ausprobieren. Demo Schalter und Sollwert ändern nur Demo-Werte.
4. In PenguLab das Paket **Node-RED** im PenguHub installieren und die Integration
   anlegen: URL `http://HOST:1880/pengulab`, Token wie im Bridge-Tab.
   Falls `httpNodeRoot` gesetzt ist, diesen Pfad vor `/pengulab` ergänzen.
5. Verbindung testen und Datenpunkte als Widget auswählen.

### Echte Geräte verbinden

Im Function-Node **Exposed data points & token check** die Liste `definitions`
anpassen. Jeder Eintrag enthält:

| Feld | Bedeutung |
|---|---|
| `entity_id` | Eindeutige, stabile Kennung, z.B. `heating.setpoint` |
| `name` | Anzeigename |
| `value_type` | `boolean`, `number` oder `string` |
| `domain` | `sensor`, `switch`, `number` oder `button` |
| `writable` | Nur ausdrücklich freigegebene Datenpunkte steuerbar |
| `unit` | Optionale Einheit |
| `min`, `max`, `step` | Optionale Zahlengrenzen und Eingabeschritt |

**Befehle:** Ausgang 2 dieses Function-Nodes an die eigenen Automations-Nodes
anschließen, beispielsweise über einen Switch nach `msg.topic` verzweigen.
`msg.topic` enthält die Kennung, `msg.payload` den neuen Wert und
`msg.penguLabAction` die Aktion (`set` oder `trigger`).

**Rückmeldung:** Aktuelle Gerätewerte an **Store device feedback** schicken:
`msg.topic = entity_id`, `msg.payload = Wert`. Den Node für Demo-Rückmeldungen
und den Demo-Inject bei produktiver Nutzung entfernen. Gerätewerte können auch
über einen Link-In auf demselben Bridge-Tab an den Speicher-Node gelangen.

Ein angenommener Befehl liefert HTTP 202. Die Anzeige ändert sich erst mit
Geräterückmeldung, nicht aufgrund einer angenommenen erfolgreichen Schaltung.
Ohne Rückmeldung steht ein Wert auf `unavailable`; der Kontext ist standardmäßig
flüchtig und wird nach einem Neustart durch neue Gerätewerte gefüllt.

### Bridge-Protokoll 1

Alle Aufrufe benötigen `Authorization: Bearer <Token>`.

- `GET /health`: `{ "ok": true, "protocol": 1 }`
- `GET /entities`: `{ "ok": true, "entities": [...] }`
- `POST /action`: `{ "entity_id": "...", "action": "set", "value": true }`

Die PHP-Anbindung prüft Freigaben und Werte vor dem Senden; die Bridge prüft
sie unabhängig erneut. Ein Widget mit deaktivierter Steuerung erlaubt über
seine Auswahl keine Aktionen. Vorhandene PenguLab-Rechte pro Integration gelten
auch für beide neuen Widget-Typen.

## Quellen und Testgrenzen

- https://github.com/ioBroker/ioBroker.rest-api
- https://github.com/node-red/node-red/releases/tag/5.0.7
- https://nodered.org/docs/user-guide/writing-functions

Connector- und Flow-Vertragstests laufen mit Testdaten. Sie ersetzen keinen
Verbindungstest gegen die individuellen Geräte und Berechtigungen im Homelab.
