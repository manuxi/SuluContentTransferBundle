# Austauschformat, Version 1

Ein Paket ist eine ZIP-Datei mit der Endung `.sulu-transfer.zip`. Diese Seite beschreibt es für Werkzeuge, die Pakete lesen oder schreiben. Das Bundle selbst liest und schreibt es mit `PackageReader` und `PackageWriter`.

---

## Aufbau

```
2026-10-19-erste-schritte.sulu-transfer.zip
├── manifest.json
├── articles/<uuid>.json
└── media/<id>/
    ├── meta.json
    └── <Originaldateiname>
```

| Pfad | Inhalt |
|---|---|
| `manifest.json` | Genau einmal. Quelle, Optionen, Liste der Artikel und die Nachschlagedaten. |
| `articles/<uuid>.json` | Eine Datei je Artikel. `<uuid>` ist kleingeschrieben und gleich `uuid` in der Datei. |
| `media/<id>/meta.json` | Nur mit Bilddateien. `<id>` ist die Nummer des Bildes auf dem Quellsystem (ohne führende Null). |
| `media/<id>/<Datei>` | Die Originaldatei des Bildes. Genau eine Datei neben der `meta.json`. |

Ein Paket ohne Bilddateien hat keinen Ordner `media/`. Die Bilder stehen dann nur in `manifest.json` unter `references.media`.

Alle JSON-Dateien sind UTF-8, mit vier Leerzeichen eingerückt, mit sortierten Schlüsseln in Objekten und ohne Maskierung von `/` und Nicht-ASCII-Zeichen. Listen behalten ihre Reihenfolge. Zeitangaben folgen ISO 8601 mit Zeitzone, zum Beispiel `2026-10-19T09:30:00+02:00`.

## manifest.json

```json
{
    "format": "sulu-content-transfer",
    "formatVersion": 1,
    "createdAt": "2026-10-19T09:30:00+02:00",
    "source": {
        "bundleVersion": "1.0.0",
        "instanceId": "631cf06769adb439",
        "label": "local.example.test",
        "suluVersion": "3.0.9",
        "user": null
    },
    "options": {
        "includeMediaFiles": true,
        "locales": ["de", "en"],
        "stage": "live"
    },
    "items": [
        {
            "id": "01a0f000-7e57-7000-8000-000000000001",
            "resourceKey": "articles",
            "templateKey": "tutorial",
            "titles": {"de": "Erste Schritte", "en": "First steps"}
        }
    ],
    "references": {
        "articles": [],
        "categories": [],
        "contacts": [],
        "media": [],
        "pages": [],
        "snippets": [],
        "tags": []
    }
}
```

| Feld | Bedeutung |
|---|---|
| `format` | Immer `sulu-content-transfer`. |
| `formatVersion` | `1`. Eine höhere Zahl lehnt der Leser mit einer klaren Meldung ab („Das Paket stammt aus einer neueren Version des Bundles“). Das prüft er vor allem anderen. |
| `createdAt` | Wann das Paket geschrieben wurde. |
| `source.instanceId` | Kennzeichnet das System, das das Paket geschrieben hat (siehe `instance_id` in der README). Der Import merkt sich seine Zuordnungen je Instanz. |
| `source.label` | Lesbarer Name der Quelle: der Host der Anfrage, `cli` in der Konsole oder das eingestellte Etikett. |
| `source.suluVersion`, `source.bundleVersion` | Versionen zum Zeitpunkt des Exports. |
| `source.user` | Reserviert. Die aktuelle Version schreibt `null`. |
| `options.locales` | Die Sprachen, die exportiert wurden. |
| `options.stage` | Was angefordert war: `live` oder `draft`. Welche Fassung wirklich genommen wurde, steht in jeder Sprache des Artikels. |
| `options.includeMediaFiles` | Ob die Bilddateien im Paket liegen. |
| `items` | Die Artikel des Pakets: `resourceKey` (`articles`), `id` (UUID), `templateKey` und `titles` je Sprache. Zu jedem Eintrag gibt es eine Datei in `articles/`, und zu jeder Datei in `articles/` einen Eintrag. |
| `references` | Nachschlagedaten für alles, worauf ein Artikel verlinkt (siehe unten). |

### references

Das sind nur Beschreibungen, damit das Zielsystem sein eigenes Gegenstück findet. Zeilen dürfen weitere Felder tragen. Leser behalten sie und ignorieren sie.

| Liste | Zeile | Hinweise |
|---|---|---|
| `pages` | `uuid`, `webspace`, `urls` (je Sprache), `titles` (je Sprache) | |
| `snippets` | `uuid`, `templateKey`, `titles` | |
| `articles` | `uuid`, `templateKey`, `titles`, `urls` | Nur Artikel, die nicht zum Paket gehören. |
| `categories` | `id`, `key`, `names` (je Sprache), `path` | `path` nennt die Eltern von der Wurzel an, jeweils mit `key` und `names`. |
| `tags` | `id`, `name` | |
| `contacts` | `id`, `firstName`, `lastName`, `email` | |
| `media` | `id`, `filename`, `size`, `sha256` | Immer, auch ohne Bilddateien. |

Für Organisationen, Medienordner und Zielgruppen gibt es in Version 1 keine Liste. Ihre Verweise stehen in den Artikeln, und das Zielsystem ordnet sie über die Nummer zu.

## articles/&lt;uuid&gt;.json

```json
{
    "uuid": "01a0f000-7e57-7000-8000-000000000001",
    "changed": "2026-10-18T22:20:45+02:00",
    "dimensions": {
        "de": {
            "stage": "live",
            "published": true,
            "publishedAt": "2026-10-18T22:25:00+02:00",
            "data": {
                "template": "tutorial",
                "title": "Erste Schritte",
                "url": {"page": {"path": "/articles", "uuid": "01a0ea21-f2e0-76a6-b1a1-73652344f2a5"}, "suffix": "/erste-schritte"},
                "mainWebspace": "your_webspace",
                "author": 3,
                "authored": "2026-10-18T09:00:00+02:00",
                "heroImage": {"id": 207}
            }
        }
    },
    "references": [
        {"locale": "de", "path": "heroImage.id", "resourceKey": "media", "id": 207},
        {"locale": "de", "path": "author", "resourceKey": "contacts", "id": 3}
    ]
}
```

| Feld | Bedeutung |
|---|---|
| `uuid` | UUID des Artikels auf dem Quellsystem. |
| `changed` | Die jüngste Änderung unter den exportierten Sprachen. |
| `dimensions` | Ein Eintrag je exportierter Sprache. |
| `dimensions.<Sprache>.stage` | `live` (die veröffentlichte Fassung) oder `draft`. |
| `dimensions.<Sprache>.published` | `true`, wenn die Sprache in der Quelle veröffentlicht ist. Bei `stage: "draft"` und `published: false` gab es die Sprache nur als Entwurf. |
| `dimensions.<Sprache>.publishedAt` | Wann veröffentlicht wurde, oder `null`. |
| `dimensions.<Sprache>.data` | Der Inhalt der Sprache in der Form des Admins: was `GET /admin/api/articles/<uuid>?locale=de` liefert und `ModifyArticleMessage` annimmt (`template`, `title`, `url`, die Felder der Vorlage, `excerpt*`, `seo*`, `author`, `authored`, `mainWebspace`, `shadowOn`, `shadowLocale`, ...). Das `_hash` von Sulu ist entfernt. Felder, die der Import nicht schreibt (`id`, `changed`, `created`, `creator`, `changer`, `version`, `stage`, `availableLocales`, `contentLocales`, `ghostLocale`, `locale`, `publishedState`, `workflowPlace`, `published`, `shadowLocales`), dürfen vorkommen und werden ignoriert. |
| `references` | Jede Stelle in `data`, die auf etwas anderes zeigt. |

### references eines Artikels

Jede Zeile sagt, wo ein Verweis steht und worauf er zeigt:

| Feld | Bedeutung |
|---|---|
| `locale` | Die Sprache der `data`, in der er steht. |
| `path` | Pfad innerhalb von `data`, Teile durch Punkte getrennt, eine Zahl ist ein Listenindex (`sections.0.image.id`, `gallery.ids.1`). Er zeigt auf den Wert, der die Id enthält. Bei Textfeldern mit Formatierung (`text_editor`) ist es das Textfeld selbst, und es gibt eine Zeile je verlinktem Ziel. |
| `resourceKey` | `media`, `pages`, `articles`, `snippets`, `categories`, `tags`, `contacts`, `accounts` (Organisationen), `collections` (Medienordner) oder `target_groups`. |
| `id` | Die Id auf dem Quellsystem: eine Zahl, bei Seiten, Artikeln und Snippets eine UUID. Im Tag-Filter eines `smart_content`-Feldes ist es der Name des Tags, weil Sulu dort Namen speichert. |

Der Import schreibt genau die Stellen um, die hier stehen. Eine Id, die in `data` steht, aber nicht aufgeführt ist, bleibt unverändert. Werkzeuge, die Pakete schreiben, müssen deshalb jeden Verweis aufführen.

Die Pfade folgen nicht den Pfaden, die Sulu für die eigenen Ansichten meldet (`settings.author`, `excerpt.categories`), weil diese nicht zur Form des Admins passen. Sie folgen `data`.

## media/&lt;id&gt;/meta.json

```json
{
    "filename": "diagram.jpg",
    "mimeType": "image/jpeg",
    "size": 124884,
    "sha256": "9f2c...",
    "meta": {
        "de": {"title": "Schaubild", "description": "", "copyright": "", "credits": ""},
        "en": {"title": "Diagram", "description": "", "copyright": "", "credits": ""}
    },
    "focusPoint": {"x": 50, "y": 50},
    "formatOptions": {"sulu-400x400": {"cropX": 0, "cropY": 0, "cropWidth": 800, "cropHeight": 800}},
    "tags": ["tutorial"],
    "categories": [{"key": "tutorials", "names": {"de": "Tutorials", "en": "Tutorials"}}],
    "properties": {},
    "collection": {"key": null, "titlePath": [{"de": "Tutorials", "en": "Tutorials"}]}
}
```

| Feld | Bedeutung |
|---|---|
| `filename`, `mimeType` | Originalname und Typ der Datei. |
| `size`, `sha256` | Größe in Bytes und kleingeschriebener SHA-256 der Datei. Ein Leser prüft beides gegen die Datei im Paket. |
| `meta` | Titel, Beschreibung, Copyright und Credits je Sprache (ein Text darf `null` sein). |
| `focusPoint` | `{x, y}` oder `null`. |
| `formatOptions` | Zuschnitte je Bildformat. |
| `tags`, `categories`, `properties` | Wie in Sulu. Kategorien sind über `key` und `names` beschrieben. |
| `collection` | Der Ordner: sein `key` (oder `null`) und die Titel der Ordner von der Wurzel abwärts. |

Die Datei im Paket trägt den Namen `filename`, bereinigt um Zeichen, die in einem Pfad nicht erlaubt sind (`/ \ : * ? " < > |`, Steuerzeichen, reservierte Windows-Namen). Der Originalname bleibt in der `meta.json`.

## Sicherheitsprüfungen des Lesers

Ein Paket ist eine Eingabe, der man nicht traut. Bevor etwas entpackt wird, prüft `PackageReader`:

- Erlaubt sind nur die Pfade des Aufbaus oben. Keine absoluten Pfade, kein `..`, keine Backslashes, keine anderen Dateien.
- Keine verschlüsselten Einträge, keine symbolischen Links, keine Einträge, die sich nur in Groß- und Kleinschreibung unterscheiden.
- Anzahl und Größe der Dateien liegen innerhalb von `limits.max_files` und `limits.max_package_size`, eine einzelne JSON-Datei darf bis zu 32 MiB groß sein. Die Größen im ZIP-Kopf sind nur Behauptungen, darum zählt der Leser die Bytes, die wirklich entpackt werden.
- Die Formatversion kommt zuerst.
- Danach: Jede Bilddatei passt zu Größe und SHA-256 in ihrer `meta.json`, jede Artikeldatei zu ihrer UUID, und Manifest, Artikel und Bilder gehören zusammen.

Entpackt wird in ein neues Unterverzeichnis von `work_dir` mit zufälligem Namen, nie anderswohin.

## Kompatibilität

Version 1 ist die erste Version. Ein Leser lehnt höhere Versionen ab und darf zusätzliche Felder in den Zeilen von `references` ignorieren. Eine Änderung, die ein Leser der Version 1 nicht verstehen würde, bekommt eine neue `formatVersion`.
