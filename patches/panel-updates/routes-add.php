<?php
/**
 * Routes to add for the key-renewal section.
 *
 * Put these THREE lines INSIDE the existing admin group in app/Config/Routes.php
 * (the `$routes->group('admin', ['filter' => 'admin'], ...)` block — the same
 * one that holds `downloads`, `security`, `maintenance`). They inherit the
 * `admin` filter, and KeyRenew re-checks the level itself as well.
 */

// --- add inside $routes->group('admin', ['filter' => 'admin'], function ($routes) { ... }) ---

$routes->get('keys/renew',          'KeyRenew::index');    // the section (page)
$routes->post('keys/renew/lookup',  'KeyRenew::lookup');   // find a key -> JSON
$routes->post('keys/renew/apply',   'KeyRenew::apply');    // apply the renewal -> JSON

// Resulting public URLs:
//   GET  /admin/keys/renew
//   POST /admin/keys/renew/lookup
//   POST /admin/keys/renew/apply
