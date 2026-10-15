# HelixPanel Pro — Public Installer

**Official installation and update repository for HelixPanel.**

This repository is public by design. Its contents can be viewed or forked. **The proprietary application source code is not stored here**; it lives in a separate private repository.

## Delivery model

```text
Private Core -> private build & signature -> vendor HTTPS release distributor
                                              ^
Public installer -> license check -> signed manifest / SHA-256 -> atomic install
```

- The public installer must not require GitHub Personal Access Tokens.
- Customers receive a signed, installation-bound license.
- Only the vendor's release distributor provides compiled binaries through short-lived download URLs.
- The installer verifies **Ed25519 signatures** and SHA-256 checksums before deploying.
- A failed health check triggers a rollback attempt.
- Source code is never returned by the installer or distributor.

## Installation status

**Developer preview — installation is not yet open.** The vendor must first deploy the licensing/distribution API, configure TLS, publish real **public-only** verifier keys, and release a tested production binary. Do **not** use the preview as a live VPN service.

Once the installer files and public `config.json` are released, the supported customer flow will be:

```bash
curl -fsSLo installer.py https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/installer.py
# Review installer.py before executing.
sudo python3 installer.py identity
# Get your signed license from the vendor and save it to license.key
sudo python3 installer.py install --config config.json --license-file license.key
sudo python3 installer.py status
```

Do not use `curl | bash`. Keep your license private, and place the panel behind a correctly configured HTTPS reverse proxy.

## Source and third-party licenses

Only the installation client and documentation are open for inspection. No claim is made that distributed Go/Linux binaries cannot be reverse-engineered; signed releases and license enforcement reduce unauthorized distribution but cannot make binaries impossible to copy.

Security reports: open a GitHub security advisory instead of publishing keys or exploitable details in an issue.

Copyright (c) 2026 HelixPanel. All rights reserved for the proprietary application.
