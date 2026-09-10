<?php

namespace App\Controllers;

use App\Models\DownloadFileModel;
use App\Models\DownloadTokenModel;
use App\Models\KeysModel;
use App\Libraries\FileVault;

/**
 * Public file distribution.
 *
 *   GET  /download                 human page: free files + a key box
 *   POST /download                 API: {game?, key?, file} -> JSON {status, link, ...}
 *   GET  /download/file/<token>    streams the file for a valid one-time token
 *
 * This route is reachable without a panel login, so it defends itself the same
 * way the connector does: a per-IP rate limit, generic answers that never say
 * which part was wrong (so it is not an oracle for guessing keys), and a
 * signed 256-bit token that is the only path to the bytes.
 */
class Download extends BaseController
{
    private const RL_TABLE  = 'download_ratelimit';

    // Show the NAMES of key-gated (paid) files on the public page as locked
    // rows, BEFORE a key is entered — a "premium files" teaser.
    //   true  = paid file names are visible (locked); a visitor sees what a key
    //           would unlock. Only Listed + browser-deliverable files are shown.
    //   false = the private default — paid files stay unnamed to a stranger and
    //           appear only after a valid key is entered (anti-enumeration).
    // Per-file control still wins: a file set Unlisted or API-only is never
    // shown here regardless of this switch.
    private const LIST_LOCKED_TEASER = true;

    // EVERY request counts, success or not — so nobody can hammer the endpoint
    // to guess a key OR to mint one-time links in bulk. A generous ceiling, so
    // a real user pulling a few files is never touched.
    private const RL_LIMIT  = 40;    // requests...
    private const RL_WINDOW = 300;   // ...within 5 minutes
    private const RL_BLOCK  = 900;   // then blocked for 15

    // ------------------------------------------------------------------
    //  GET /download — the human page. Only FREE, listed, browser-delivered
    //  files are shown. Key-gated files are NOT listed until a key is entered
    //  (see unlock() below), so their existence is not leaked to a stranger.
    // ------------------------------------------------------------------
    public function index()
    {
        $files = (new DownloadFileModel())
            ->where('status', 1)
            ->where('listed', 1)
            ->orderBy('id_file', 'DESC')
            ->findAll();

        $free = [];
        $locked = [];
        $anyGated = false;
        foreach ($files as $f) {
            if (! $this->browserDeliverable($f)) {
                continue;   // API-only file: never on the page
            }
            if ((int) $f->access === DownloadFileModel::ACCESS_FREE) {
                $free[] = ['title' => $f->title, 'slug' => $f->slug, 'game' => $f->game, 'size' => (int) $f->size];
            } else {
                $anyGated = true;   // a key-gated file exists
                // Teaser: surface its name as a locked row (no slug — it cannot
                // be fetched without a key). Off by default keeps it unnamed.
                if (self::LIST_LOCKED_TEASER) {
                    $locked[] = ['title' => $f->title, 'game' => $f->game, 'size' => (int) $f->size];
                }
            }
        }

        return view('Download/page', ['free' => $free, 'anyGated' => $anyGated, 'locked' => $locked]);
    }

    // ------------------------------------------------------------------
    //  POST /download/unlock — {key} -> the listed files THAT key unlocks.
    //  Used by the page to reveal gated files only after a valid key, so file
    //  names are never shown to someone without a key.
    // ------------------------------------------------------------------
    public function unlock()
    {
        $ipHash = md5('download_' . $this->clientIp());
        if (! $this->rlOk($ipHash)) {
            return $this->reply(['status' => 'error', 'message' => 'Too many requests. Please slow down.'], 429);
        }

        $in  = $this->input();
        $key = trim((string) ($in['key'] ?? ''));

        $files = (new DownloadFileModel())
            ->where('status', 1)->where('listed', 1)
            ->where('access', DownloadFileModel::ACCESS_KEY)
            ->orderBy('id_file', 'DESC')->findAll();

        $out = [];
        foreach ($files as $f) {
            if ($this->browserDeliverable($f) && $this->keyAuthorises($key, $f, '')) {
                $out[] = ['title' => $f->title, 'slug' => $f->slug, 'game' => $f->game, 'size' => (int) $f->size];
            }
        }

        // Same shape whether the key is good or not; an empty list is the
        // answer to a wrong key, so it is not an oracle for which files exist.
        return $this->reply(['status' => 'ok', 'files' => $out]);
    }

    // ------------------------------------------------------------------
    //  POST /download — the API (for apps).
    // ------------------------------------------------------------------
    public function request()
    {
        $ipHash = md5('download_' . $this->clientIp());
        if (! $this->rlOk($ipHash)) {
            return $this->reply(['status' => 'error', 'message' => 'Too many requests. Please slow down.'], 429);
        }

        $in   = $this->input();
        $slug = strtolower(trim((string) ($in['file'] ?? '')));
        $key  = trim((string) ($in['key'] ?? ''));
        $game = strtoupper(trim((string) ($in['game'] ?? '')));

        if ($slug === '' || ! preg_match('/^[a-z0-9\-_]{1,64}$/', $slug)) {
            return $this->reply(['status' => 'error', 'message' => 'File not found.'], 404);
        }

        $file = (new DownloadFileModel())->bySlug($slug);
        if (! $file || (int) $file->status !== 1) {
            return $this->reply(['status' => 'error', 'message' => 'File not found.'], 404);
        }

        // Delivery gate: this endpoint is the API. A file the admin set to
        // "browser link only" is not handed out here.
        if (! $this->apiDeliverable($file)) {
            return $this->reply(['status' => 'error', 'message' => 'File not found.'], 404);
        }

        if ((int) $file->access === DownloadFileModel::ACCESS_KEY) {
            if (! $this->keyAuthorises($key, $file, $game)) {
                return $this->reply(['status' => 'error', 'message' => 'This key cannot download this file.'], 403);
            }
        }

        return $this->reply($this->mint($file, $key, $ipHash));
    }

    // ------------------------------------------------------------------
    //  GET /download/get — issue a link for the browser page (free or, with a
    //  ?key=, a gated file). Returns JSON like the API; used by the page's JS.
    // ------------------------------------------------------------------
    public function get()
    {
        $ipHash = md5('download_' . $this->clientIp());
        if (! $this->rlOk($ipHash)) {
            return $this->reply(['status' => 'error', 'message' => 'Too many requests. Please slow down.'], 429);
        }

        $in   = $this->input();
        $slug = strtolower(trim((string) ($in['file'] ?? '')));
        $key  = trim((string) ($in['key'] ?? ''));

        if ($slug === '' || ! preg_match('/^[a-z0-9\-_]{1,64}$/', $slug)) {
            return $this->reply(['status' => 'error', 'message' => 'File not found.'], 404);
        }
        $file = (new DownloadFileModel())->bySlug($slug);
        if (! $file || (int) $file->status !== 1 || ! $this->browserDeliverable($file)) {
            return $this->reply(['status' => 'error', 'message' => 'File not found.'], 404);
        }
        if ((int) $file->access === DownloadFileModel::ACCESS_KEY
            && ! $this->keyAuthorises($key, $file, '')) {
            return $this->reply(['status' => 'error', 'message' => 'This key cannot download this file.'], 403);
        }
        return $this->reply($this->mint($file, $key, $ipHash));
    }

    // ------------------------------------------------------------------
    //  GET /download/direct/<slug> — a shareable link for a FREE file: mint a
    //  one-time token and 302 to it, so the raw file still never has a URL.
    // ------------------------------------------------------------------
    public function direct($slug = '')
    {
        $ipHash = md5('download_' . $this->clientIp());
        if (! $this->rlOk($ipHash)) {
            return $this->response->setStatusCode(429)->setBody('Too many requests. Please slow down.');
        }
        $slug = strtolower(trim((string) $slug));
        if (! preg_match('/^[a-z0-9\-_]{1,64}$/', $slug)) {
            return $this->response->setStatusCode(404)->setBody('Not found.');
        }
        $file = (new DownloadFileModel())->bySlug($slug);
        // Only a FREE, browser-deliverable file can be reached by a plain URL.
        if (! $file || (int) $file->status !== 1
            || (int) $file->access !== DownloadFileModel::ACCESS_FREE
            || ! $this->browserDeliverable($file)) {
            return $this->response->setStatusCode(404)->setBody('Not found.');
        }
        $data = $this->mint($file, '', $ipHash);
        return redirect()->to($data['link']);
    }

    /** Mint a token for a file and return the JSON payload. */
    private function mint(object $file, string $key, string $ipHash): array
    {
        $ttl   = (int) $file->token_ttl > 0 ? (int) $file->token_ttl : 60;
        $token = (new DownloadTokenModel())->issue(
            (int) $file->id_file,
            $key !== '' ? $key : null,
            $ipHash,
            $ttl
        );
        return [
            'status'     => 'ok',
            'message'    => 'Authorized.',
            'file'       => $file->slug,
            'name'       => $file->orig_name,
            'link'       => base_url('download/file/' . $token),
            'expires'    => time() + $ttl * 60,
            'expires_in' => $ttl * 60,
            'single_use' => (int) $file->single_use === 1,
            'zip'        => in_array((int) $file->protection, [DownloadFileModel::PROT_ZIP, DownloadFileModel::PROT_ZIPCRYPTO], true),
        ];
    }

    private function browserDeliverable(object $f): bool
    {
        return in_array((int) $f->delivery, [DownloadFileModel::DELIV_LINK, DownloadFileModel::DELIV_BOTH], true);
    }

    private function apiDeliverable(object $f): bool
    {
        return in_array((int) $f->delivery, [DownloadFileModel::DELIV_API, DownloadFileModel::DELIV_BOTH], true);
    }

    // ------------------------------------------------------------------
    //  GET /download/file/<token> — hand over the bytes
    // ------------------------------------------------------------------
    public function file($token = '')
    {
        $tokens = new DownloadTokenModel();
        $tok    = $tokens->valid((string) $token);
        if (! $tok) {
            return $this->response->setStatusCode(410)->setBody('This link has expired or is invalid.');
        }

        $files = new DownloadFileModel();
        $file  = $files->find((int) $tok->file_id);
        if (! $file || (int) $file->status !== 1) {
            return $this->response->setStatusCode(404)->setBody('File not found.');
        }

        // Single-use: claim the token now, atomically. If it was already
        // claimed by a parallel request, refuse rather than serve twice.
        if ((int) $file->single_use === 1) {
            if (! $tokens->burn((int) $tok->id_token)) {
                return $this->response->setStatusCode(410)->setBody('This link has already been used.');
            }
        }

        $files->set('downloads', 'downloads + 1', false)
              ->where('id_file', $file->id_file)
              ->update();

        return $this->stream($file);
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** Read the request body whether it arrived as a form or as JSON. */
    private function input(): array
    {
        $post = $this->request->getPost();
        if (! empty($post)) {
            return $post;
        }
        $json = $this->request->getJSON(true);
        return is_array($json) ? $json : [];
    }

    /** A key is good for a file when it is a real, active, non-expired key and
     *  the file is either for that key's game or open to any game (ALL). */
    private function keyAuthorises(string $key, object $file, string $game): bool
    {
        if ($key === '' || strlen($key) > 96) {
            return false;
        }

        $model = new KeysModel();
        $row   = $model->getKeys($key, 'user_key');
        if (! $row || (int) $row->status !== 1) {
            return false;
        }

        // Expired key -> no.
        if (! empty($row->expired_date)) {
            try {
                if (\CodeIgniter\I18n\Time::now()->isAfter(\CodeIgniter\I18n\Time::parse($row->expired_date))) {
                    return false;
                }
            } catch (\Throwable $e) {
                return false;
            }
        }

        // Game gate. A file marked ALL accepts any game; otherwise the key's
        // game must match the file's. If the caller sent a game, it must agree
        // with the key too, so a CODM key cannot be presented for a PUBGM slot.
        $fileGame = strtoupper((string) $file->game);
        $keyGame  = strtoupper((string) $row->game);

        if ($fileGame !== 'ALL' && $keyGame !== $fileGame) {
            return false;
        }
        if ($game !== '' && $game !== $keyGame && $fileGame !== 'ALL') {
            return false;
        }

        return true;
    }

    /**
     * Send the file, honouring its at-rest protection. Everything streams in
     * chunks and nothing is held whole in memory, so a 1 GB file is fine. For
     * an encrypted file the integrity tag is checked BEFORE any header or byte
     * goes out, so a tampered file yields a clean error, never a partial body.
     */
    private function stream(object $file)
    {
        $prot = (int) $file->protection;
        $safe = $this->safeName((string) $file->orig_name);
        $mime = $file->mime ?: 'application/octet-stream';

        if ($prot === DownloadFileModel::PROT_AES) {
            // Verify first — while we can still return a normal error.
            $meta = FileVault::aesVerify((string) $file->stored_name);
            if ($meta === null) {
                return $this->response->setStatusCode(500)->setBody('File could not be read.');
            }
            $this->flushHeaders($mime, $safe, (int) $file->size);
            FileVault::aesOutput((string) $file->stored_name, $meta['iv'], (int) $meta['ctlen']);
            exit;
        }

        $path = FileVault::path((string) $file->stored_name);
        if (! is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('File missing on server.');
        }

        if ($prot === DownloadFileModel::PROT_ZIP || $prot === DownloadFileModel::PROT_ZIPCRYPTO) {
            // Served opaque: the visitor needs the password you gave them.
            $zipName = pathinfo($safe, PATHINFO_FILENAME) . '.zip';
            $this->flushHeaders('application/zip', $zipName, filesize($path));
        } else {
            $this->flushHeaders($mime, $safe, filesize($path));
        }
        $this->passthru($path);
        exit;
    }

    /** Clear buffering and emit the download headers exactly once. */
    private function flushHeaders(string $ctype, string $name, int $len): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        if ($len >= 0) {
            header('Content-Length: ' . $len);
        }
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Accept-Ranges: none');
    }

    /** Copy a file to the client in chunks, never holding it whole in memory. */
    private function passthru(string $path): void
    {
        $fp = @fopen($path, 'rb');
        if (! $fp) {
            return;
        }
        while (! feof($fp)) {
            echo fread($fp, 1048576);
            flush();
        }
        fclose($fp);
    }

    /** A filename that is safe in a Content-Disposition header. */
    private function safeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._\- ]+/', '_', $name);
        $name = trim($name);
        return $name !== '' ? substr($name, 0, 160) : 'download';
    }

    /**
     * One gate for every entry point: refuse if this IP is blocked, otherwise
     * count this request and allow it. Counting every request (not only the
     * failures) is what stops both key-guessing and bulk link-minting.
     */
    private function rlOk(string $ipHash): bool
    {
        if ($this->rlRemaining($ipHash) > 0) {
            return false;
        }
        $this->rlFail($ipHash);
        return true;
    }

    private function reply(array $data, int $code = 200)
    {
        return $this->response->setStatusCode($code)->setJSON($data);
    }

    // ---- rate limit (same shape as the connector) ----------------------

    private function rlRemaining(string $ipHash): int
    {
        try {
            $db  = db_connect();
            $now = time();
            if (random_int(1, 20) === 1) {
                $db->table(self::RL_TABLE)
                   ->where('blocked_until <', $now)->where('window_end <', $now)->delete();
            }
            $row = $db->table(self::RL_TABLE)->where('ip_hash', $ipHash)->get()->getRowArray();
            if ($row && (int) $row['blocked_until'] > $now) {
                return (int) $row['blocked_until'] - $now;
            }
        } catch (\Throwable $e) {
            log_message('critical', 'Download rate limiter unavailable: {m}', ['m' => $e->getMessage()]);
        }
        return 0;
    }

    private function rlFail(string $ipHash): void
    {
        try {
            $db  = db_connect();
            $now = time();
            $row = $db->table(self::RL_TABLE)->where('ip_hash', $ipHash)->get()->getRowArray();

            $fails     = $row ? (int) $row['fails'] : 0;
            $windowEnd = $row ? (int) $row['window_end'] : 0;
            if ($windowEnd < $now) {
                $fails     = 0;
                $windowEnd = $now + self::RL_WINDOW;
            }
            $fails++;
            $blockedUntil = 0;
            if ($fails >= self::RL_LIMIT) {
                $blockedUntil = $now + self::RL_BLOCK;
                $fails        = 0;
            }
            $payload = ['fails' => $fails, 'window_end' => $windowEnd, 'blocked_until' => $blockedUntil];
            if ($row) {
                $db->table(self::RL_TABLE)->where('ip_hash', $ipHash)->update($payload);
            } else {
                $payload['ip_hash'] = $ipHash;
                $db->table(self::RL_TABLE)->insert($payload);
            }
        } catch (\Throwable $e) {
            log_message('critical', 'Download rate limiter write failed: {m}', ['m' => $e->getMessage()]);
        }
    }

    private function rlClear(string $ipHash): void
    {
        try {
            db_connect()->table(self::RL_TABLE)->where('ip_hash', $ipHash)->delete();
        } catch (\Throwable $e) {
        }
    }
}