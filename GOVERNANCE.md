# Governance

Repository maintainers are responsible for releases, security coordination, compatibility decisions, and final merge approval.

## Decisions

Maintainers evaluate changes against Moodle coding standards, supported-version compatibility, privacy obligations, security, upgrade safety, and documented activity behaviour. A pull request or issue is the normal record for a technical decision.

Breaking changes require a documented migration or upgrade path. Changes that affect group isolation, permissions, backup and restore, or personal data require explicit test coverage and manual validation guidance.

## Contributions

Contributors may report defects, propose changes, and submit pull requests under [CONTRIBUTING.md](CONTRIBUTING.md). Maintainers may decline work that is out of scope, lacks validation, duplicates an existing proposal, or cannot be supported across the declared Moodle range.

## Releases

Maintainers decide when to publish releases. A release requires a version update, relevant documentation, automated checks, and staging validation as described in [docs/release.md](docs/release.md).
