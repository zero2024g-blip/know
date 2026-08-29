<?php
/**
 * ONE-TIME Argon2id password-hash generator.
 *
 * Only needed when you remove the legacy login bridge and a check finds an
 * account that is not yet on Argon2id (see SECURITY.md -> "Removing the legacy
 * password bridge"). It turns a temporary password you type into a hash you can
 * paste into the users table, so that account can log in again.
 *
 * HOW TO USE
 *   1. Upload this file to your web root via the File Manager.
 *   2. Open  https://YOUR-PANEL/hash-pass.php  once.
 *   3. Type a temporary password, press the button, copy the hash.
 *   4. In the database:
 *        UPDATE users SET password='<hash>' WHERE username='<name>';
 *      Give the user the temporary password; they change it after logging in.
 *   5. DELETE THIS FILE IMMEDIATELY.
 *
 * It never reads or writes the database itself — you paste the value by hand,
 * so a file left behind cannot be triggered by a stranger to change a password.
 * It refuses to run once a marker file exists next to it.
 */

$marker = __DIR__ . '/.hash-pass-used';

if (file_exists($marker)) {
    http_response_code(410);
    exit('This tool has already been used. Delete hash-pass.php and .hash-pass-used.');
}

$hash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw'])) {
    $pw = (string) $_POST['pw'];
    if ($pw === '' || strlen($pw) < 6) {
        $err = 'Enter at least 6 characters.';
    } else {
        $hash = password_hash($pw, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2,
        ]);
        // Leave a marker so the tool can only be used once.
        @file_put_contents($marker, date('c'));
    }
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Password hash</title>
<style>
  body{font:15px/1.5 system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 16px;color:#222}
  code{display:block;word-break:break-all;background:#f4f4f5;padding:12px;border-radius:8px;margin:12px 0}
  input{font:15px monospace;padding:8px;width:100%;box-sizing:border-box}
  button{font:15px system-ui;padding:8px 16px;margin-top:10px;cursor:pointer}
  .err{color:#b00}
</style></head>
<body>
<h2>Argon2id password hash</h2>
<?php if ($hash !== null): ?>
  <p>Copy this into the <code>password</code> column for the account, then <b>delete this file</b>:</p>
  <code><?= htmlspecialchars($hash, ENT_QUOTES) ?></code>
  <p>SQL:</p>
  <code>UPDATE users SET password='<?= htmlspecialchars($hash, ENT_QUOTES) ?>' WHERE username='NAME';</code>
  <p>This tool is now locked. Delete <code>hash-pass.php</code> and <code>.hash-pass-used</code>.</p>
<?php else: ?>
  <?php if (!empty($err)): ?><p class="err"><?= htmlspecialchars($err, ENT_QUOTES) ?></p><?php endif; ?>
  <form method="post">
    <label>Temporary password<br><input type="text" name="pw" autofocus autocomplete="off"></label>
    <button type="submit">Make hash</button>
  </form>
<?php endif; ?>
</body></html>
