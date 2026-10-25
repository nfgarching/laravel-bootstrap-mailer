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
- **Versandprotokoll** in `nf_sent_mails`

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
MAIL_PORT=587
MAIL_ENCRYPTION=tls
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
```

`status`: `sent`, `queued`, `failed` (mit `error`).

## 6. Hinweise

- **Queue**: Bei `NFMAILER_QUEUE=true` muss ein Worker laufen
  (`php artisan queue:work`, im Docker-Setup als eigener Container).
  Der Status bleibt dann `queued`.
- **Download-Route** `/nfmailer/d/{token}` ist öffentlich (Empfänger haben kein
  Login), geschützt durch 48-stelliges Zufallstoken, Ablaufdatum und Rate-Limit.
  Die App muss dafür von außen erreichbar sein (`APP_URL` korrekt setzen).
- **Hochgeladene Dateien** landen in `storage/app/nfmailer/uploads` und werden nicht
  automatisch gelöscht.
- Views anpassen: `php artisan vendor:publish --tag=nfmailer-views`.
