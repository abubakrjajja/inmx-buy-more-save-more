# Updates

Sites check GitHub Releases and offer the update on the normal WordPress Plugins
screen. Nothing custom appears in wp-admin.

## Shipping a release

1. Bump the version in **two** places in `inmx-buy-more-save-more.php`:
   - the `* Version:` header line
   - `define( 'INMX_BMSM_VERSION', ... )`
2. Commit and tag:
   ```bash
   git commit -am "Release 1.0.1"
   git tag v1.0.1
   git push origin main --tags
   ```
3. GitHub Actions verifies the header matches the tag, lints every PHP file,
   builds `inmx-buy-more-save-more.zip`, checks the zip's root folder is correct,
   and publishes it as a release asset.
4. Sites pick it up within 12 hours, or immediately via **Check for updates** on
   the plugin's row on the Plugins screen.

The version check in step 3 exists because of a specific failure: if the tag says
1.0.1 and the plugin header still says 1.0.0, WordPress installs the update,
re-reads 1.0.0, and offers the same update again on every check, forever.

## Credentials

**The repository is public, so sites need no credentials at all.** Nothing to
install, nothing to rotate, nothing to leak. This is the reason to keep it that
way unless there is a concrete reason not to.

Everything in the rest of this section applies only if the repo is ever made
private again.

## Private repository setup

For a private repo, each site needs a read-only token.

### Create the token

GitHub → Settings → Developer settings → **Fine-grained personal access tokens**

- **Repository access:** Only select repositories → `inmx-buy-more-save-more`
- **Permissions:** Repository permissions → **Contents: Read-only**
- **Expiration:** set one, and diarise the renewal

Read-only on one repo is the whole grant. It cannot push, cannot touch other
repos, and cannot read anything else in the account.

### Install the token on a site

Add to `wp-config.php`, above the `/* That's all, stop editing! */` line:

```php
define( 'INMX_BMSM_GH_TOKEN', 'github_pat_...' );
```

**Never put the token in a plugin file.** Two reasons, both of which have bitten
real projects:

1. A token committed to the repository is caught by GitHub secret scanning and
   revoked automatically. Updates then fail on every site at once, silently,
   and the only symptom is that new versions stop appearing.
2. A token in the plugin folder sits in the web root of every site running it,
   and inside every backup archive. Read access to the token is read access to
   the private source, which is the thing the private repo was protecting.

### Verify it works

```bash
wp eval '$s = new INMX_BMSM_GitHub_Source( INMX_BMSM_GH_OWNER, INMX_BMSM_GH_REPO ); var_dump( $s->get_latest() );'
```

Expect an array with `version` and `package`. `NULL` means the lookup failed;
turn on `WP_DEBUG` and check the log, or read the stored reason:

```bash
wp eval 'var_dump( get_site_transient( "inmx_bmsm_update_error" ) );'
```

A 404 on a private repo nearly always means the token is missing, expired, or
scoped to the wrong repository. It rarely means the release is absent.

## Turning updates off

On a staging clone, in `wp-config.php` or an mu-plugin:

```php
add_filter( 'inmx_bmsm_update_source', '__return_null' );
```

The plugin then never contacts GitHub and never offers an update.

## Pointing a site at a different repo

```php
define( 'INMX_BMSM_GH_OWNER', 'someone-else' );
define( 'INMX_BMSM_GH_REPO',  'their-fork' );
```

## Moving off GitHub later

The update client does not know GitHub exists. It talks to an
`INMX_BMSM_Update_Source`, which has three methods: `get_latest()`,
`download()`, `get_label()`.

To serve updates from your own endpoint - which is what you want once you need
per-site licensing, revocation, or install counts, and what removes the need for
any site to hold a GitHub credential - write one class implementing that
interface and swap it in:

```php
add_filter( 'inmx_bmsm_update_source', function () {
    return new INMX_BMSM_Licence_Server_Source( 'https://updates.inmixio.com', get_option( 'inmx_licence_key' ) );
} );
```

Nothing else in the plugin changes. Transient caching, the update row, the
details modal, the folder-name normalisation and the download hand-off all
continue to work, because none of them are GitHub-specific.

## Caching and load

- Successful lookups are cached for **12 hours**, per site.
- Failed lookups are cached for **1 hour**, so an unreachable API does not
  trigger an outbound request on every admin page load.
- Lookups never run on front-end page views - only in wp-admin, WP-Cron and
  WP-CLI. A customer's page load never waits on GitHub.
- The check timeout is 10 seconds; downloads get 120.
