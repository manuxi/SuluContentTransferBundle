# Matching

Before the import writes anything, it checks every article, image and link of the package against the target system. This page lists the rules. The result is the preview in the admin and the tables of `--dry-run`. When the import is carried out, it computes the plan once more and stops with "Check the package again" if anything on the system changed in between.

---

## Articles

The article of the package is matched with an article of the target system in this order:

1. **The relation table** (`ct_transfer_mapping`) knows the source system and the UUID of the package → that article is the counterpart.
2. **The same UUID** exists on the target system → the same article.
3. Otherwise the article is **new** and is created with the UUID it has in the package. Links to it from other articles stay valid.

If the table points to an article that has been deleted since, the preview says so ("the article of the earlier import no longer exists") and the next rules apply.

A package from the **same system** (same `instance_id`) can only be imported as a copy or skipped: the articles are already there.

### Conflicts

| Situation | State | Default action | Allowed |
|---|---|---|---|
| No counterpart | New | Create | Create, Copy, Skip |
| Counterpart, not changed on this system | Existing, unchanged | Update | Update, Copy, Skip |
| Counterpart, changed on this system | Changed here on ... | Skip | Skip, Update, Copy |
| Template or language problem | Not importable | Skip | Skip |

"Changed here" is decided like this:

- With a relation (an earlier import): any version of the article on this system was changed after the time of that import.
- Without a relation (the same UUID, no earlier import): a version of the same language and stage is newer than the article in the package. A newer draft does not count against a package that holds the published version.

Nothing is overwritten silently: a changed article is skipped unless someone chooses "Update". The preview names who changed it and when. If the package holds nothing newer than the last import, the preview says that, too.

### Copy

"Create as copy" gives the article a new UUID and keeps its titles. The addresses get `-1`, `-2`, ... where they are taken. The original on the target system stays untouched. If the package article had no counterpart, the copy becomes its counterpart for later imports.

### Languages

- Only languages the target system has in the webspace of the article are imported; the others are skipped with a note.
- A language that is not in the package stays as it is on the target system. The import never deletes a language.
- Shadow languages (`shadowOn`, `shadowLocale`) are taken along.
- You can switch languages off in the preview. A switched-off language is not written.

### Templates and webspace

- The template (article type) of the package must exist on the target system, otherwise the article is "Not importable". Deploy the code first.
- If the webspace of the article does not exist on the target system, the article goes into the default webspace (or gets none) and the preview says so.

### Addresses

An article keeps the address of the package if it is free. The page the address sits under is matched by UUID, then by its URL in the same language; if it is missing on the target system, that language is skipped. If an address is taken by other content, the article gets `-1`, `-2`, ... and the report names the new address. Two articles of the same package never take addresses from each other.

## Status

By default **everything is imported as a draft**, whatever it was on the source. Updating an article that is published on the target changes only its draft; the published version stays until someone publishes it.

"Same as in the source" publishes the languages that were published on the source (it needs the "Live" permission). Languages that were only drafts there stay drafts.

## Images

An image is matched in this order:

1. **The relation table** knows the image of the source system. If the file is identical (SHA-256), it is reused. If the package holds a different file, the image gets a **new file version**. If the package has no file, the image is reused as it is.
2. **Name, size and SHA-256** equal to an image of the target system → that image is reused. Only images with the same name and size are read and hashed.
3. Otherwise the image is **created**, if the package holds the file. It goes into the media folder with the same key as on the source; if there is none, it goes into the system folder "Import". Focus point, crops, tags and categories (matched by key) are taken along for new images.
4. Without a file in the package and without a counterpart the image is **missing**: the reference is removed and the preview and report say so.

The titles, descriptions, copyrights and credits of images that already exist are only replaced if "Overwrite the texts of existing images" is ticked. Focus points and crops of existing images are not touched.

## Links

The package lists every place in an article that points to something else (see [format](format.en.md#references-of-an-article)). Each one is matched on the target system:

| Target | Matched by | If there is no counterpart |
|---|---|---|
| Image | see above | Reference removed |
| Page | UUID, then URL in the same language | Reference removed |
| Snippet | relation table, then UUID | Reference removed |
| Article | relation table, then UUID; articles of the package point to the target UUID of their counterpart | Reference removed |
| Category | `key`; without a key the chain of names from the root (upper and lower case ignored) | **Created**, with the missing parents, with the `key` and the names of the source |
| Tag | name | **Created** |
| Contact (also the author) | e-mail, then first and last name if that is unambiguous | Reference removed |
| Organization, media folder, target group | the same number on the target system | Reference removed |

Organizations, media folders and target groups cannot be described by a package. If the number exists, the reference stays and the preview says "kept as it is, please check it briefly". Check that it is the right one.

These places are rewritten: fields that select something (single and multiple), image fields in blocks, the excerpt (image, icon, categories, tags), links in rich text (`<sulu-link>`), `link` fields, the filters and the data source of `smart_content`, teasers, and the page an address sits under.

Removing means: a list loses the entry, a single value becomes empty, a link in a text loses its link and keeps its text, a `smart_content` field without a data source is emptied. The report lists the removals per language and place.

Categories and tags that will be created are listed in the preview before anything is written.

## What the import writes

- Articles through Sulu's own messages (`CreateArticleMessage`, `ModifyArticleMessage`, publish), so events, search index and activity log follow. The importing user is creator and changer.
- Images through Sulu's media manager, origin `import`.
- Categories and tags through the managers of Sulu.
- One row per article and per image in `ct_transfer_mapping`, so the next import finds its counterparts.

Each article is written in its own transaction. If one fails, nothing of it stays and the others carry on; the report shows it in red with the reason.
