# Introduction

REST API for the Niya Umrah Equb digital rotating-savings platform. Covers member participation, private Group Equbs, contribution collection and settlement, the agent network, and platform administration.

<aside>
    <strong>Base URL</strong>: <code>https://cms.niya-et.com</code>
</aside>

    This reference is generated directly from the running codebase, so it always reflects what the
    API actually does rather than what it was once documented to do.

    ## Base URL

    All paths below are relative to `https://cms.niya-et.com/api`.

    ## Authentication

    The API uses signed JSON Web Tokens presented as a bearer credential. There are no API keys.

    1. `POST /auth/send-otp` — a four-digit code is sent by SMS. Keep the returned `verificationId`.
    2. `POST /auth/verify-otp` — confirm the code.
    3. `POST /auth/register` — create the account. **This returns `token: null` by design.**
    4. `POST /auth/login` — exchange credentials for a usable bearer token.

    Send it on every protected request as `Authorization: Bearer <token>`.

    Access tokens live 60 minutes and may be refreshed for up to 14 days from issue via
    `POST /auth/refresh`. Refresh proactively rather than waiting for a 401.

    ## Roles

    Every account holds exactly one role, and the roles do not overlap. A `member` token receives
    HTTP 403 from every route under `/agent` and `/admin`, and vice versa. An integration needing
    two capabilities needs two accounts.

    ## Response shape

    Most endpoints return `status`, a human-readable `message`, and the payload under `data`.
    Paginated collections add a sibling `meta` object with `current_page`, `last_page`, `per_page`
    and `total`. A few older endpoints deviate; treat the HTTP status code as authoritative and the
    presence of the expected payload key as the success signal.

    ## Errors

    Errors return `status: "error"` with a `message` written for the end user. Validation failures
    add a field-keyed `errors` map. A `422` may be either a schema failure (carries `errors`) or a
    business-rule refusal (carries only `message`).

    ## Money

    All amounts are Ethiopian Birr with two decimal places. Some endpoints return them as decimal
    strings and others as JSON numbers, so parse defensively and use a decimal type for arithmetic.

    ## Payments

    Every contribution is collected through the Chapa gateway. A contribution is created in the
    `pending` state and becomes `paid` only once the gateway callback has been received and
    independently verified. **Neither the 201 response nor the payer's return from the hosted
    checkout page is proof of settlement** — poll the payment record.

