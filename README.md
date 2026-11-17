# Veiser KNX Raumtemperaturmanager – v0.4 Test

Eigene HTML-SDK-Kachel mit Temperaturrad, vier Betriebsarten und manueller Verstellung. Die bestehende Modul-ID und Variablen-Idents bleiben erhalten.

## Installation
Dateien aus dieser ZIP in das Stammverzeichnis des bestehenden GitHub-Repositories hochladen (library.json muss direkt im Stamm liegen). Anschließend das Modul in Symcon aktualisieren. Vorhandene Instanz beibehalten und Testmodus aktiv lassen.

## Achtung
- Nicht auf einer produktiven Heizungsanlage aktivieren, bevor KNX-Moduswerte und Sollwert-Gruppenadressen überprüft wurden.
- Rückmeldung ist derzeit die Sollwert-Rückmeldung, nicht zwingend die gemessene Raumtemperatur.
- Die Darstellung wurde statisch geprüft, aber nicht auf einer echten SymBox getestet.
- Bei der Aktualisierung kann ein erneutes Laden der Visualisierung nötig sein.
