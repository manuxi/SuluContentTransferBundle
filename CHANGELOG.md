# Changelog

## 1.1.0 - 2026-10-21

### Added

- One dropdown "Export/Import" (`sulu_content_transfer.transfer`) in the article lists of every group, in place of the three buttons "Export" (CSV of Sulu), "Export package" and "Import package". Entries: "Export list as CSV", "Export package (n)" and "Import package". An entry the user may not use does not appear; without any package entry the list stays as it is.
- The CSV export of Sulu is part of the dropdown: it opens the CSV dialog of Sulu itself (same list, same download, no CSV logic of its own).
- Option `admin.toolbar` (`dropdown`, default, or `buttons`; `buttons` is the behavior of 1.0.0) and option `admin.merge_csv_export` (default `true`; `false` leaves the CSV button of Sulu where it is and the dropdown holds the package entries only).

### Changed

- The toolbar actions `sulu_content_transfer.export` and `sulu_content_transfer.import` stay registered, but the article lists no longer get them by default.

## 1.0.0 - 2026-10-19

First release.

### Added

- Export of articles as a transfer package (`.sulu-transfer.zip`): languages, published version or draft, image files or references only, linked articles that are not selected can be added.
- Import of a package in three steps (choose the file, preview, report) in the article lists of the admin, in German and English. The preview shows per article what happens (create, update, create as copy, skip), per language, the status, images, new categories and tags, and links that could not be matched.
- Console commands `sulu:content-transfer:export` and `sulu:content-transfer:import` with `--dry-run`, `--user`, `--keep-status`, `--overwrite-media-meta`, `--action` and `--locale`.
- Exchange format version 1 (`docs/format.en.md`) and the rules of the matching (`docs/matching.en.md`), each in German and English.
- Articles keep their UUID. Images are matched by name, size and SHA-256. Links to pages, snippets, articles, categories, tags and contacts are matched on the target system; missing categories (with their parents) and tags are created, other missing targets are removed and reported.
- Conflict rule: an article that was changed on the target system after the last import is skipped unless the update is chosen. Imports are drafts by default.
- Table `ct_transfer_mapping` (which article or image of a source system belongs to which one on this system) and the system folder "Import" for new images.
- Permissions per article, language and media folder, checked in the preview and again on the server; the analysis of a package needs the permission to add or edit articles.
- Safety checks on packages: only expected paths, no encrypted or linked entries, limits for size and number of files, SHA-256 check of image files. Reports do not contain paths of the server or database statements.
- Configuration `instance_id`, `instance_label`, `limits.max_package_size`, `limits.max_files` and `work_dir`.
