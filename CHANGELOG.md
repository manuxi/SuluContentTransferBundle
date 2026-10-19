# Changelog

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
