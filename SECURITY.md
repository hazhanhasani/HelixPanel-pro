# Security Policy

For security reports, please use GitHub's private **Report a vulnerability** option if available, instead of posting exploit details or credentials in public issues.

## Distribution trust

- Only download the official installer from this repository.
- The actual HelixPanel application is distributed as a compiled and signed release from the vendor's HTTPS endpoint, **not** as source in this repository.
- The installer checks an Ed25519-signed manifest and SHA-256 artifact digest before installation.
- Installation tokens and customer license files must never be posted in public GitHub issues.
- The vendor's license-signing and release-signing **private keys** must remain off GitHub.
- Review installer changes before running with root permissions.

## Status

The system remains an integration/developer preview. Do not assume that the public installer, license activation server or VPN engine has been deployed for production.
