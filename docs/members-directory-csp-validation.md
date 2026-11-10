# Members directory CSP fix and navigation validation

Actual path: Members navigation `/index.php?page=members` redirects to `/member.php`. The **Existing BMA records** table is `#people-directory`, initialized by `/assets/js/people-directory.js`, with data from `/api/people.php`. `/api/membership-members.php` belongs to the separate Membership dashboard and is unchanged.

The live host sends two Content-Security-Policy headers. The application policy allows the DataTables CDN, but the additional hosting policy excludes that origin. Browsers enforce both policies, so the CDN JavaScript/CSS are blocked. With `DataTable` undefined, the initializer displays the reported error and exits before its API request. This is why no Membership-dashboard endpoint request was relevant.

The fix serves the same DataTables 3.1.3 Bootstrap integration bundle locally, for the directory and its member-profile transaction table. It retains the upstream copyright and MIT license; only redundant trailing blank lines were removed from the vendor distributions for repository whitespace checks. No CSP, authentication, API, query, schema or permission changes were made. The sidebar skips only Membership in addition to the already-hidden Revenue entry; Membership metadata, code, permissions and direct routes remain intact.

Upstream assets: `https://cdn.datatables.net/v/bs5/dt-3.1.3/datatables.min.js` and matching `.css`. Official license: `https://raw.githubusercontent.com/DataTables/DataTablesSrc/3.1.3/license.txt`.

| Check | Result |
| --- | --- |
| Actual Members route and API | PASS — browser follows Members to `/member.php` and requests `/api/people.php`; directory does not use the Membership dashboard endpoint. |
| Live CSP reproduced | PASS — authenticated disposable application responses include both actual live policies, without relaxing either. Directory has zero CSP violations and uses same-origin DataTables assets. |
| Complete paging | PASS — all 328 restored BMA people reached exactly once; 294 people without member records remain accessible, Members Only returns the 34 actual records. |
| Page sizes and controls | PASS — 25/50/100/250 rows, clickable Next/Previous past row 50, header sorting and server-side total/filtered counts. |
| Search and filters | PASS — name/BMA search, member status and Has/No Stripe customer filtering; existing endpoint/query behavior retained. |
| Profiles and Stripe editor | PASS — profile opens, DataTables is local, administrator editor opens and an existing customer can be previewed. No confirmation or association write was submitted. |
| Navigation and authorization | PASS — Membership absent for admin/viewer; Members remains available. Authorized direct `/membership` still returns 200; viewer editor access returns 403 and anonymous directory API returns 401. |
| Browsers and layout | PASS — 37 Chromium and 38 Firefox checks, including 1440px/390px directory layout and both live CSP policies. Explicit response/element waits were used for Firefox preview navigation; prior passed checks were reused. |
| Syntax / regression scope | PASS — PHP lint on the three modified PHP files; vendored JavaScript syntax checked. Existing SQL/matching/import tests were not rerun because those implementations are unchanged. |

Tests reused the existing disposable restored production dataset. Only local test-login accounts/audit entries were created. No production database, transaction, subscription, identity, reconciliation or permission records were modified. Production file backups, selective deployment and read-back hashes are recorded in the private deployment manifest. No Membership dashboard implementation was changed.
