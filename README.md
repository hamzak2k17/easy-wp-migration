# Easy WP Migration

Lightweight WordPress site migration and backup tool. Export your entire site to a single `.ezmig` archive file, import from `.ezmig`, pull directly from a URL (paste a migration link and the destination pulls from the source), and manage server-side backups with list/restore/delete.

**Current status: In active development — pre-1.0 release. Not ready for production use.**

## Requirements

- PHP 8.1+
- WordPress 6.2+
- PHP ZipArchive extension

## Feature Roadmap

- [x] Phase 1: Plugin skeleton and admin UI shell
- [x] Phase 2: Archive layer (archiver interface, zip implementation, metadata, factory)
- [x] Phase 3: State machine, job framework, and chunked AJAX foundation
- [x] Phase 4: Database exporter (chunked SQL dump)
- [x] Phase 5: File exporter (themes, plugins, media, wp-content)
- [x] Phase 6: Importer (database restore + file extraction)
- [x] Phase 7: Admin UI for export and import with progress
- [x] Phase 8: Backup management (list, restore, delete)
- [ ] Phase 9: Download and file-size handling
- [ ] Phase 10: Pull from URL (migration link generation + remote pull)
- [ ] Phase 11: Polish, testing, and WordPress.org submission

## License

GPLv2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
