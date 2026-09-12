# PenguLab 2.10.0

## Dashboard-Editor

- Eine Aktualisierung pro Bildschirmbild statt einer Neuberechnung bei jedem
  Pointer-Event. Unveränderte Rasterpositionen und Widgets werden übersprungen.
- Kollisionsauflösung reserviert unbeteiligte Karten und sucht freie Kanten
  statt Hunderte von Rasterzeilen abzutasten.
- Magnetische Kanten, Mittellinien, gleiche Breiten/Höhen und vorhandene Abstände
  mit sichtbaren Hilfslinien. Fangbereich ca. 9 px, Haltebereich 16 px.
- **Alt** unterdrückt den Magneten; das bestehende 8-Pixel-Raster bleibt erhalten.
- Fokussierte Karte mit **Pfeiltasten** um 8 px verschieben; **Shift + Pfeil**
  um 32 px. **↔** öffnet genaue Position/Größe in 8-Pixel-Schritten.
- Keine zusätzlichen Inhaltsüberschriften im Editor; vorhandene echte Titel
  bleiben gleich. Werkzeuge liegen über der Karte. Kein dauerhaftes Wackeln.
- Abbrechen einer Desktop-Geste per Escape oder Pointer-Cancel.
- App-Gruppen entstehen nach 650 ms im mittleren Bereich einer Zielkarte.

## Aktualisierungen

- Timer werden vor einem Dashboard-Neuaufbau beendet. Kein Anwachsen der
  Polling-Intervalle beim wiederholten Bearbeiten oder Speichern.
- Bestehende Widget-Inhalte werden beim Moduswechsel erhalten.
- Sequenzielles Polling je Widget; Pausierung bei verborgenem Tab und während
  einer Desktop-Geste. Laufende Antworten dürfen veraltete Ansichten nicht
  überschreiben. Gleiche Daten werden nicht erneut gerendert.
- Docker startet den vorhandenen PHP-Server mit `PHP_CLI_SERVER_WORKERS=4`
  (über die Container-Umgebung anpassbar).
- Datenabfragen geben die PHP-Session frei, damit langsame Integrationen keine
  Layout-Speicheranfragen in derselben Session blockieren.
  Hintergrund zur Worker-Einstellung: https://www.php.net/manual/en/features.commandline.webserver.php

## Neue Integrationen

**ioBroker REST-API 4.0.2:** Datenpunkte mit Filter auswählen, Werte anzeigen,
Schalter, Zahlenfelder und Buttons steuern.

**Node-RED 5.0.7:** Importierbarer Bridge-Flow für ausdrücklich bereitgestellte
Werte und Aktionen. Einrichtung: [docs/SMART_HOME.md](docs/SMART_HOME.md).

## Update

Dies ist das vollständige Quellcodepaket. Ein veröffentlichtes Docker-Image
oder GitHub-Release ist damit noch nicht erstellt. Das Paket kann wie bisher
ins Repository übernommen und über dessen Build veröffentlicht werden.

Bei einer Installation aus Quellcode die Programmdateien aktualisieren und
**das bestehende Datenverzeichnis einschließlich SQLite-Datenbank und
Verschlüsselungsschlüssel behalten**. Bei Docker aus diesem Ordner neu bauen
und das bisherige Datenvolume weiterverwenden. Die Datei `assets/js/layout.js`
muss zusammen mit den übrigen Programmdateien ausgeliefert werden.

Gespeicherte 8-Pixel-Layouts benötigen keine Migration. Die neuen Pakete werden
anschließend unter PenguHub angeboten und dort bei Bedarf installiert.
