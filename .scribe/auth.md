# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_BEARER_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Obtain a token from <code>POST /auth/login</code>. Tokens expire after 60 minutes and can be refreshed for up to 14 days via <code>POST /auth/refresh</code>.
