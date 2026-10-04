# Releasing

1. Update `CHANGELOG.md` (move "Unreleased" to the new version) and set `<version>` in
   `appinfo/info.xml` – the one place for the version.
2. `make test lint` and `npm run lint`; optionally `npm run e2e` against a test instance.
3. Commit, tag and push:

   ```bash
   git tag v3.0.0 && git push origin main v3.0.0
   ```

   The release workflow checks that the tag matches `info.xml`, builds the tarball and
   attaches it to a GitHub release with the changelog section as notes.

## Signing and the Nextcloud App Store (not done yet)

The App Store only accepts signed apps:

1. Request a certificate for the app id `immich_search`: create a private key and CSR and open
   a pull request at https://github.com/nextcloud/app-certificate-requests (instructions in
   that repository). The key never leaves your machine.
2. Sign the build: `occ integrity:sign-app --privateKey=… --certificate=… --path=<unpacked app>`
   (writes `appinfo/signature.json`), then pack again. To automate: store key and certificate
   as GitHub secrets and add the step to `release.yml`.
3. Register the app at https://apps.nextcloud.com with the certificate and upload the
   signed tarball URL; add screenshots (blurred ones from `npm run e2e` work) to `info.xml`.

Until then: install from the GitHub release tarball (unpack into `custom_apps/`). Nextcloud
shows an "integrity check" note for unsigned custom apps; that is expected.
