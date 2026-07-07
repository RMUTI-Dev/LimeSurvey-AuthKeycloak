# AuthKeycloak — Keycloak OIDC Single Sign-On plugin for LimeSurvey

*[อ่านเป็นภาษาไทย](README.th.md) — this English version is the source of truth;
the Thai version is a translation and may lag behind after updates.*

A LimeSurvey authentication plugin that lets admin users log in via a Keycloak
identity provider (OpenID Connect) instead of (or alongside) LimeSurvey's
built-in username/password login.

Originally built for RMUT Isan's Keycloak deployment (`passport.rmuti.ac.th`),
generalized here so any institution running Keycloak can use it.

## How it works

1. Admin clicks "Login with SSO" (or the plugin is set as the default login method).
2. The plugin redirects to your Keycloak realm's authorization endpoint.
3. Keycloak redirects back with an authorization code.
4. The plugin exchanges the code for a token, fetches the user's profile via
   `/userinfo`, and finds or auto-creates the matching LimeSurvey user.
5. LimeSurvey session is established and the admin lands on the admin home page.

## Requirements

- LimeSurvey 6.0 or 7.0 (see `config.xml` `<compatibility>`)
- A Keycloak realm and client configured for this LimeSurvey instance (confidential
  client, standard flow enabled)

## Installation

1. Download the plugin zip from the [Releases](../../releases) page (already
   packaged with the correct `AuthKeycloak/` top-level folder — no need to
   rename anything after unzipping).
2. In LimeSurvey: **Configuration → Plugins → Upload & install** → select the zip.
3. Activate the plugin, then open its settings to configure Keycloak (below).

## Configuration

Set these from **Configuration → Plugins → AuthKeycloak → Settings**, or via
environment variables (env vars always take priority — useful for K8s/Docker
deployments where the DB-stored settings aren't convenient to manage):

| Setting                    | Env var                    | Default                        | Notes |
|-----------------------------|-----------------------------|---------------------------------|-------|
| Keycloak Base URL           | `KEYCLOAK_URL`              | *(none, must be set)*           | e.g. `https://sso.example.org` |
| Realm                       | `KEYCLOAK_REALM`             | *(none, must be set)*           | |
| Client ID                   | `KEYCLOAK_CLIENT_ID`         | *(none, must be set)*           | |
| Client Secret               | `KEYCLOAK_CLIENT_SECRET`     | *(none, must be set)*           | **Never commit this** — set it via the admin panel or an env var/secret store only |
| Verify SSL certificate      | `KEYCLOAK_VERIFY_SSL`        | **on**                          | Only turn off if your Keycloak endpoint uses a self-signed or internal CA cert — leaving verification on is strongly recommended |
| Make SSO the default login  | —                            | off                             | |
| Auto-create user on first login | —                        | on                              | |
| Grant survey-creation permission to auto-created users | — | off | |
| Allow initial admin (uid=1) to use SSO | —              | off                             | Keeping this off means uid=1 always has a local-password fallback even if SSO breaks |

## Security notes

- The client secret is only ever read from the LimeSurvey plugin settings
  storage or an environment variable — it is never hardcoded in this repo.
- SSL verification defaults to **on**. Only disable it if you understand the
  risk (it removes protection against man-in-the-middle attacks between
  LimeSurvey and your Keycloak server).
- Keycloak-side error details (e.g. token exchange failures) are logged
  server-side only, not shown to the browser — the callback endpoint is
  reachable before authentication completes, so it shouldn't leak IdP internals
  to an unauthenticated visitor.

## License

GNU General Public License v2.0 or later — see [LICENSE](LICENSE). Same license
as LimeSurvey itself, since this plugin is built against LimeSurvey's core
`AuthPluginBase` API.

## Credits

Originally developed by RMUTI OARIT (Office of Academic Resources and
Information Technology, Rajamangala University of Technology Isan).
