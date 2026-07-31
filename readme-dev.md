# Developer documentation

## Creating a release

1. Update the version in `appinfo/info.xml` (e.g., to `0.1.2`).
2. Update `CHANGELOG.md` with the new version and date. Add the diff link at the bottom of the file with the existing links.
3. Commit all changes:
   `git add . && git commit -m "Release v0.1.2"`
4. Build the appstore package:
  `make appstore`
5. Verify the archive contents (check for accidental temp/config files):
   `tar -tzf build/artifacts/appstore/user_vo.tar.gz`
   Look for any files that shouldn't be included (temp_config.php, test files, credentials, etc.)
6. Test the built appstore archive locally, using whichever nextcloud-docker-dev container you
   currently test against as `<version>` below (e.g. `stable33`):
   - Temporarily comment out the apps-extra volume mount in `docker-compose.yml` for that container.
   - Recreate the container: `docker compose up -d --force-recreate <version>`
   - Copy package to shared directory: `cp build/artifacts/appstore/user_vo.tar.gz data/shared/`
   - Extract and install: `docker compose exec <version> tar -xzf /shared/user_vo.tar.gz -C /var/www/html/apps/ && docker compose exec <version> occ app:enable user_vo`
   - Verify functionality, then clean up: `docker compose exec <version> occ app:disable user_vo && docker compose exec <version> rm -rf /var/www/html/apps/user_vo`
   - Restore volume mount in `docker-compose.yml` and recreate container: `docker compose up -d --force-recreate <version>`
7. Push changes to remote:
   `git push origin main`
8. (If not already authenticated) `gh auth login`
9. Create a new release and tag:
   `gh release create v0.1.2`
10. Upload the package to the release:
   `gh release upload v0.1.2 build/artifacts/appstore/user_vo.tar.gz`
11. Create the signature:
   `openssl dgst -sha512 -sign ~/.nextcloud/certificates/user_vo.key build/artifacts/appstore/user_vo.tar.gz | openssl base64`
12. Submit the release on https://apps.nextcloud.com/developer/apps/releases/new, using the signature and the GitHub release asset link:
    `https://github.com/bkhoesie/user_vo/releases/download/v0.1.2/user_vo.tar.gz`
