# Enterprise Connected Site

This companion WordPress plugin connects a destination site to the Enterprise User Management portal. User provisioning is initiated and audited in the internal portal; the destination plugin creates least-privilege local accounts and provides sign-in through the internal portal.

## Connect a site

1. Install and activate this plugin on the destination WordPress site. In the internal portal, enable the OIDC provider under **Integrations → SSO / OIDC**.
2. On the destination, open **Settings → Enterprise Connected Site**, enter the internal WordPress site URL and the OIDC client ID and secret shown on the internal portal, then save.
3. In the internal portal, open **Integrations → Site Manager** and generate a one-time connection code. It expires in 15 minutes.
4. Paste the code into the destination’s **Connect to the internal Site Manager** section and click **Connect to internal portal**. The destination sends its site name, sign-in URL, provisioning URL, and OIDC callback. The internal portal registers the site, adds the callback to its allowed redirect list, and returns the provisioning key, which this plugin saves automatically.
5. In the internal Site Manager record, assign eligible site managers and optional job-path/group limits. Use **Test connection** to verify reachability and the saved key; the test does not create or modify accounts.

Manual setup is still available: copy the connection details displayed by this plugin into the internal Site Manager, then copy the generated key back into **Site provisioning key**.

The internal portal currently uses one OIDC client credential pair for connected sites. If that client secret is regenerated, update it in the settings of each connected site. The callback URL must match exactly and the connected sites must use HTTPS.

## What users see

Authorized managers get a **Site Manager** app in the internal apps menu. They choose a site, select users within their manager scope and that site's path/group rules, enter one reason for the provisioning batch, and submit. Site managers and internal IT/HR receive the WordPress Editor role; other provisioned accounts receive Subscriber. Administrator access is never assigned. Provisioned users get a **Connected Sites** app chooser that takes them directly to each site through internal sign-in. The destination only accepts an account already provisioned and linked to that exact OIDC issuer and subject.

After provisioning, internal profile edits are queued and pushed automatically to every connected site where the user is provisioned. Sync includes core account details, the profile image, active/inactive status, user classifications, and fields configured as public in the internal profile settings. Users without a custom profile photo receive a generated initials-and-color JPEG; its versioned image URL updates when the initials or color change. A one-time background catch-up replaces previously synced placeholder SVGs with JPEGs. Terminated users are marked inactive externally, their active sessions are revoked, and further password or internal-portal sign-ins are blocked. A one-time background catch-up applies this to users who were already terminated before automatic sync was enabled. The internal accounts `superadmin` and `josephsimpson` are exempt from automatic deactivation. Private HR metadata, credentials, and unrelated internal records are not exported. Failed pushes are retried automatically.

The destination plugin adds a **Sign in with the internal portal** link to its WordPress login form. When an internal user is provisioned, the destination first matches an existing account by the exact email address if that account has no central identity link; it then links the local account to the internal OIDC identity instead of creating a duplicate. Accounts already linked to any central identity are never claimed based on email. The internal Site Manager's **Open site with internal sign-in** button provisions or links the manager's own destination account if necessary, then starts SSO.
