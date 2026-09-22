# Media File Relocate

Moves the files attached to media entities into a folder derived from a
pattern matched in the file name. Files whose name does not match any
configured pattern are left where Drupal put them.

## Why

Drupal decides the upload folder from the field's *File directory* setting
(for example `[date:custom:Y]-[date:custom:m]`) before it knows the file
name, so the file name cannot be used in that setting. This module runs
after the media entity is saved instead, so it works for every way a file
can arrive: the media add form, the media library, REST `file:upload`
(as used by Islandora Workbench), JSON:API, and migrations.

Example with the default pattern:

| Uploaded file                  | Default location             | With this module                          |
|--------------------------------|------------------------------|-------------------------------------------|
| `61220_utsc11048_sfcHvZg.tif`  | `private://2026-09/…`        | `private://61220_utsc11048/…`             |
| `61220_utsc11049_report.pdf`   | `private://2026-09/…`        | `private://61220_utsc11049/…`             |
| `holiday_photo.jpg`            | `private://2026-09/…`        | unchanged (`private://2026-09/…`)         |

## Requirements

- Drupal 10.3 or later, or Drupal 11.
- Core `file` and `media` modules.
- Drush 12 or later for the relocate command (optional).

## Installation

The module lives in `web/modules/custom/media_file_relocate`. Enable it
with Drush and export the configuration:

```bash
ddev drush en media_file_relocate -y
ddev drush config:export -y
```

Enabling the module installs an empty pattern list, so nothing moves until a
pattern is configured. It does not touch any existing files; use the Drush
command for those.

## Configuration

Go to **Configuration > Media > Media File Relocate**
(`/admin/config/media/media-file-relocate`). The page requires the
*Administer site configuration* permission.

Enter one PCRE regular expression per line, including delimiters. Patterns
are tried in order and the first match wins. The folder name is:

- the **first capture group** if the pattern has one, otherwise
- the **whole match**.

This site's configuration (in `config/sync`) is:

```yaml
patterns:
  - '/^(61220_utsc\d+)/'
```

Examples:

| Pattern                    | File name                     | Folder            |
|----------------------------|-------------------------------|-------------------|
| `/^(61220_utsc\d+)/`       | `61220_utsc11048_abc.tif`     | `61220_utsc11048` |
| `/^([A-Z]{3})-\d+/`        | `LIB-2024-scan.pdf`           | `LIB`             |
| `/^\d{4}/`                 | `2019_field_notes.docx`       | `2019`            |

Invalid regular expressions are rejected by the form. A folder name that is
empty, `.` or `..`, or that contains `/` or `\`, is ignored and logged.

The configuration is stored in `media_file_relocate.settings` and can also
be set with Drush:

```bash
ddev drush config:set media_file_relocate.settings patterns.0 '/^(61220_utsc\d+)/'
```

## How it works

1. `hook_media_presave()` runs every time a media entity is saved (create or
   update).
2. The `media_file_relocate.relocator` service (`FileRelocator`) looks at
   every `file` and `image` field on that media entity.
3. For each referenced file it matches the file name against the configured
   patterns to get a folder name.
4. The target scheme is taken from the field storage's *Upload destination*
   (`uri_scheme`), so private fields stay private and public fields stay
   public. The destination is `<scheme>://<folder>/<current basename>`.
5. If the file is already in that folder nothing happens, so re-saving a
   media entity is harmless.
6. Otherwise the folder is created if needed and the file is moved with the
   core file repository, which updates the file entity's URI, and invokes
   `hook_file_move()` (for example so the image module can flush old image
   style derivatives). A name collision at the destination is resolved by
   renaming, as Drupal does for uploads.

Only the file's location changes. The file entity ID, the media entity,
`filehash` checksums and `dgi_fixity` records are unaffected. Each move is
logged to the `media_file_relocate` channel.

## Relocating existing files

Files uploaded before the module was enabled, or before a pattern was
added, are moved with the Drush command:

```bash
# Show what would move, without changing anything.
ddev drush media-file-relocate:relocate --dry-run

# Move matching files on all media.
ddev drush media-file-relocate:relocate

# Limit to one media type.
ddev drush media-file-relocate:relocate --bundle=image
```

`mfr:relocate` is a short alias. The command loads media in batches and
prints one line per file, for example:

```
Moved media 12 (file) file 34: private://2026-09/61220_utsc11048_sfcHvZg.tif -> private://61220_utsc11048/61220_utsc11048_sfcHvZg.tif
```

followed by a summary count. Run it with `--dry-run` first on a production
site.

Re-saving a media entity through the UI or a Workbench update also moves
its files, so the command is only needed for bulk work.

## Logs

```bash
ddev drush watchdog:show --type=media_file_relocate
```

Info entries record each move. Warnings are written when a source file is
missing from disk or when a match produces an unusable folder name. Errors
are written when a pattern is not a valid regular expression, when the
target folder cannot be created, or when the move itself fails.

## Tests

A kernel test is provided in `tests/src/Kernel/FileRelocatorTest.php`. It
needs `drupal/core-dev` in the project:

```bash
ddev composer require --dev drupal/core-dev
ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/media_file_relocate"
```

## Notes and limitations

- Files are matched by the name stored on the file entity, which is the
  original upload name after Drupal's sanitising, not the on-disk name.
- Only fields of type `file` and `image` are handled. Remote video, oEmbed
  and other non-file media sources are ignored.
- Files are never moved by cron or automatically on module install.
- Old image style derivatives under the previous folder are flushed by
  core; new ones are generated on demand from the new location.
- The module does not delete the now-empty source folder.
