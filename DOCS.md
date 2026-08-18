# API Documentation — Setup and Deployment

Generated reference for the Niya Umrah Equb API, built with
[Scribe](https://scribe.knuckles.wtf/laravel) v5. Scribe reads the routes, form requests and
controller docblocks and emits three artefacts from one command:

| Artefact | Path | Use |
| --- | --- | --- |
| Web UI | `public/docs/index.html` | Human-readable reference with try-it-out |
| OpenAPI 3.0 spec | `public/docs/openapi.yaml` | Client generation, API gateways, Swagger tooling |
| Postman collection | `public/docs/collection.json` | Hand to a partner for immediate testing |

---

## 1. The `/api` 404 — fix this first

If `https://cms.niya-et.com/api` returns
`{"status":"error","message":"No resource exists at that path.","documentation":null}`,
the application is running the current `bootstrap/app.php` but a **stale route and config
cache**. Two symptoms confirm it:

- the JSON error shape proves `bootstrap/app.php` is live (it is never cached);
- `"documentation": null` proves `config/api.php` is missing from the cached config;
- the 404 itself proves `routes/api.php` is missing from the cached route table.

Run **on the server**, from the application root:

```bash
php artisan optimize:clear     # clears config, route, view, event and compiled caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Then verify:

```bash
php artisan route:list --path=api | head -20   # must list "GET  api" and "GET  api/health"
curl -s https://cms.niya-et.com/api | head
curl -s https://cms.niya-et.com/api/health
```

If `route:list` does **not** show `GET api`, the caches were not the problem and the
deployment is incomplete. Confirm these four files are present on the server:

- `config/api.php` *(new)*
- `app/Http/Controllers/Api/ServiceController.php` *(new)*
- `routes/api.php` *(modified — service routes near the top)*
- `bootstrap/app.php` *(modified — JSON exception rendering)*

> **Never edit files directly on the server.** Deploy, then clear caches. Any deploy script
> that runs `config:cache` must run it *after* the new files land, not before.

---

## 2. Environment

Add to `.env` on every environment. All have working defaults, so nothing breaks if they are
missing — but the values shown in the service index and the docs come from here.

```dotenv
APP_NAME="Niya Umrah Equb"

API_SERVICE_NAME="Niya Umrah Equb API"
API_VERSION="2.1"
API_DOCS_URL="https://cms.niya-et.com/docs"
API_SUPPORT_EMAIL="support@niya-et.com"

SCRIBE_BASE_URL="https://cms.niya-et.com"
```

`APP_NAME` is currently `Laravel`. It leaks into mail headers and framework output — set it.

---

## 3. Generating the documentation

```bash
php artisan scribe:generate
```

Output lands in `public/docs/`. **Commit that directory.**

### Why output is static, not a live route

Scribe is a `require-dev` dependency. A production `composer install --no-dev` leaves it
absent, so the `laravel` docs type would never register its `/docs` route and the page would
404 in production — the same class of failure as the `/api` issue above.

`config/scribe.php` therefore sets `'type' => 'static'`. The result is plain HTML, YAML and
JSON served directly by the web server with no runtime dependency on Scribe. Generate
locally or in CI, commit, deploy.

Docs are then served at:

- `https://cms.niya-et.com/docs`
- `https://cms.niya-et.com/docs/openapi.yaml`
- `https://cms.niya-et.com/docs/collection.json`

### Regenerate whenever the contract changes

Route added or removed, validation rule changed, response reshaped → regenerate and commit.
A stale generated reference is worse than none, because it is trusted.

---

## 4. Annotating controllers

Scribe infers URL parameters, query parameters and body parameters automatically from route
definitions and form request rules. What it cannot infer is grouping, auth status and
example responses. Those come from docblocks.

### Class-level tags

Put these on the controller class. They apply to every method in it.

```php
/**
 * One-paragraph description of what this controller is for.
 *
 * @group Member · Contributions
 * @authenticated
 */
class EqubPaymentController extends Controller
```

Omit `@authenticated` on controllers whose routes are public.

### Already annotated

| Controller | Group |
| --- | --- |
| `Api/ServiceController` | Service |
| `Api/AuthController` | Authentication |
| `Api/Member/EqubPaymentController` | Member · Contributions |
| `Api/Member/EqubMembershipController` | Member · Memberships |
| `Api/Member/MyEqubGroupController` | Member · Group Equb |
| `Api/Admin/EqubPaymentController` | Admin · Contributions |

### Still to annotate

Add the class docblock shown above to each of these, using the group in the right column.
The group names match the display order already configured in `config/scribe.php`; any group
not listed there is appended alphabetically, so new names are safe but unordered.

| Controller | Group | `@authenticated` |
| --- | --- | --- |
| `Api/SettingsController` | Platform Configuration | no |
| `Api/FaqController` | Platform Configuration | no |
| `Api/ExchangeRateController` | Platform Configuration | no |
| `Api/PromoController` | Platform Configuration | no |
| `Api/UserController` | Account | yes |
| `Api/Member/EqubPackageController` | Member · Equb Catalogue | yes |
| `Api/Member/EqubGroupController` | Member · Equb Catalogue | yes |
| `Api/Member/EqubDrawController` | Member · Draws | yes |
| `Api/Member/PaymentController` | Member · Contributions | yes |
| `Api/Member/AgentInfoController` | Member · Directory | yes |
| `Api/Member/MemberDirectoryController` | Member · Directory | yes |
| `Api/Member/EqubGroupInvitationController` | Member · Invitations | yes |
| `Api/Agent/AgentDashboardController` | Agent | yes |
| `Api/Agent/AgentMembersController` | Agent | yes |
| `Api/Agent/AgentPaymentsController` | Agent | yes |
| `Api/Agent/AgentCommissionsController` | Agent | yes |
| `Api/Admin/UserManagementController` | Admin · Accounts | yes |
| `Api/Admin/MemberManagementController` | Admin · Members | yes |
| `Api/Admin/EqubPackageController` | Admin · Equb Packages | yes |
| `Api/Admin/EqubGroupController` | Admin · Equb Groups | yes |
| `Api/Admin/EqubMembershipController` | Admin · Memberships | yes |
| `Api/Admin/EqubDrawController` | Admin · Draws | yes |
| `Api/Admin/MemberEqubGroupController` | Admin · Group Equb Moderation | yes |

Until a controller is annotated its endpoints still appear, grouped under **Endpoints**.
Nothing breaks; the reference is just less organised.

### Method-level tags

The first docblock line becomes the endpoint title, the rest the description.

```php
/**
 * Settle several contributions at once
 *
 * Creates one contribution record per membership and ties them to a single gateway
 * charge under a shared batch reference.
 *
 * @response 201 {
 *   "status": "success",
 *   "reference": "EQUB-B7KD2M4XQA",
 *   "total_amount": 8103.00
 * }
 * @response 403 {
 *   "status": "error",
 *   "message": "Some of those contributions are not yours to pay."
 * }
 */
```

Useful tags: `@response`, `@responseFile`, `@queryParam`, `@bodyParam`, `@urlParam`,
`@unauthenticated`, `@subgroup`.

`AuthController` needs `@unauthenticated` on `sendOtp`, `checkUser`, `verifyOtp`, `register`,
`login`, `resetPassword`, `deleteAccountByPhone` and `refresh`, since the rest of that
controller is protected.

---

## 5. Response calls are disabled

`config/scribe.php` removes the `ResponseCalls` strategy. That strategy executes real HTTP
requests during generation; because nearly every route here requires a bearer token, it would
capture `401` responses and publish them as the documented output. Explicit `@response` tags
are deterministic and correct.

If you ever re-enable it, set `SCRIBE_AUTH_KEY` to a valid token and restrict it to safe
routes — never to `POST *`, which would create real Equb records and real payment rows.

---

## 6. Handing docs to a partner

1. `php artisan scribe:generate`
2. Commit `public/docs/`, deploy, clear caches.
3. Send the partner:
   - the URL `https://cms.niya-et.com/docs`
   - `public/docs/collection.json` for Postman
   - `public/docs/openapi.yaml` if they generate their own client

The Postman collection and OpenAPI spec are generated from the same source as the web page,
so the three can never disagree.
