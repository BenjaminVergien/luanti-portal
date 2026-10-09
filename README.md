# Luanti Portal

Lokale Entwicklungsbasis fuer ein Fachkraefteportal mit Laravel 13 und
Filament 5. Das Portal soll die Verwaltung von Einrichtungen, Freigaben und
Luanti-Zugaengen ermoeglichen. Registrierung, E-Mail-Bestaetigung, Einrichtungen,
Mitgliedschaften, Beitrittsantraege und persoenliche Einladungen sind umgesetzt.
Serververwaltung, Weltantraege und die Verbindung zur eigenstaendigen
Luanti-Bridge folgen noch.

## Bestehende lokale Einrichtung

Die portable Windows-Toolchain und die Befehle fuer diesen Workspace stehen in
[DEVELOPMENT.md](DEVELOPMENT.md). Das Panel ist unter
http://127.0.0.1:8000/admin erreichbar.

## Einrichtung nach einem Clone

Voraussetzung sind PHP und Composer sowie Node.js und npm. Die konkreten
Paketversionen sind in `composer.lock` und `package-lock.json` festgeschrieben;
`composer check-platform-reqs` prueft PHP und die benoetigten Erweiterungen.
Die bestehende Windows-Einrichtung verwendet PHP 8.5.

Mit einer bereits installierten Toolchain in PowerShell:

```powershell
git clone https://github.com/Stewie23/luanti-portal.git
Set-Location luanti-portal
Copy-Item .env.example .env
composer install
composer check-platform-reqs
npm ci --ignore-scripts
php artisan key:generate
New-Item -ItemType File -Path database/database.sqlite
php artisan migrate
```

In der lokalen `.env` ein eigenes `DEV_ADMIN_PASSWORD` mit mindestens
16 Zeichen eintragen. Danach den lokalen Entwicklungsaccount anlegen und
die Entwicklungsprozesse starten:

```powershell
php artisan db:seed
composer run dev
```

Der Account verwendet standardmaessig `dev@example.test`. Der Seeder legt ihn
nur in `APP_ENV=local` an und behaelt das Passwort eines bestehenden Accounts.
Der Entwicklungsaccount erhaelt explizit die Globaladminrolle.

## Zugangsverwaltung

- `/admin`: interne Verwaltung fuer Globaladmins und Sachbearbeitung.
- `/portal/register`: Selbstregistrierung als Fachkraft ohne Mitgliedschaften.
- `/zugang`: eigene Mitgliedschaften, Einrichtungssuche, Beitrittsantraege und
  Annahme persoenlicher Einladungen; erfordert eine bestaetigte E-Mail-Adresse.
- `/portal`: Einrichtungsbereich mit Wechsel zwischen eigenen Einrichtungen.

Globaladmins legen Einrichtungen an, sperren Konten und laden zu internen
Rollen ein. Sachbearbeitung verwaltet Mitgliedschaften aller Einrichtungen.
Einrichtungsverwaltungen bearbeiten den Einrichtungsnamen, entscheiden ueber
Beitrittsantraege und verwalten Fachkraftmitgliedschaften ihrer Einrichtung.
Verwaltungsmitgliedschaften vergeben nur die internen Rollen.

Einladungen sind sieben Tage gueltig, einmal verwendbar und an die bestaetigte
E-Mail-Adresse gebunden. Beim Annehmen werden die aktuellen Rechte der
einladenden Person erneut geprueft. Mitgliedschaften gelten je Einrichtung.
Das Entfernen einer Mitgliedschaft veraendert andere Einrichtungen nicht.

Den ersten produktiven Globaladmin richtet `php artisan admin:create`
interaktiv ein. Der Befehl nimmt keine Passwortargumente entgegen und ersetzt
kein bestehendes Passwort. Sobald ein aktiver Globaladmin existiert, werden
weitere interne Rollen ausschliesslich per Einladung vergeben.

Lokal schreibt der Mailer Einladungen ins Log. E-Mail-Bestaetigungen laufen
ueber die Queue; `composer run dev` startet den Worker mit. Vor externer Nutzung
einen echten Mailtransport und einen dauerhaft laufenden Queue-Worker
konfigurieren.

## Pruefen und bauen

```powershell
composer run test
npm run build
```

## Repository-Inhalt

Versioniert werden Anwendungscode, Projektkonfiguration, Tests, Dokumentation,
Lockdateien und die leere Konfigurationsvorlage `.env.example`.
Abhaengigkeiten in `vendor` und `node_modules`, Build-Ausgaben, echte `.env`-
Dateien, Datenbanken und Laufzeitdaten bleiben lokal. Die portable Toolchain,
der VS-Code-Workspace und die Bridge liegen ausserhalb dieses Repositories.
