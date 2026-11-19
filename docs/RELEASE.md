# Releasing

1. Update `CHANGELOG.md` (move "Unreleased" to the new version) and set `<version>` in
   `appinfo/info.xml` – the one place for the version.
2. `make test lint` and `npm run lint`; optionally `npm run e2e` against a test instance.
3. Commit, tag and push:

   ```bash
   git tag v4.5.0 && git push origin main v4.5.0
   ```

   The release workflow checks that the tag matches `info.xml`, builds the tarball and
   attaches it to a GitHub release with the changelog section as notes.

## Signing and the Nextcloud App Store

The release workflow does it all on every tag:

1. checks `appinfo/info.xml` against the App Store schema (`info.xsd`; CI does the same on
   every push),
2. builds the tarball and signs the code with `occ integrity:sign-app` from a fresh
   Nextcloud server checkout (writes `appinfo/signature.json`),
3. signs the archive (`openssl dgst -sha512`), attaches it to the GitHub release and posts
   the release to `https://apps.nextcloud.com/api/v1/apps/releases`.

Started by hand (Actions → Release → Run workflow) it is a dry run: build and sign only,
the signed tarball is kept as a workflow artifact.

What it needs:

- `.github/appstore/glimpse.crt` – the certificate from
  https://github.com/nextcloud/app-certificate-requests (`glimpse/glimpse.crt`), public.
- Secret `APP_PRIVATE_KEY` – the private key behind it (`~/.nextcloud/certificates/glimpse.key`
  on the maintainer's machine; never committed).
- Secret `APPSTORE_TOKEN` – apps.nextcloud.com → Account → API token of the app owner.

The app itself was registered once at apps.nextcloud.com (Account → Register app) with the
certificate and the signature of the app id:
`echo -n glimpse | openssl dgst -sha512 -sign glimpse.key | openssl base64 -A`.

The `<screenshot>` entries in `info.xml` (at most 10 allowed by the schema) point to
`docs/screenshots/` on `main` (raw.githubusercontent.com); sources and licenses of the
pictures in them are in `docs/screenshots/CREDITS.md`.

Local alternative to the workflow: `make sign KEY=glimpse.key CERT=.github/appstore/glimpse.crt`
signs inside the local Nextcloud container (`build/glimpse-<version>-signed.tar.gz`).
