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

Der Befehl startet den lokalen Webserver, den Queue-Worker und Vite zusammen.
Mit `Strg+C` werden die Entwicklungsprozesse beendet.

- Portal: http://127.0.0.1:8000/admin
- Lokaler Entwicklungszugang: `dev@example.test`
- Das generierte Passwort steht ausschliesslich in der ignorierten `.env`
  unter `DEV_ADMIN_PASSWORD`.

Filament stellt zunaechst das Standardpanel bereit. Antraege, Freigaben,
Einrichtungen, Weltverwaltung und die Luanti-Anbindung werden anschliessend
entwickelt. Es gibt noch kein produktives Rollenmodell.

## Befehle

```powershell
# Tests
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 test

# Frontend bauen
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 build

# Artisan und Composer verwenden
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 artisan migrate
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 composer check-platform-reqs

# Lokalen Entwicklungsaccount nach einem Datenbank-Neustart wieder anlegen
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 artisan db:seed
```

Der Seeder erzeugt den Entwicklungsaccount nur in `APP_ENV=local` und
ueberschreibt kein bestehendes Passwort. `.env.example` enthaelt kein Passwort.

## Lokale Konfiguration

- SQLite: `database/database.sqlite`
- Queue, Sessions und Cache: Datenbank
- Oberflaeche: Deutsch; Zeitzone: Europe/Berlin
- Mail: lokales Log, kein Mailversand
- Webserver und Vite: an `127.0.0.1` gebunden

`.env`, SQLite-Dateien, lokale Logs, Zugangsdaten, `vendor`, `node_modules`
und Build-Ausgaben gehoeren nicht ins Repository. Die Lockdateien gehoeren
zum Quellcode. Die vorhandene SSH-Bridge ist ein eigenstaendiges Projekt.

Vor einem spaeteren Deployment braucht das Portal insbesondere explizite
Zugriffsregeln fuer Fachkraefte, Einrichtungen und Administration. Die aktuelle
Einrichtung ist eine lokale Entwicklungsbasis.

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
