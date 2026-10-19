# Zuordnung

Bevor der Import etwas schreibt, gleicht er jeden Artikel, jedes Bild und jeden Link des Pakets mit dem Zielsystem ab. Diese Seite nennt die Regeln. Das Ergebnis ist die Vorschau im Admin und sind die Tabellen von `--dry-run`. Beim Ausführen rechnet der Import den Plan noch einmal und bricht mit „Bitte die Datei noch einmal prüfen“ ab, wenn sich auf dem System inzwischen etwas geändert hat.

---

## Artikel

Der Artikel aus dem Paket wird in dieser Reihenfolge einem Artikel des Zielsystems zugeordnet:

1. **Die Zuordnungstabelle** (`ct_transfer_mapping`) kennt das Quellsystem und die UUID aus dem Paket → dieser Artikel ist das Gegenstück.
2. **Dieselbe UUID** gibt es auf dem Zielsystem → derselbe Artikel.
3. Sonst ist der Artikel **neu** und wird mit der UUID angelegt, die er im Paket hat. Links auf ihn aus anderen Artikeln bleiben gültig.

Zeigt die Tabelle auf einen Artikel, der inzwischen gelöscht wurde, sagt die Vorschau das („Den Artikel aus dem früheren Import gibt es hier nicht mehr“), und die nächsten Regeln gelten.

Ein Paket vom **selben System** (gleiche `instance_id`) lässt sich nur als Kopie importieren oder überspringen: Die Artikel sind ja schon da.

### Konflikte

| Lage | Zustand | Standardaktion | Erlaubt |
|---|---|---|---|
| Kein Gegenstück | Neu | Anlegen | Anlegen, Als Kopie anlegen, Überspringen |
| Gegenstück, hier nicht geändert | Vorhanden, unverändert | Aktualisieren | Aktualisieren, Als Kopie anlegen, Überspringen |
| Gegenstück, hier geändert | Hier geändert am ... | Überspringen | Überspringen, Aktualisieren, Als Kopie anlegen |
| Problem mit Vorlage oder Sprache | Nicht importierbar | Überspringen | Überspringen |

„Hier geändert“ wird so entschieden:

- Mit Zuordnung (ein früherer Import): Irgendeine Fassung des Artikels auf diesem System wurde nach dem Zeitpunkt dieses Imports geändert.
- Ohne Zuordnung (gleiche UUID, kein früherer Import): Eine Fassung derselben Sprache und Stufe ist neuer als der Artikel im Paket. Ein neuerer Entwurf zählt nicht gegen ein Paket, das die veröffentlichte Fassung enthält.

Nichts wird still überschrieben: Ein geänderter Artikel wird übersprungen, solange niemand „Aktualisieren“ wählt. Die Vorschau nennt, wer ihn wann geändert hat. Enthält das Paket nichts Neueres als der letzte Import, sagt sie auch das.

### Kopie

„Als Kopie anlegen“ gibt dem Artikel eine neue UUID und lässt die Titel, wie sie sind. Die Adressen bekommen `-1`, `-2`, ..., wo sie belegt sind. Das Original auf dem Zielsystem bleibt unberührt. Hatte der Artikel aus dem Paket kein Gegenstück, wird die Kopie bei späteren Importen sein Gegenstück.

### Sprachen

- Importiert werden nur Sprachen, die das Zielsystem im Webspace des Artikels hat. Die anderen werden mit Hinweis übersprungen.
- Eine Sprache, die nicht im Paket steckt, bleibt auf dem Zielsystem, wie sie ist. Der Import löscht nie eine Sprache.
- Schattensprachen (`shadowOn`, `shadowLocale`) gehen mit.
- In der Vorschau lassen sich Sprachen abwählen. Eine abgewählte Sprache wird nicht geschrieben.

### Vorlagen und Webspace

- Die Vorlage (der Artikeltyp) aus dem Paket muss auf dem Zielsystem existieren, sonst ist der Artikel „Nicht importierbar“. Erst den Code deployen.
- Gibt es den Webspace des Artikels auf dem Zielsystem nicht, kommt der Artikel in den Standard-Webspace (oder bekommt keinen), und die Vorschau sagt das.

### Adressen

Ein Artikel behält die Adresse aus dem Paket, wenn sie frei ist. Die Seite, unter der die Adresse liegt, wird über die UUID zugeordnet, dann über ihre URL in derselben Sprache. Fehlt sie auf dem Zielsystem, wird diese Sprache übersprungen. Ist die Adresse von anderem Inhalt belegt, bekommt der Artikel `-1`, `-2`, ..., und der Bericht nennt die neue Adresse. Zwei Artikel desselben Pakets nehmen sich keine Adressen weg.

## Status

Standardmäßig wird **alles als Entwurf importiert**, egal was es in der Quelle war. Wer einen Artikel aktualisiert, der auf dem Ziel veröffentlicht ist, ändert nur dessen Entwurf. Die veröffentlichte Fassung bleibt, bis jemand veröffentlicht.

„Wie in der Quelle“ veröffentlicht die Sprachen, die in der Quelle veröffentlicht waren (dafür braucht es das Recht „Live“). Sprachen, die dort nur Entwurf waren, bleiben Entwurf.

## Bilder

Ein Bild wird in dieser Reihenfolge zugeordnet:

1. **Die Zuordnungstabelle** kennt das Bild des Quellsystems. Ist die Datei gleich (SHA-256), wird es wiederverwendet. Enthält das Paket eine andere Datei, bekommt das Bild eine **neue Dateiversion**. Hat das Paket keine Datei, wird das Bild unverändert wiederverwendet.
2. **Name, Größe und SHA-256** stimmen mit einem Bild des Zielsystems überein → dieses Bild wird wiederverwendet. Gelesen und geprüft werden nur Bilder mit gleichem Namen und gleicher Größe.
3. Sonst wird das Bild **angelegt**, falls das Paket die Datei enthält. Es kommt in den Medienordner mit demselben Schlüssel wie in der Quelle, gibt es den nicht, in den Systemordner „Import“. Fokuspunkt, Zuschnitte, Tags und Kategorien (über den Schlüssel zugeordnet) gehen bei neuen Bildern mit.
4. Ohne Datei im Paket und ohne Gegenstück **fehlt** das Bild: Der Verweis wird entfernt, und Vorschau und Bericht sagen das.

Titel, Beschreibung, Copyright und Credits von Bildern, die es schon gibt, werden nur ersetzt, wenn „Bildtexte vorhandener Bilder überschreiben“ angehakt ist. Fokuspunkte und Zuschnitte vorhandener Bilder bleiben unangetastet.

## Links

Das Paket nennt jede Stelle eines Artikels, die auf etwas anderes zeigt (siehe [Format](format.de.md#references-eines-artikels)). Jede wird auf dem Zielsystem zugeordnet:

| Ziel | Zuordnung über | Gibt es kein Gegenstück |
|---|---|---|
| Bild | siehe oben | Verweis wird entfernt |
| Seite | UUID, dann URL in derselben Sprache | Verweis wird entfernt |
| Snippet | Zuordnungstabelle, dann UUID | Verweis wird entfernt |
| Artikel | Zuordnungstabelle, dann UUID; Artikel des Pakets zeigen auf die Ziel-UUID ihres Gegenstücks | Verweis wird entfernt |
| Kategorie | `key`; ohne Schlüssel die Namenskette von der Wurzel (Groß- und Kleinschreibung egal) | **Wird angelegt**, mit den fehlenden Eltern, mit dem `key` und den Namen aus der Quelle |
| Tag | Name | **Wird angelegt** |
| Kontakt (auch der Autor) | E-Mail, dann Vor- und Nachname, wenn das eindeutig ist | Verweis wird entfernt |
| Organisation, Medienordner, Zielgruppe | dieselbe Nummer auf dem Zielsystem | Verweis wird entfernt |

Organisationen, Medienordner und Zielgruppen kann ein Paket nicht beschreiben. Gibt es die Nummer, bleibt der Verweis, und die Vorschau sagt „übernommen, bitte kurz prüfen“. Dann nachsehen, ob es die richtige ist.

Umgeschrieben werden diese Stellen: Auswahlfelder (einzeln und mehrfach), Bildfelder in Blöcken, der Excerpt (Bild, Icon, Kategorien, Tags), Links in formatiertem Text (`<sulu-link>`), `link`-Felder, die Filter und die Datenquelle von `smart_content`, Teaser und die Seite, unter der eine Adresse liegt.

Entfernen heißt: Eine Liste verliert den Eintrag, ein einzelner Wert wird leer, ein Link im Text verliert den Link und behält den Text, ein `smart_content`-Feld ohne Datenquelle wird geleert. Der Bericht listet die Entfernungen je Sprache und Stelle.

Kategorien und Tags, die angelegt werden, stehen in der Vorschau, bevor etwas geschrieben wird.

## Was der Import schreibt

- Artikel über die eigenen Nachrichten von Sulu (`CreateArticleMessage`, `ModifyArticleMessage`, Veröffentlichen), sodass Ereignisse, Suchindex und Aktivitätsprotokoll mitlaufen. Der importierende Benutzer ist Ersteller und Bearbeiter.
- Bilder über den Medien-Manager von Sulu, Herkunft `import`.
- Kategorien und Tags über die Manager von Sulu.
- Eine Zeile je Artikel und je Bild in `ct_transfer_mapping`, damit der nächste Import seine Gegenstücke findet.

Jeder Artikel wird in einer eigenen Transaktion geschrieben. Scheitert einer, bleibt nichts von ihm zurück, und die anderen laufen weiter. Der Bericht zeigt ihn rot mit dem Grund.
