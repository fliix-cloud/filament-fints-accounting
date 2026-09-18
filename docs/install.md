# Installation und Inbetriebnahme

Alle Befehle werden im Verzeichnis der **Laravel-Host-Anwendung** ausgeführt.
Das Paket ist keine eigenständig startbare Anwendung.

## Voraussetzungen

- PHP 8.3+, Composer, Laravel 13 und ein Filament-5-Panel mit Anmeldung.
- Die von Composer geforderten PHP-Erweiterungen, insbesondere `dom`,
  `fileinfo` und `mbstring`.
- Eine eingerichtete Laravel-Datenbank, HTTPS sowie Schreibrechte für `storage`
  und `bootstrap/cache`.
- Eine deutsche Firma je Anwendungsinstanz; für den unterstützten deutschen
  Buchungsfall EUR.
- Node.js/npm für den Frontend-Build des Hosts.

Falls noch kein Panel vorhanden ist:

```bash
composer require filament/filament:"~5.0"
php artisan filament:install --panels
```

**Freigabestatus:** Das Paket ist pre-release. Es gibt keinen zugesicherten
Upgrade-Pfad für jede frühere Entwicklungsdatenbank und keine Produktions- oder
GoBD-Freigabe. Eine erfolgreiche Installation ist keine Compliance-Freigabe.

## Paket und Datenbank installieren

Für den aktuellen Entwicklungsstand:

```bash
composer config repositories.filament-fints-accounting vcs https://github.com/fliix-cloud/filament-fints-accounting.git
composer require fliix-cloud/filament-fints-accounting:dev-main nemiah/php-fints:@dev
composer check-platform-reqs
php artisan migrate --force
php artisan filament-accounting:install --country=DE
```

Die explizite Host-Anforderung `nemiah/php-fints:@dev` erlaubt nur dieser
transitiven Entwicklungsabhängigkeit die benötigte Stabilität. Die Host-
`composer.lock` muss versioniert und beim Deployment mit `composer install`
verwendet werden. Laravel entdeckt den Service Provider automatisch; frühere
separate Accounting-/FinTS-Pakete nicht zusätzlich installieren.

In `.env` die üblichen Laravel-Werte (`APP_URL`, `DB_*`, `APP_KEY`) setzen. Einen
bestehenden Schlüssel nicht ersetzen. Belege müssen auf einem privaten Disk
außerhalb des Webroots liegen:

```dotenv
APP_ENV=production
APP_DEBUG=false
ACCOUNTING_COUNTRY=DE
ACCOUNTING_DISK=local
```

Für `ACCOUNTING_DISK` kann ein anderer privater Disk aus `config/filesystems.php`
verwendet werden. Für diese Einrichtung dieselbe Datenbankverbindung wie
Laravel verwenden und `ACCOUNTING_DB_CONNECTION` nicht setzen; die
Transaktionssicherheit separater Verbindungen ist noch nicht als allgemeine
Produktionskonfiguration belegt. Danach `php artisan config:clear` ausführen.

Der Installer veröffentlicht die Paketkonfiguration, legt aber keine Firma und
keine Benutzerrechte an. Er kann bei einer vorhandenen Firma das deutsche
Konten-/Steuerprofil vorbereiten. Lokal ist auch
`filament-accounting:install --migrate --country=DE` möglich.

## Panel, Theme und Funktionen

Im bestehenden Panel Provider registrieren:

```php
use FilamentAccounting\FilamentAccountingPlugin;

$panel->plugin(FilamentAccountingPlugin::make());
```

Das Plugin ist standardmäßig host-breit. Für die frühere volle Breite
`FilamentAccountingPlugin::make()->fullWidth()` verwenden. Für paketinterne
Tailwind-Klassen ein Filament-Theme einrichten und dort die Paketquellen
aufnehmen:

```css
@source '../../../../vendor/fliix-cloud/filament-fints-accounting/src/**/*.php';
@source '../../../../vendor/fliix-cloud/filament-fints-accounting/resources/views/**/*.blade.php';
```

Theme registrieren, Vite konfigurieren und anschließend `npm install` sowie
`npm run build` ausführen. Die Pfade bei einem anderen Theme-Verzeichnis
anpassen.

Eine Oberfläche wird nur registriert, wenn Konfiguration **und** Plugin-Schalter
aktiv sind. Relevante Schalter sind `dashboard`, `customers`, `suppliers`,
`catalog`, `sales_invoices`, `purchase_invoices`, `bank_reconciliation`,
`journal`, `chart_of_accounts`, `tax_and_posting_rules`, `settings` und `audit`.
`chart_of_accounts` und `audit` sind standardmäßig deaktiviert. `reports` ist
kein gültiger Funktionsschalter mehr; „Auswertungen“ ist nur eine
Navigationsgruppe. Schalter steuern die Panel-Registrierung, nicht Services,
Routen oder Berechtigungen.

## Benutzer und Firma

Das Paket liefert Berechtigungsnamen, aber keinen Rollen-Seed. Nicht definierte
Gates werden abgelehnt. Im Host müssen daher die Gates aus
`filament-accounting.authorization.abilities` oder eine eigene
`AccountingAuthorizer`-Implementierung eingerichtet werden. Jedes Gate muss
Benutzer, Rolle und aktuelle Firma prüfen; Panel-Login allein genügt nicht.

Danach:

1. Eine ausdrücklich berechtigte Person anmelden und die Firmeneinstellungen
   öffnen.
2. Firma, Anschrift, DE/EUR, Geschäftsjahr, Steuerdaten und Rechnungsdaten
   vollständig erfassen.
3. Falls die Firma außerhalb des Assistenten angelegt wurde,
   `php artisan filament-accounting:seed-profile DE` ausführen.
4. Kunden, Lieferanten und Testbelege in einer getrennten Testinstallation
   prüfen. Ein bestehender Demo-Seeder ist nur für lokale/testende Umgebungen
   bestimmt und legt keine produktiven Rechte an.

## FinTS (optional)

Vor einer Bankverbindung eine eigene registrierte Produkt-ID setzen:

```dotenv
FINTS_PRODUCT_ID=DEINE_REGISTRIERTE_PRODUKT_ID
```

```bash
php artisan config:clear
php artisan filament-accounting:sync-institutes
php artisan filament-accounting:sync-bank --connection=BANKVERBINDUNGS_UUID --accounts --balances --transactions
```

Bankzugang, TAN-Verfahren und SCA werden im Panel eingerichtet. Eine
Synchronisation beweist noch keine lückenlose Historie; die Reichweite und
Nachholgrenzen sind in [Operations](operations.md) beschrieben.

## Schema- und Release-Politik

Der aktuelle Stand ist pre-release. Es gibt noch keine unterstützte
Upgrade-Matrix oder persistente Installationsbaseline. Das Schema und die
öffentliche API können sich ändern; auch bereits ausgelieferte
Basis-Migrationen sind in der Entwicklungsphase nicht unveränderlich.

Für eine bestehende Entwicklungsinstallation:

1. Quell- und Ziel-Commit, Host-`composer.lock`, Datenbankversion und
   `php artisan migrate:status` dokumentieren.
2. Datenbank, private Originale/Artefakte, Audit-Anker und notwendige
   Verschlüsselungsschlüssel gemeinsam sichern und die Wiederherstellung isoliert
   prüfen.
3. Nur bei passendem Ausgangsschema den Update-Versuch auf der Sicherung mit
   `php artisan migrate`, `filament-accounting:verify` und fachlichen Prüfungen
   von Salden, Belegversionen, Dateien und Auditnachweisen durchführen.
4. Wenn eine bereits ausgeführte Basismigration geändert wurde, anhalten. Eine
   separat entworfene und getestete Migration/Backfill ist erforderlich; keine
   historische Audit-Evidenz erfinden.

`php artisan migrate:fresh --seed` ist ausschließlich für wegwerfbare
Entwicklungs- oder Demo-Datenbanken zulässig. Es löscht auch Hosttabellen und
ist kein Upgrade-Befehl. Rollbacks sind nicht generell verlustfrei; bei neuer
Belegevidenz können Versionierungs- und Zahlungssnapshot-Migrationen den
Rollback verweigern. Bei Releases werden die geprüfte Lock-Datei und
`composer install --no-dev --optimize-autoloader` verwendet, nicht ein
unkontrolliertes `composer update` auf dem Server.

Die erste 0.x-Freigabe muss eine Schema-Baseline, genaue Abhängigkeiten,
unterstützte Datenbanktreiber, Backfills, Rollback-Grenzen und verbleibende
Einschränkungen nennen. Ein 0.x-Tag ist weder Produktions- noch
Compliance-Freigabe. `nemiah/php-fints:dev-master` bleibt ein dokumentiertes
Entwicklungsrisiko; vor einer Produktionsbehauptung ist eine kompatible,
getaggte Protokollversion zu evaluieren.

## Betrieb nach der Installation

Für Produktion Datenbank, private Belege, Audit-Anker und `APP_KEY` gemeinsam
sichern, Wiederherstellung testen, Queue-Worker und Scheduler überwachen und
regelmäßig ausführen:

```bash
php artisan filament-accounting:cleanup-sca
php artisan filament-accounting:verify --json
```

Audit-Anker erst nach unabhängig geprüfter Speicherhärtung aktivieren. Siehe
[Operations](operations.md) und [GoBD readiness](gobd.md).
