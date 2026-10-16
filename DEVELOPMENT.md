# Lokale Entwicklung

Das Fachkraefteportal verwendet Laravel 13 und Filament 5. PHP und Composer
liegen portabel im benachbarten Workspace-Ordner `.dev-tools`; Node und npm
sind bereits auf dem Rechner installiert. Der globale PATH wird nicht geaendert.
Die aufgeloesten Anwendungsversionen stehen in `composer.lock` und `package-lock.json`.

## Starten

Im Projektordner:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1
```

Der Befehl startet den lokalen Webserver, den Queue-Worker, den Scheduler und Vite zusammen.
Mit `Strg+C` werden die Entwicklungsprozesse beendet.

- Portal: http://127.0.0.1:8000/admin
- Lokaler Entwicklungszugang: `dev@example.test`
- Das generierte Passwort steht ausschliesslich in der ignorierten `.env`
  unter `DEV_ADMIN_PASSWORD`.

Die interne Verwaltung steht unter `/admin`, der Einrichtungsbereich unter
`/portal`. Fachkraefte registrieren sich unter `/portal/register`; ihre
Zugangsseite ist `/zugang`. Der lokale Entwicklungsaccount ist Globaladmin.
Einrichtungen, Mitgliedschaften, Beitrittsantraege und persoenliche Einladungen
sind eingerichtet. Weltantraege waehlen veroeffentlichte Weltvorlagen; genehmigte
Antraege erhalten private Spielkopien. Der Scheduler bereitet Kopien vor und
gleicht ihre genehmigten Laufzeiten mit der Bridge ab.

Die neue Vorlagen-/Instanzstruktur benoetigt bei bestehenden Entwicklungsdaten
ein bewusstes Zuruecksetzen der bisherigen Weltantraege und Accountzuordnungen.
Zuerst die lokale SQLite-Datei sichern, dann `luanti:worlds:reset-development
--force` und `migrate` ueber `dev.ps1 artisan` ausfuehren. Benutzer, Einrichtungen
und Serverkonfiguration bleiben erhalten. Server-Spielstaende werden nicht
geloescht oder automatisch zu Vorlagen gemacht.

Die Bridge benoetigt ihren aktualisierten installierten Controller und die
aktualisierte HTTPS-API. Veroeffentlichung sauberer Vorlagen und Operator-Upgrade
stehen in der benachbarten Bridge unter `docs/HTTPS_API.md`. Produktiv muss
Laravel `schedule:run` einmal pro Minute ausgefuehrt werden; lokal uebernimmt
der mit `dev.ps1` gestartete Scheduler diese Aufgabe.

## Befehle

```powershell
# Tests
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 test

# Frontend bauen
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 build

# Artisan und Composer verwenden
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 artisan migrate
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 composer check-platform-reqs

# Ersten Globaladmin ausserhalb der lokalen Entwicklung interaktiv anlegen
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 artisan admin:create

# Lokalen Entwicklungsaccount nach einem Datenbank-Neustart wieder anlegen
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 artisan db:seed
```

Der Seeder erzeugt den Entwicklungsaccount nur in `APP_ENV=local`, weist ihm
die Globaladminrolle zu und ueberschreibt kein bestehendes Passwort.
`.env.example` enthaelt kein Passwort.

Einladungen und E-Mail-Bestaetigungen gehen lokal an den Log-Mailer; die
Bestaetigungen werden vom Queue-Worker verarbeitet. Einladungslinks im Log
sind persoenliche Testzugangslinks. Einladungen laufen nach sieben Tagen ab.

## Lokale Konfiguration

- SQLite: `database/database.sqlite`
- Queue, Sessions und Cache: Datenbank
- Oberflaeche: Deutsch; Zeitzone: Europe/Berlin
- Mail: lokales Log, kein Mailversand
- Webserver und Vite: an `127.0.0.1` gebunden

`.env`, SQLite-Dateien, lokale Logs, Zugangsdaten, `vendor`, `node_modules`
und Build-Ausgaben gehoeren nicht ins Repository. Die Lockdateien gehoeren
zum Quellcode. Die vorhandene SSH-Bridge ist ein eigenstaendiges Projekt.

Vor einem Deployment einen echten Mailtransport, HTTPS, einen stabilen
Anwendungsschluessel und einen dauerhaft laufenden Queue-Worker konfigurieren.
Der erste produktive Globaladmin wird ueber `admin:create` angelegt. Die
Zugriffsregeln gelten in allen Umgebungen, auch lokal; es gibt keinen
allgemeinen lokalen Adminzugriff mehr.

## Eigenes Git-Repository

`luanti-portal` ist ein eigenstaendiges Repository auf dem Branch `main`.
Die Bridge, der uebergeordnete VS-Code-Workspace und `.dev-tools` liegen
ausserhalb dieses Repositories.

Versioniert werden die Laravel-Projektdateien, unser Anwendungscode,
Tests, Dokumentation, `.env.example`, `composer.json`, `composer.lock`,
`package.json` und `package-lock.json`. Laravel, Filament und die anderen
heruntergeladenen Pakete in `vendor` und `node_modules` werden ignoriert.
Sie lassen sich aus den Lockdateien mit `composer install` und
`npm ci --ignore-scripts` wiederherstellen.

Auch die veroeffentlichten Filament-Dateien, Frontend-Builds, `.env`-Varianten,
SQLite-Datenbanken und der Laufzeitinhalt von `storage` bleiben lokal.
Die leeren Verzeichnisse werden ueber ihre `.gitignore`-Dateien erhalten.
