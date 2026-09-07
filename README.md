# Kickertool Ergebnis-Export

Lädt Turnierergebnisse von **live.kickertool3.de** im **Sport-XML-Format** (DTFB-Standard)
herunter — als ZIP-Download im Browser oder vollautomatisch per E-Mail über einen Cronjob.

Gedacht für Vereine und Verbände, die die Ergebnisse ihrer Turniere regelmäßig weiterreichen
oder archivieren müssen und das nicht jedes Mal von Hand machen wollen.

Die komplette Anwendung ist **eine einzige PHP-Datei** — kein Composer, keine Datenbank,
kein Framework. Hochladen, aufrufen, fertig.

---

## TL;DR — in 5 Minuten online

1. **`index.php` auf den Webspace hochladen**, z. B. nach `/httpdocs/kickertool/`.
   Sonst nichts — keine Installation, keine Datenbank, kein Composer.
2. **Im Browser aufrufen**: `https://www.deinverein.de/kickertool/`
3. Reiter **Einstellungen** → Feld *Vereine* → die Kickertool-Adresse des Vereins eintragen
   (`https://live.kickertool3.de/deinverein`) → **Speichern**.
4. Reiter **Turniere** → gewünschte Turniere anhaken → **Als ZIP herunterladen**. Fertig.
5. **Danach**: Verzeichnisschutz drüberlegen (im Hosting-Panel), sonst kann jeder die Seite
   aufrufen. → [Schritt 4](#4-zugriff-schützen)

### Fehler beim ersten Aufruf? Fast immer die Schreibrechte

Sieht die Seite so aus, darf PHP im Zielverzeichnis nichts anlegen:

```text
Warning: mkdir(): Permission denied in .../index.php on line 19
Warning: file_put_contents(.../data/.htaccess): Failed to open stream: Permission denied
```

Die App will beim ersten Aufruf den Ordner `data/` neben der `index.php` erzeugen — dafür
braucht das Verzeichnis Schreibrechte für den Benutzer, unter dem PHP läuft. Welcher der
beiden folgenden Fälle vorliegt, verrät ein Blick auf den Ordner:

```bash
ls -ld /pfad/zu/kickertool
```

**Fall A — Shared Hosting: der Ordner gehört dir, die Rechte sind zu eng**
(z. B. `drwxr-xr-x 2 meinlogin meinlogin`, aber PHP läuft unter demselben Konto)

Rechte erweitern. Im FTP-Programm (FileZilla, WinSCP, Datei-Manager des Hosters) rechtsklick
auf den Ordner → *Dateiberechtigungen* / *CHMOD* → **755**. Auf der Kommandozeile:

```bash
chmod 755 /pfad/zu/kickertool
```

Hilft das nicht, läuft PHP unter einem anderen Benutzer als dein FTP-Konto — dann **775**,
und erst als letzter Ausweg **777**. Bei 777 darf jeder Benutzer auf dem Server
hineinschreiben; auf einem Shared Host also unbedingt vorher sicherstellen, dass `data/`
nicht per HTTP erreichbar ist (siehe [Sicherheit](#sicherheit)).

**Fall B — eigener Server oder Docker: der Ordner gehört dem falschen Benutzer**
(z. B. `drwxr-xr-x 2 root root` — die Rechte sind längst 755, es hilft trotzdem nichts)

Hier ist nicht der Modus das Problem, sondern der Eigentümer: `r-x` erlaubt PHP nur zu lesen.
Also den Ordner dem PHP-Benutzer übereignen statt die Rechte aufzuweichen. Dessen ID zuerst
ermitteln — bei den offiziellen `php:*-fpm`-Images ist es `www-data` mit UID 33:

```bash
docker exec <php-container> id www-data     # z.B. uid=33(www-data) gid=33(www-data)
```

Ohne Docker zeigt `ps aux | grep -E 'php-fpm|apache2|nginx'`, unter wem der Prozess läuft.
Dann auf dem Host (bei Docker-Bind-Mounts gelten die Rechte auf beiden Seiten, der Befehl
gehört also **nicht** in den Container):

```bash
chown -R 33:33 /pfad/zu/kickertool
ls -ld /pfad/zu/kickertool          # erwartet: drwxr-xr-x ... www-data www-data
```

Danach die Seite einfach neu laden — der Rest passiert von selbst.

> **nginx-Nutzer aufgepasst**: nginx wertet keine `.htaccess` aus. Die Schutzdatei, die die App
> in `data/` anlegt, ist dort wirkungslos und das Verzeichnis wäre über
> `https://.../data/config.json` abrufbar. Vor dem Eintragen echter Zugangsdaten unbedingt den
> Abschnitt [Sicherheit](#sicherheit) lesen und die `location`-Regel setzen.

Voraussetzung ist **PHP ≥ 8.2** mit `curl` und `zip` — bei den allermeisten Hostern ohnehin da.

E-Mail-Versand und der wöchentliche Cronjob sind optional und brauchen etwas mehr Einrichtung;
beides ist weiter unten beschrieben.

---

## Funktionen

- **Turnierübersicht** über beliebig viele Vereine hinweg, nach Datum sortiert
- **Drei Kategorien**: `Eingeschlossen` (passt zum Filter, noch nicht exportiert) ·
  `Exportiert` (schon heruntergeladen) · `Gefiltert` (passt nicht zum Filter oder noch nicht gespielt)
- **Filter** nach Turniername (Textbestandteil) und Startdatum
- **Export-Verlauf**: das Tool merkt sich, welche Turniere schon raus sind — einzeln oder
  komplett zurücksetzbar
- **ZIP-Download** der ausgewählten Turniere direkt im Browser
- **E-Mail-Versand** des ZIPs an eine feste Adresse (eigener SMTP-Client, kein `mail()` nötig)
- **Cron-Endpoint**: schickt einmal pro Woche automatisch alle neuen abgeschlossenen Turniere

---

## Voraussetzungen

| Anforderung | Details |
|---|---|
| PHP | **≥ 8.2** (die App nutzt DNF-Rückgabetypen wie `true\|string`) |
| PHP-Erweiterungen | `curl`, `zip` — auf praktisch jedem Hoster Standard |
| Webspace | Irgendein Apache/nginx mit PHP. Shared Hosting reicht völlig. |
| Optional | Cronjob-Funktion des Hosters für den automatischen Versand |

Prüfen lässt sich das mit einer kleinen Datei `info.php` (`<?php phpinfo();`) auf dem Server —
**nach dem Prüfen wieder löschen**.

---

## Installation in 4 Schritten

### 1. Datei hochladen

`index.php` per FTP oder Datei-Manager in ein Verzeichnis auf dem Webspace legen, z. B.:

```text
/httpdocs/kickertool/index.php
```

Mehr braucht es nicht. Der Ordner `data/` mit Konfiguration, Verlauf und Cron-Token wird beim
ersten Aufruf automatisch angelegt.

### 2. Seite aufrufen

```text
https://www.deinverein.de/kickertool/
```

Es öffnet sich die Oberfläche mit den drei Reitern **Turniere**, **Einstellungen** und
**Automatisierung**.

### 3. Vereine eintragen

Reiter **Einstellungen** → Feld *Vereine*, eine URL pro Zeile:

```text
https://live.kickertool3.de/deinverein
https://live.kickertool3.de/zweiterverein
```

Die URL ist die öffentliche Kickertool-Seite des Vereins — einfach dort im Browser aufrufen und
die Adresse kopieren. Nach dem Speichern erscheinen im Reiter **Turniere** alle Turniere.

Optional im selben Reiter:

- **Turnier-Filter** — nur Turniere, deren Name einen dieser Texte enthält (einer pro Zeile,
  Groß-/Kleinschreibung egal). Leer = alle Turniere.
- **Turniere ab Datum** — alles davor wird ignoriert. Praktisch beim Saisonwechsel.

### 4. Zugriff schützen

Die Seite ist sonst **für jeden im Internet erreichbar** — inklusive der eingetragenen
SMTP-Zugangsdaten und der Cron-URL. Deshalb einen Passwortschutz davorlegen:
bei den meisten Hostern gibt es dafür im Kundenmenü einen Punkt wie
*„Verzeichnisschutz“* / *„Password Protected Directories“*.

Alternativ von Hand eine `.htaccess` neben die `index.php` legen:

```apache
AuthType Basic
AuthName "Kickertool"
AuthUserFile /absoluter/pfad/zu/.htpasswd
Require valid-user
```

Damit ist die Installation fertig und einsatzbereit.

---

## E-Mail-Versand einrichten (optional)

Für den ZIP-Versand per E-Mail im Reiter **Einstellungen** unter *E-Mail & SMTP* eintragen:

| Feld | Beispiel | Bedeutung |
|---|---|---|
| Empfänger | `ergebnisse@example.org` | Wohin die Ergebnisse geschickt werden |
| SMTP-Host | `smtp.example.org` | Postausgangsserver, meist vom eigenen Hoster |
| Port | `587` | `587` für STARTTLS, `465` für SSL, `25` unverschlüsselt |
| Verschlüsselung | `tls` | `tls` (empfohlen), `ssl` oder `none` |
| Absender-Adresse | `ergebnisse@example.org` | Muss dem SMTP-Konto gehören, sonst greift SPF/DMARC |
| Benutzername | `ergebnisse@example.org` | Zugangsdaten des Postfachs |
| Passwort | | Wird gespeichert; leer lassen behält das vorhandene |

Ein Test-Versand geht am schnellsten über den Reiter **Turniere**: ein Turnier anhaken →
*Per E-Mail senden*.

> Tipp: Ein eigenes, ausschließlich für dieses Tool angelegtes Postfach ist deutlich besser
> als das persönliche. Das Passwort wird zwar verschlüsselt abgelegt, muss vom Server aber
> entschlüsselbar bleiben (siehe *Sicherheit*).

---

## Automatischer wöchentlicher Versand (optional)

Der Reiter **Automatisierung** zeigt eine Cron-URL mit einem zufälligen Geheim-Token:

```text
https://www.deinverein.de/kickertool/index.php?cron=1&token=SECRET
```

Ein Aufruf dieser URL packt alle **abgeschlossenen, zum Filter passenden, noch nicht
exportierten** Turniere in ein ZIP, schickt es an die konfigurierte Adresse und markiert sie
als exportiert. Beim nächsten Lauf kommen also nur die neuen Turniere.

Cronjob im Hosting-Panel anlegen, z. B. jeden Montag um 07:00 Uhr:

```cron
0 7 * * 1 wget -qO- "https://www.deinverein.de/kickertool/index.php?cron=1&token=SECRET"
```

Der genaue Befehl steht direkt im Reiter **Automatisierung** zum Kopieren — inklusive
richtigem Token. Zum Ausprobieren gibt es dort den Knopf *Cron jetzt ausführen*.

**Wichtig:** Wenn das Verzeichnis per Passwort geschützt ist (Schritt 4), kommt der Cronjob
nicht mehr durch. Dann entweder die Zugangsdaten mitgeben
(`wget --user=... --password=... -qO- "..."`) oder `index.php` im Verzeichnisschutz ausnehmen —
der Cron-Endpoint ist durch das Token abgesichert.

---

## Sicherheit

Bitte einmal in Ruhe lesen, bevor das Tool produktiv läuft:

- **Das SMTP-Passwort wird verschlüsselt gespeichert** (AES-256-GCM). In `data/config.json`
  steht nur noch ein `enc:v1:…`-Wert. Ein vorhandenes Klartext-Passwort aus einer älteren
  Version wird beim ersten Seitenaufruf automatisch umgestellt.
- **Der Schlüssel muss trotzdem irgendwo liegen** — die App braucht das Passwort im Klartext,
  um sich beim Mailserver anzumelden. Standardmäßig liegt er in `data/secret.key`. Das schützt
  gegen ein weitergegebenes oder abgegriffenes `config.json` (Backup, versehentlicher Commit,
  falsch gesetzter Verzeichnisschutz), **nicht** gegen jemanden, der vollen Zugriff auf den
  Server hat.
- **Deutlich stärker**: den Schlüssel aus dem Dateisystem heraushalten und per Umgebungsvariable
  setzen, z. B. in der `.htaccess` neben der `index.php`:

  ```apache
  SetEnv KICKERTOOL_SECRET_KEY eine-lange-zufaellige-passphrase
  ```

  Dann existiert `data/secret.key` gar nicht erst — am besten **bevor** das SMTP-Passwort das
  erste Mal eingetragen wird. Wer die Variable nachträglich setzt, wechselt damit den Schlüssel:
  das bereits gespeicherte Passwort ist dann nicht mehr lesbar. Dasselbe passiert, wenn die
  Passphrase später geändert wird, `data/secret.key` gelöscht wird oder bei einem Serverumzug
  nicht mitkommt. Folge ist kein Datenverlust — die Oberfläche zeigt das Passwort als nicht
  gesetzt an, es muss einmal neu eingetragen werden.
- **In `data/` liegen weitere sensible Dinge**: `history.json` (Export-Verlauf), `token.txt`
  (Cron-Secret) und ggf. `secret.key`. Das Verzeichnis gehört geschützt:
- **Apache**: Die App legt in `data/` automatisch eine `.htaccess` an, die den Zugriff sperrt
  (Syntax für Apache 2.2 *und* 2.4).
- **nginx ignoriert `.htaccess` grundsätzlich.** Dort muss der Zugriff in der Server-Konfiguration
  gesperrt werden:

  ```nginx
  location ~ /data/ { deny all; return 404; }
  ```

- **Am saubersten**: `data/` komplett aus dem Webroot herausnehmen. Dafür gibt es die
  Umgebungsvariable `KICKERTOOL_DATA_DIR`:

  ```apache
  SetEnv KICKERTOOL_DATA_DIR /home/benutzer/kickertool-data
  ```

  Das Verzeichnis muss existieren und für PHP schreibbar sein.
- **Nach dem Einrichten prüfen**, ob `https://.../kickertool/data/config.json` wirklich einen
  Fehler liefert und nicht die Datei.
- **Die Cron-URL ist ein Passwort-Ersatz.** Nicht in öffentliche Chats, Tickets oder Screenshots.
  Ein neues Token bekommt man, indem man `data/token.txt` löscht.
- **Nichts davon gehört ins Git-Repository.** Die mitgelieferte `.gitignore` schließt `data/`
  bereits aus — bitte so lassen.

---

## Wie es technisch funktioniert

Kickertool stellt zwei öffentliche, nicht authentifizierte REST-Endpunkte bereit, die die
Anwendung direkt aufruft — es wird also kein Browser ferngesteuert und nichts aus HTML
herausgeparst:

| Endpunkt | Zweck |
|---|---|
| `GET https://api.tournament.io/v1/table_soccer/result/page/{verein}` | Turnierliste eines Vereins |
| `GET https://api.tournament.io/v1/table_soccer/result/tournaments/{id}/export/sport-xml` | ZIP mit den Sport-XML-Dateien |

Der Export-Endpunkt ist derselbe, den die Kickertool-Weboberfläche selbst hinter ihrem
Sport-XML-Download-Knopf verwendet.

Pro Turnierphase (Vorrunde, KO-Runde, …) enthält das ZIP eine eigene `.xml`-Datei. Beim Export
mehrerer Turniere werden alle Dateien in ein gemeinsames ZIP gelegt, nach Verein und Turnier
sortiert.

**Hinweis:** Das ist eine inoffizielle Nutzung einer öffentlichen Schnittstelle. Ändert
Kickertool die API, funktioniert der Export nicht mehr — dann bitte ein Issue aufmachen.

---

## Dateien im Repository

```text
index.php             Die komplette Anwendung
config.example.json   Beispielkonfiguration mit erfundenen Werten
.gitignore            Schließt data/ aus
LICENSE               MIT
README.md             Diese Datei
```

`config.example.json` ist nur zur Veranschaulichung — normalerweise trägt man alles über die
Oberfläche ein. Wer will, kann die Datei nach `data/config.json` kopieren und dort anpassen.
Sie enthält **keine echten Zugangsdaten** und soll auch nie welche enthalten.

---

## Problembehebung

| Symptom | Ursache / Lösung |
|---|---|
| Weiße Seite | Meist PHP < 8.2. Version beim Hoster prüfen bzw. umstellen. |
| `Permission denied`, `mkdir()`-Warnung | Das Verzeichnis mit der `index.php` ist für PHP nicht beschreibbar. Shared Hosting: `chmod 755`. Eigener Server/Docker: `chown` auf den PHP-Benutzer. Siehe [TL;DR](#fehler-beim-ersten-aufruf-fast-immer-die-schreibrechte). |
| „Keine Turniere gefunden“ | Vereins-URL falsch, oder der Verein hat keine öffentlichen Turniere. URL im Browser testen. |
| Turnier fehlt in der Liste | Turniere im Status *geplant* werden nur unter *Gefiltert* angezeigt und nicht exportiert. |
| Turnier taucht nicht mehr auf | Es steht unter *Exportiert*. Dort einzeln zurücksetzen, dann ist es wieder „Neu“. |
| E-Mail schlägt fehl | Die Fehlermeldung kommt direkt vom SMTP-Server. Häufig: falscher Port, falsche Verschlüsselung, oder Absender ≠ SMTP-Konto. |
| Passwort plötzlich nicht mehr gesetzt | `data/secret.key` wurde gelöscht oder `KICKERTOOL_SECRET_KEY` geändert. Passwort einmal neu eintragen. |
| ZIP ist leer | Für die gewählten Turniere liefert Kickertool (noch) keine Sport-XML-Daten. |
| Cron sendet nichts | „Keine neuen Turniere“ ist normal, wenn alles schon exportiert ist. Sonst Token und E-Mail-Konfiguration prüfen. |

---

## Mitwirken

Fehler, Wünsche und Verbesserungen gerne als **Issue** oder **Pull Request**.
Da alles in einer Datei liegt, ist auch eine kurze Beschreibung des Problems schon hilfreich.

## Lizenz

[MIT](LICENSE) — Nutzung, Anpassung und Weitergabe ausdrücklich erwünscht, ohne Gewähr.
