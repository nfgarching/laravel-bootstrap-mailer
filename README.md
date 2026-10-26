# nfgarching/mailer

E-Mail-Versand mit komfortablem Empfänger-Modal (Livewire 3, Bootstrap 5.3).

- **An / CC / BCC** als Chip-Felder; das aktive Feld ist blau umrandet
- **Beteiligte der Akte** erscheinen sofort, ohne Suche
- **Suche** über alle konfigurierten Quellen (Adressbuch, Mandanten …)
- **Mehrfachauswahl** per Checkbox, „alle markieren“ je Gruppe, dann → An/CC/BCC
- **Freie Eingabe**: Enter, Komma oder Semikolon; ganze Listen einfügen
  (`Müller, Hans <h@x.de>; info@y.de`), Backspace löscht den letzten Chip
- Chip-Menü: in anderes Feld verschieben; eine Adresse steht nie doppelt
- **Anhänge** (übergeben oder hochgeladen) oder wahlweise **Download-Links** mit Ablaufdatum und Abrufzähler
- **Versandprotokoll** in `sent_mails` (Download-Links in `sent_mails_links`), verschlüsselt gespeichert
- **Sicherheit**: TLS-Pflicht, S/MIME-Signatur und -Verschlüsselung, Download-Links mit PIN – siehe [7. Sicherheit](#7-sicherheit)

---

## 1. Installation

`composer.json` der App:

```json
"repositories": [
    { "type": "path", "url": "packages/nfgarching/mailer" }
],
"require": {
    "nfgarching/mailer": "*"
}
```

```bash
composer update nfgarching/mailer
php artisan vendor:publish --tag=nfmailer-config
php artisan vendor:publish --tag=nfmailer-stubs     # Beispielquelle Akte → app/Mail/Recipients
php artisan migrate
```

**Bootstrap-JS global verfügbar machen** (das Modal wird per `bootstrap.Modal` geöffnet).
Wenn Bootstrap über Vite eingebunden ist, in `resources/js/app.js`:

```js
import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;
```

Bei Einbindung über das CDN-Bundle ist `window.bootstrap` bereits gesetzt.

**Mailversand** in `.env` (Google Workspace):

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=kanzlei@example.de
MAIL_PASSWORD=<App-Passwort>
MAIL_FROM_ADDRESS=kanzlei@example.de
MAIL_FROM_NAME="Kanzlei Muster"

NFMAILER_QUEUE=false
NFMAILER_SIGNATURE="Kanzlei Muster\nMusterstraße 1\n12345 Musterstadt"
```

`\n` in der Signatur wird als Zeilenumbruch übernommen.

Für Google ist ein **App-Passwort** nötig (2-Faktor muss aktiv sein).
Alternativ SMTP-Relay `smtp-relay.gmail.com` mit IP-Freigabe in der Admin-Konsole.

## 2. Komponente einbinden

Einmal im Layout, z. B. vor `</body>`:

```blade
<livewire:nf-mail-composer />
```

## 3. Modal öffnen

**Aus Blade (Button in der Akte):**

```blade
<button type="button" class="btn btn-outline-primary"
        x-data
        x-on:click="Livewire.dispatch('nfmailer-open', { options: @js([
            'context' => ['akte' => $akte->id],
            'subject' => "Ihre Angelegenheit – Az. {$akte->aktenzeichen}",
        ]) })">
    E-Mail senden
</button>
```

**Direkt an einen ausgewählten Beteiligten:**

```blade
x-on:click="Livewire.dispatch('nfmailer-open', { options: @js([
    'context' => ['akte' => $akte->id],
    'to'      => [['email' => $beteiligter->email, 'name' => $beteiligter->name]],
    'cc'      => ['sekretariat@example.de'],
]) })"
```

**Aus einer Livewire-Komponente** (z. B. claims oder textgenerator):

```php
$this->dispatch('nfmailer-open', options: [
    'context'     => ['akte' => $this->akte->id],
    'to'          => [$mandant->email],
    'subject'     => 'Forderungsaufstellung',
    'body'        => $text,                  // z. B. aus dem Textgenerator
    'attachments' => [
        ['disk' => 'local', 'path' => "akten/{$id}/forderung.pdf", 'name' => 'Forderungsaufstellung.pdf'],
    ],
    'links'       => true,                   // Download-Link statt Anhang vorwählen
]);
```

Alle Optionen:

| Option        | Typ                                       | Bedeutung |
|---------------|-------------------------------------------|-----------|
| `context`     | array                                     | Wird an alle Quellen gereicht und im Protokoll gespeichert |
| `to`/`cc`/`bcc` | Liste aus `"a@b.de"`, `"Name <a@b.de>"` oder `['email','name']` | Vorbelegung |
| `subject`     | string                                    | Betreff |
| `body`        | string                                    | Text (wird als Klartext mit Zeilenumbrüchen versendet) |
| `attachments` | Liste aus `['disk','path','name']`        | Nur Disks/Pfade aus `config('nfmailer.attachments')` |
| `links`       | bool                                      | Schalter „Download-Link statt Anhang“ vorbelegen |
| `pin`         | bool                                      | „Download mit PIN schützen“ vorbelegen (Standard: `links.pin_default`) |
| `encrypt`     | bool                                      | S/MIME-Verschlüsselung, falls möglich (Standard: `true`) |

Nach erfolgreichem Versand feuert die Komponente `nfmailer-sent` mit der Protokoll-ID:

```php
#[On('nfmailer-sent')]
public function mailSent(int $id) { /* z. B. Aktenvermerk anlegen */ }
```

## 4. Adressquellen

In `config/nfmailer.php` unter `sources`. Jede Quelle implementiert
`Nfgarching\Mailer\Contracts\RecipientSource` mit drei Methoden:

- `label()` – Gruppenüberschrift
- `suggestions($context)` – sofort sichtbare Einträge (z. B. Beteiligte der Akte)
- `search($term, $context, $limit)` – Suchtreffer

**Generisches Model** (ohne eigene Klasse):

```php
[
    'class'  => ModelRecipientSource::class,
    'label'  => 'Mandanten',
    'model'  => App\Models\Mandant::class,
    'email'  => ['email', 'email_privat'],   // eine oder mehrere Spalten
    'name'   => ['vorname', 'nachname'],
    'search' => ['vorname', 'nachname', 'firma', 'email'],
    'hint'   => 'firma',
],
```

**Beteiligte der Akte**: `stubs/AkteBeteiligteSource.php` (nach dem Publizieren in
`app/Mail/Recipients/`) an das eigene Schema anpassen und in `sources` an erster
Stelle eintragen. Die Beispielklasse prüft per `Gate::allows('view', $akte)`, ob
der Nutzer die Akte sehen darf – da der Kontext vom Browser kommt, sollte jede
akten­bezogene Quelle so eine Prüfung haben.

Treffer mehrerer Quellen werden nach E-Mail zusammengeführt; die zuerst
eingetragene Quelle gewinnt (Name/Hinweis).

## 5. Protokoll

```php
use Nfgarching\Mailer\Models\SentMail;

SentMail::forContext(['akte' => $akte->id])->latest()->get();
$mail->links;   // Download-Links mit download_count / last_downloaded_at
$mail->pin;     // PIN der Download-Links (falls vergeben)
$mail->signed;  $mail->encrypted;
```

`status`: `sent`, `queued`, `failed` (mit `error`).

Betreff, Text, Empfänger, Anhangsliste und PIN liegen **verschlüsselt** in der
Datenbank (Laravel-Cast `encrypted`, Schlüssel = `APP_KEY`). Deshalb ist keine
SQL-Suche über diese Felder möglich – gefiltert wird über `context_key`.

## 6. Hinweise

- **Queue**: Bei `NFMAILER_QUEUE=true` muss ein Worker laufen
  (`php artisan queue:work`, im Docker-Setup als eigener Container).
  Der Status bleibt dann `queued`.
- **Download-Route** `/nfmailer/d/{token}` ist öffentlich (Empfänger haben kein
  Login). Die App muss dafür von außen per HTTPS erreichbar sein (`APP_URL`).
- **Hochgeladene Dateien** landen in `storage/app/nfmailer/uploads`; löschen
  nur über `nfmailer:prune` mit gesetztem `prune.uploads_after_days`.
- Views anpassen: `php artisan vendor:publish --tag=nfmailer-views`.

## 7. Sicherheit

Nach dem Update einmal migrieren – die Migration verschlüsselt vorhandene
Protokolleinträge und stellt bestehende Links auf gehashte Tokens um (bereits
versandte Links bleiben gültig):

```bash
php artisan migrate
php artisan nfmailer:check
```

`nfmailer:check` prüft TLS, S/MIME-Zertifikat und Schlüssel (inkl.
Probe-Signatur), `APP_URL`, Link-Einstellungen sowie SPF, DKIM und DMARC der
Absenderdomain (`--dkim-selector=google` für Google Workspace).

### Mailer „nfmailer“

Das Package versendet immer über den Mailer `nfmailer`, den es selbst
registriert. Er umhüllt den eigentlichen Versandweg (`NFMAILER_MAILER`, sonst
`MAIL_MAILER`) und

- **erzwingt TLS**: Bietet der Server kein STARTTLS an, wird nicht versendet
  (`NFMAILER_REQUIRE_TLS=true`, Standard). Mit Port 465/`smtps` ist die
  Verbindung von Anfang an verschlüsselt. Für lokale Test-Postfächer wie
  Mailpit: `NFMAILER_REQUIRE_TLS=false`.
- **signiert** jede Mail per S/MIME, sobald Zertifikat und Schlüssel gesetzt sind,
- **verschlüsselt** per S/MIME, wenn im Modal gewählt.

Der Mailer kann auch für eigene Mails der App verwendet werden:
`Mail::mailer('nfmailer')->send(...)`.

### S/MIME-Signatur

Benötigt ein S/MIME-Zertifikat für die Absenderadresse (z. B. D-Trust, Sectigo,
Certum). Meist kommt es als `.p12`/`.pfx`; daraus PEM-Dateien erzeugen:

```bash
mkdir -p /var/secure/smime && cd /var/secure/smime
openssl pkcs12 -in kanzlei.p12 -clcerts -nokeys -out kanzlei.crt
openssl pkcs12 -in kanzlei.p12 -nocerts -out kanzlei.key        # mit Passphrase
openssl pkcs12 -in kanzlei.p12 -cacerts -nokeys -out chain.crt
chmod 600 kanzlei.key
```

```env
NFMAILER_SMIME_CERT=/var/secure/smime/kanzlei.crt
NFMAILER_SMIME_KEY=/var/secure/smime/kanzlei.key
NFMAILER_SMIME_PASSPHRASE=…
NFMAILER_SMIME_CHAIN=/var/secure/smime/chain.crt
```

Den Schlüssel **nicht** im Projektverzeichnis oder im Git ablegen. Im
Docker-Setup als Volume bzw. Secret einbinden.

### S/MIME-Verschlüsselung

Funktioniert nur, wenn für **alle** Empfänger (An, CC, BCC) ein Zertifikat
hinterlegt ist. Am einfachsten: den Empfänger um **eine signierte E-Mail**
bitten, diese als `.eml` speichern (Outlook: Datei → Speichern unter; Gmail:
„Original anzeigen“ → herunterladen; Thunderbird: Speichern als) und importieren:

```bash
php artisan nfmailer:cert import antwort-mandant.eml   # signierte Mail
php artisan nfmailer:cert import mandant.cer           # oder .crt/.pem/.p7s/.p7b
php artisan nfmailer:cert list
php artisan nfmailer:cert show mandant@example.de      # inkl. SHA-256-Fingerabdruck
php artisan nfmailer:cert remove mandant@example.de
```

Beim Import einer Mail wird deren Signatur geprüft: Wurde sie verändert, wird
nichts übernommen. Enthält die Datei eine ganze Kette, werden nur Zertifikate
mit E-Mail-Adresse übernommen (keine CA-Zertifikate); abgelaufene werden
abgelehnt. Mit `--email=…` lässt sich der Import auf eine Adresse beschränken.

Bei sensiblen Mandaten zusätzlich den angezeigten SHA-256-Fingerabdruck kurz
telefonisch mit dem Empfänger abgleichen.

### Prüfung bei der Zertifizierungsstelle

Beim Import und regelmäßig danach wird jedes Empfänger-Zertifikat geprüft:

- **Vertrauenskette** bis zu einem Wurzelzertifikat aus dem System-Zertifikatsspeicher
  oder aus `storage/app/private/nfmailer/roots`. Fehlende Zwischenzertifikate werden
  aus der Mail genommen oder über die im Zertifikat genannte „CA Issuers“-Adresse geladen.
- **Sperrstatus per OCSP** beim Aussteller. Gesperrte Zertifikate werden beim Import
  abgelehnt und nie zum Verschlüsseln verwendet.

```bash
php artisan nfmailer:cert verify                     # alle neu prüfen
php artisan nfmailer:cert verify mandant@example.de
php artisan nfmailer:cert trust wurzel.pem           # Wurzel einer CA hinterlegen
php artisan nfmailer:check --verify                  # Gesamtprüfung inkl. Nachprüfung
```

Vor jedem verschlüsselten Versand wird der Sperrstatus erneut abgefragt, wenn die
letzte Prüfung älter als 24 Stunden ist (`smime.trust.check_before_send`,
`cache_hours`). Ist ein Zertifikat inzwischen gesperrt, wird die Mail nicht
versendet. Ist der OCSP-Dienst nicht erreichbar, gilt der Status als „unbekannt“
und das Zertifikat bleibt verwendbar.

Einstellungen in `smime.trust`:

| Schlüssel | Bedeutung |
|-----------|-----------|
| `chain` (`NFMAILER_SMIME_CHAIN_POLICY`) | `warn` (Standard): Zertifikate ohne vertrauenswürdige Kette werden übernommen und als „Kette ?“ markiert · `require`: nur Zertifikate einer vertrauenswürdigen CA · `off` |
| `ocsp` (`NFMAILER_SMIME_OCSP`) | Sperrprüfung an/aus |
| `roots` | Verzeichnis für zusätzliche Wurzelzertifikate |
| `openssl` (`NFMAILER_OPENSSL`) | Pfad zum `openssl`-Befehl |

Voraussetzungen: der Befehl `openssl` (im Dockerfile ggf. `apt-get install -y openssl`)
und ausgehender HTTP-Zugang zu den OCSP-Diensten der CAs. Wurzelzertifikate von
S/MIME-CAs fehlen manchmal im System-Speicher; dann zeigt der Import „Kette ?“.
In dem Fall die Wurzel von der Website der CA laden, den Fingerabdruck dort
abgleichen und mit `nfmailer:cert trust` hinterlegen.

Im Modal zeigt ein Schloss an jedem Empfänger, ob ein Zertifikat vorliegt; der
Schalter „Verschlüsseln“ ist nur aktiv, wenn alle eins haben. Fehlt beim
Versand doch ein Zertifikat, wird **nicht** unverschlüsselt gesendet, sondern
abgebrochen. Die Mail wird zusätzlich für das eigene Zertifikat verschlüsselt,
damit die Kopie unter „Gesendet“ lesbar bleibt.

**Grenze:** Der **Betreff** wird bei S/MIME nicht verschlüsselt – keine
vertraulichen Angaben (Namen, Diagnosen, Aktenthemen) in den Betreff.

### Download-Links

- Token nur als SHA-256-Hash gespeichert
- Aufruf zeigt erst eine Seite mit „Herunterladen“-Knopf: Virenscanner und
  Link-Vorschauen (z. B. Outlook Safe Links) verbrauchen keine Abrufe
- **PIN** (6 Ziffern, im Modal vorbelegt): wird nach dem Versand angezeigt und
  ist im Protokoll abrufbar; optional per separater E-Mail. Nach
  `links.pin_attempts` Fehlversuchen wird der Link gesperrt
- optional `links.max_downloads`; Sperren per `$link->revoke()`
- Benachrichtigung an den Absender beim ersten Abruf (`links.notify_on_download`)

### Aufräumen

```php
// routes/console.php
Schedule::command('nfmailer:prune')->daily();
```

Entfernt Links `prune.links_after_days` Tage nach Ablauf; hochgeladene Dateien
nur, wenn `prune.uploads_after_days` gesetzt ist (Standard: behalten, als
Nachweis des Versands).

### Wichtig: APP_KEY

Ohne `APP_KEY` ist das Protokoll nicht mehr lesbar. Den Schlüssel getrennt von
den Datenbank-Backups sichern. Beim Schlüsselwechsel den alten Schlüssel in
`APP_PREVIOUS_KEYS` eintragen.
