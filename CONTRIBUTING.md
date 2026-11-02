# Contributing

Contributions are welcome: bug reports, documentation fixes, drivers for other captive portals and
code changes.

## Before you start

- Read [CONCEPT.md](CONCEPT.md) for architecture and decisions. For larger changes, or anything that
  changes a decision recorded there, open an issue first to agree on the approach.
- Security issues: do not open a public issue, see [SECURITY.md](SECURITY.md).

## Pull requests

- Work on a feature branch in your fork and open the pull request against `main`.
- Keep a pull request focused on one topic; describe what changes and why.
- Everything in the repository is English: code, comments, docs, commit messages, logs.
  User-facing strings go into `lang/{en,de,fr,it}` (all four languages).
- Update the docs (README, `docs/`) when behaviour changes; decisions go into CONCEPT.md only.
- Run before pushing:

  ```sh
  php artisan test
  vendor/bin/pint --test
  ```

- Optional: the CHR harness in `tests/routeros` runs the generated RouterOS scripts against a RouterOS
  CHR (see [tests/routeros/README.md](tests/routeros/README.md)). Useful when you change the RouterOS
  driver; not required for a pull request, maintainers can run it in CI.

## Anonymity and secrets

- Use generic example values only (`portal.example.com`, `10.0.0.0/24`, "site A"). No personal names,
  real domains, public IP addresses, host names, email addresses or descriptions of real
  infrastructure in code, docs, tests or commit messages.
- Never commit secrets: no real tokens, passwords, keys, certificates or `.env` files, also not in
  tests, CI or examples. Use obvious fixture values.

## License

This project is licensed under the GNU Affero General Public License v3.0 or later
([LICENSE](LICENSE)). By submitting a contribution you agree that it is licensed under the same terms.
