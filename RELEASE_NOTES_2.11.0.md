# PenguLab 2.11.0 · PenguOps

PenguOps ergänzt PenguLab um ein optionales, zunächst rein lesendes Homelab Health Center. Das Add-on nutzt die bereits eingerichteten Integrationen und führt deren verfügbare Messwerte in einer nachvollziehbaren Teilbewertung zusammen.

## Neu

- Health Score mit sichtbaren Abzügen: 15 Punkte je kritischem Befund und 5 Punkte je Warnung.
- Unbekannte, fehlende oder über fünf Minuten alte Daten werden niemals als gesund gewertet.
- Befundverlauf mit erstem und letztem Auftreten, Erholung und 90 Tagen Aufbewahrung.
- Regeln pro Integration: Warn-/Kritisch-Grenzen, Pflicht-VMs oder -Container, ausgewählte Home-Assistant-Entitäten, Toleranzzeit, Wartungsfenster und Ausnahmen.
- Zusätzliche Detaildaten aus Proxmox, Docker und Portainer ohne zweite Abfrage derselben API-Antwort.
- Getrennte Hintergrundprozesse für Erfassung und KI-Analyse; langsame lokale Modelle blockieren weder Dashboard noch Monitoring.
- Dashboard-Widget „Homelab Health“ und eigener Eintrag unter Add-ons.
- AI Model Hub mit Ollama, OpenAI, Claude/Anthropic, Google Gemini, IONOS AI Model Hub und xAI.
- Live-Modellabfrage, manuell wählbare Modell-ID, Zeit- und Tokenlimits, Jobstatus und Abbruch.
- Verbindliche Datenvorschau vor jeder externen Analyse. Es werden nur freigegebene Dienstnamen, Zustände, Messzeiten, Prüfungen und Befunde übermittelt – keine Zugangsdaten, URLs oder Rohlogs.

## Betrieb

Nach dem Update `PenguOps` im PenguHub installieren. Beim offiziellen Docker-Image starten Collector und AI-Worker automatisch mit dem Container. Bei manueller PHP-Installation werden sie separat ausgeführt:

```sh
php bin/penguops-worker.php
php bin/penguops-worker.php --ai
```

Beide Kommandos sind dauerhaft laufende Prozesse und sollten durch systemd, Supervisor oder einen vergleichbaren Prozessmanager überwacht werden. Für einen einzelnen Erfassungsdurchlauf steht `--once` zur Verfügung.

## Bewertungsgrenzen

PenguOps bewertet nur tatsächlich verfügbare APIs und aktivierte Regeln. Ein hoher Score ist keine vollständige Sicherheits-, Backup- oder Verfügbarkeitsgarantie. Insbesondere werden Neustart-Schleifen oder Backup-Alter nur dann bewertet, wenn ein Connector diese Information zuverlässig liefert. Ein nicht erreichbares API beweist keinen Ausfall des dahinterliegenden Dienstes.

## Sicherheit

- KI-Schlüssel werden mit dem bestehenden PenguLab-Schlüsselspeicher verschlüsselt.
- Nicht-Administratoren sehen nur Integrationen, für die sie bereits berechtigt sind; Regeln und AI Model Hub sind Administratoren vorbehalten.
- Cloud-Anbieter benötigen HTTPS. Bei Änderung von Anbieter oder Basis-URL muss der Schlüssel erneut eingegeben werden.
- KI-Ausgaben sind Hinweise und Hypothesen. PenguOps führt keine vorgeschlagenen Aktionen aus.

## Upgrade-Hinweis

Das Update ist additiv. Es verändert bestehende Apps, Integrationen und Dashboard-Positionen nicht. Das Add-on legt seine Tabellen erst bei der Installation an. Vor jedem Update bleibt eine Sicherung des persistenten `data`-Verzeichnisses empfohlen.
