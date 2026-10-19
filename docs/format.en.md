# Exchange format, version 1

A package is a ZIP file with the ending `.sulu-transfer.zip`. This page describes it for tools that read or write packages. The bundle itself reads and writes it with `PackageReader` and `PackageWriter`.

---

## Layout

```
2026-10-19-first-steps.sulu-transfer.zip
├── manifest.json
├── articles/<uuid>.json
└── media/<id>/
    ├── meta.json
    └── <original file name>
```

| Path | Content |
|---|---|
| `manifest.json` | Exactly once. Source, options, list of the articles and the lookup data. |
| `articles/<uuid>.json` | One file per article. `<uuid>` is lower case and the same as `uuid` inside the file. |
| `media/<id>/meta.json` | Only with image files. `<id>` is the number of the image on the source system (without leading zero). |
| `media/<id>/<file>` | The original file of the image. Exactly one file next to `meta.json`. |

A package without image files has no `media/` folder. The images are then only named in `manifest.json` under `references.media`.

All JSON files are UTF-8, indented with four spaces, with sorted keys in objects and without escaping of `/` and non-ASCII characters. Lists keep their order. Dates are ISO 8601 with time zone, for example `2026-10-19T09:30:00+02:00`.

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

| Field | Meaning |
|---|---|
| `format` | Always `sulu-content-transfer`. |
| `formatVersion` | `1`. A reader refuses a higher number with a clear message ("the package comes from a newer version of the bundle"). It checks this before it looks at anything else. |
| `createdAt` | When the package was written. |
| `source.instanceId` | Identifies the system that wrote the package (see `instance_id` in the README). The import remembers its relations per instance. |
| `source.label` | Readable name of the source: the host of the request, `cli` on the console, or the configured label. |
| `source.suluVersion`, `source.bundleVersion` | Versions at the time of the export. |
| `source.user` | Reserved. The current version writes `null`. |
| `options.locales` | The languages that were exported. |
| `options.stage` | What was asked for: `live` or `draft`. The version that was really taken is in every language of the article. |
| `options.includeMediaFiles` | Whether the image files are part of the package. |
| `items` | The articles of the package: `resourceKey` (`articles`), `id` (UUID), `templateKey` and `titles` per language. Every item has a file in `articles/`, and every file in `articles/` has an item. |
| `references` | Lookup data for everything an article links to (see below). |

### references

These are only descriptions, so that the target system can find its own counterpart. Rows may carry further fields, which readers keep and ignore.

| List | Row | Notes |
|---|---|---|
| `pages` | `uuid`, `webspace`, `urls` (per language), `titles` (per language) | |
| `snippets` | `uuid`, `templateKey`, `titles` | |
| `articles` | `uuid`, `templateKey`, `titles`, `urls` | Only articles that are not part of the package. |
| `categories` | `id`, `key`, `names` (per language), `path` | `path` lists the parents from the root, each with `key` and `names`. |
| `tags` | `id`, `name` | |
| `contacts` | `id`, `firstName`, `lastName`, `email` | |
| `media` | `id`, `filename`, `size`, `sha256` | Always, also without image files. |

Organizations, media folders and target groups have no list in version 1. Their references are in the articles, and the target system matches them by number.

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

| Field | Meaning |
|---|---|
| `uuid` | UUID of the article on the source system. |
| `changed` | The most recent change among the exported languages. |
| `dimensions` | One entry per exported language. |
| `dimensions.<locale>.stage` | `live` (the published version) or `draft`. |
| `dimensions.<locale>.published` | `true` if the language is published on the source. With `stage: "draft"` and `published: false` the language was only available as a draft. |
| `dimensions.<locale>.publishedAt` | When it was published, or `null`. |
| `dimensions.<locale>.data` | The content of the language in the form of the admin: what `GET /admin/api/articles/<uuid>?locale=de` returns and what `ModifyArticleMessage` accepts (`template`, `title`, `url`, the fields of the template, `excerpt*`, `seo*`, `author`, `authored`, `mainWebspace`, `shadowOn`, `shadowLocale`, ...). Sulu's `_hash` is removed. Fields that the import does not write (`id`, `changed`, `created`, `creator`, `changer`, `version`, `stage`, `availableLocales`, `contentLocales`, `ghostLocale`, `locale`, `publishedState`, `workflowPlace`, `published`, `shadowLocales`) may be present and are ignored. |
| `references` | Every place in `data` that points to something else. |

### references of an article

Each row says where a reference sits and what it points to:

| Field | Meaning |
|---|---|
| `locale` | The language of the `data` it sits in. |
| `path` | Path inside `data`, parts separated by dots, a number is a list index (`sections.0.image.id`, `gallery.ids.1`). It points to the value that holds the id. For rich text fields (`text_editor`) it is the text field itself, and there is one row per linked target. |
| `resourceKey` | `media`, `pages`, `articles`, `snippets`, `categories`, `tags`, `contacts`, `accounts` (organizations), `collections` (media folders) or `target_groups`. |
| `id` | The id on the source system: a number, or a UUID for pages, articles and snippets. In the tag filter of a `smart_content` field it is the name of the tag, because Sulu stores names there. |

The importer rewrites exactly the places listed here. An id that sits in `data` but is not listed stays as it is. Tools that write packages must therefore list every reference.

The paths do not follow the paths Sulu reports for its own views (`settings.author`, `excerpt.categories`), because those do not fit the form of the admin. They follow `data`.

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

| Field | Meaning |
|---|---|
| `filename`, `mimeType` | Original name and type of the file. |
| `size`, `sha256` | Size in bytes and lower case SHA-256 of the file. A reader checks both against the file in the package. |
| `meta` | Title, description, copyright and credits per language (a text may be `null`). |
| `focusPoint` | `{x, y}` or `null`. |
| `formatOptions` | Crops per image format. |
| `tags`, `categories`, `properties` | As in Sulu. Categories are described by `key` and `names`. |
| `collection` | The folder: its `key` (or `null`) and the titles of the folders from the root down. |

The file in the package carries the name `filename` with characters removed that are not allowed in a path (`/ \ : * ? " < > |`, control characters, reserved Windows names). The original name stays in `meta.json`.

## Safety checks of the reader

A package is untrusted input. Before anything is unpacked, `PackageReader` checks:

- Only the paths of the layout above are allowed. No absolute paths, no `..`, no backslashes, no other files.
- No encrypted entries, no symbolic links, no entries that differ only in upper and lower case.
- The number of files and the size are within `limits.max_files` and `limits.max_package_size`; a single JSON file may have up to 32 MiB. The sizes in the ZIP header are only claims, so the bytes that are really unpacked are counted.
- The format version comes first.
- Afterwards: every image file matches the size and the SHA-256 in its `meta.json`, every article file matches its UUID, and manifest, articles and images belong together.

Files are unpacked into a new subdirectory of `work_dir` with a random name, never anywhere else.

## Compatibility

Version 1 is the first version. A reader refuses higher versions and may ignore additional fields in the rows of `references`. A change that a version 1 reader could not handle gets a new `formatVersion`.
