<?php

namespace App\Controllers;

use App\Models\KeysModel;
use App\Models\HistoryModel;
use App\Models\UserModel;
use App\Models\GameModel;
use CodeIgniter\I18n\Time;

/**
 * ============================================================================
 *  KeyRenew  (app/Controllers/KeyRenew.php)  —  extend / re-activate keys
 * ============================================================================
 *  A dedicated admin section to renew a licence key's time. It understands the
 *  three states a key can be in and does the right thing for each:
 *
 *    - UNUSED   (expired_date is NULL): the clock has not started — the key
 *      gets its hours on first activation. Renewing ADDS to that stored
 *      duration, so it will simply last longer once someone logs in.
 *
 *    - ACTIVE   (expired_date in the future): still valid. Renewing EXTENDS
 *      from the current expiry, so the remaining time is never thrown away.
 *
 *    - EXPIRED  (expired_date in the past): consumed. Renewing RESTARTS the
 *      clock from now (now + hours) and re-activates the key (status = 1).
 *
 *  Admin only, and re-checked in every method — a filter slip cannot open it.
 *  Every renewal is written to `history`, so who extended what is on record.
 *
 *  Routes (add under the admin group — see routes-add.php):
 *    GET  admin/keys/renew            the section (lookup + renew form)
 *    POST admin/keys/renew/lookup     JSON: find a key, describe its state
 *    POST admin/keys/renew/apply      JSON: apply the renewal
 * ============================================================================
 */
class KeyRenew extends BaseController
{
    protected $user;
    protected $model;

    /** Hard ceiling on a single renewal, so a typo cannot set the year 9999. */
    private const MAX_HOURS = 87600;   // 10 years

    public function __construct()
    {
        $this->user  = (new UserModel())->getUser();
        $this->model = new KeysModel();
    }

    /** Admins only. Returns a response to send back, or null to proceed. */
    private function denyNonAdmin()
    {
        if (! $this->user || (int) ($this->user->level ?? 2) !== 1) {
            return redirect()->to('/')->with('msgDanger', 'Admins only.');
        }
        return null;
    }

    private function denyNonAdminJson()
    {
        if (! $this->user || (int) ($this->user->level ?? 2) !== 1) {
            return $this->response->setStatusCode(403)
                ->setJSON(['ok' => false, 'error' => 'Access denied.']);
        }
        return null;
    }

    // ------------------------------------------------------------------
    //  GET admin/keys/renew — the page
    // ------------------------------------------------------------------
    public function index()
    {
        if ($deny = $this->denyNonAdmin()) {
            return $deny;
        }

        // Preset amounts the buttons offer (hours). The custom field covers
        // anything else. These mirror the common duration tiers.
        $presets = [
            ['h' => 24,   'label' => '1 day'],
            ['h' => 168,  'label' => '7 days'],
            ['h' => 720,  'label' => '30 days'],
            ['h' => 2160, 'label' => '90 days'],
            ['h' => 4320, 'label' => '180 days'],
            ['h' => 8760, 'label' => '1 year'],
        ];

        // Game codes for the bulk scope dropdown (all games ever on record, so
        // keys for a since-removed game can still be renewed in bulk).
        $games = [];
        try {
            foreach ((new GameModel())->allOrdered() as $g) {
                $games[] = ['code' => (string) $g->code, 'name' => (string) $g->name];
            }
        } catch (\Throwable $e) {
            $games = [];
        }

        return view('Keys/renew', [
            'title'   => 'Renew keys',
            'user'    => $this->user,
            'presets' => $presets,
            'games'   => $games,
        ]);
    }

    // ------------------------------------------------------------------
    //  POST admin/keys/renew/lookup — {q} -> the key and its live state
    // ------------------------------------------------------------------
    public function lookup()
    {
        if ($deny = $this->denyNonAdminJson()) {
            return $deny;
        }

        $q = trim((string) $this->request->getPost('q'));
        if ($q === '' || mb_strlen($q) > 96) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Enter a key or its ID.']);
        }

        $key = $this->find($q);
        if (! $key) {
            return $this->response->setJSON(['ok' => false, 'error' => 'No key found for that value.']);
        }

        return $this->response->setJSON([
            'ok'   => true,
            'key'  => $this->describe($key),
            'csrf' => csrf_hash(),
        ]);
    }

    // ------------------------------------------------------------------
    //  POST admin/keys/renew/apply — {id_keys, amount, unit, reactivate}
    // ------------------------------------------------------------------
    public function apply()
    {
        if ($deny = $this->denyNonAdminJson()) {
            return $deny;
        }

        $postedId = $this->request->getPost('id_keys');
        $id       = is_scalar($postedId) ? (int) $postedId : 0;
        if ($id < 1) {
            return $this->response->setJSON(['ok' => false, 'error' => 'No key given.', 'csrf' => csrf_hash()]);
        }

        $amount = (int) $this->request->getPost('amount');
        $unit   = strtolower((string) $this->request->getPost('unit'));
        $hours  = $unit === 'days' ? $amount * 24 : $amount;

        if ($hours < 1) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Amount must be at least 1.', 'csrf' => csrf_hash()]);
        }
        if ($hours > self::MAX_HOURS) {
            return $this->response->setJSON([
                'ok'    => false,
                'error' => 'That is too long. The most you can add at once is 10 years.',
                'csrf'  => csrf_hash(),
            ]);
        }

        // getKeys(), not find(): the model has no $returnType, so find() would
        // hand back an array and every ->property read below would be fatal.
        $key = $this->model->getKeys($id, 'id_keys');
        if (! $key) {
            return $this->response->setJSON(['ok' => false, 'error' => 'That key no longer exists.', 'csrf' => csrf_hash()]);
        }

        // THE RULE: renew only an active, still-valid, device-bound key. An
        // inactive, unused, or expired key is refused and nothing is changed.
        $block = $this->renewBlock($key);
        if ($block !== '') {
            return $this->response->setJSON(['ok' => false, 'error' => $block, 'csrf' => csrf_hash()]);
        }

        // Extend from the key's OWN current expiry, so the remainder is kept and
        // exactly $hours are added.
        $set  = ['expired_date' => Time::parse($key->expired_date)->addHours($hours)->toDateTimeString()];
        $mode = 'extend';

        $db = \Config\Database::connect();
        $db->transBegin();
        try {
            $this->model->update($id, $set);

            (new HistoryModel())->insert([
                'keys_id' => (int) $id,
                'user_do' => $this->user->username,
                'info'    => 'renew|' . $mode . '|+' . $hours . 'h',
            ]);

            if ($db->transStatus() === false) {
                throw new \RuntimeException('renew transaction failed');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Key renew failed: {m}', ['m' => $e->getMessage()]);
            return $this->response->setJSON(['ok' => false, 'error' => 'Could not renew. Nothing was changed.', 'csrf' => csrf_hash()]);
        }

        $fresh = $this->model->getKeys($id, 'id_keys');

        return $this->response->setJSON([
            'ok'      => true,
            'mode'    => $mode,
            'added'   => $hours,
            'message' => $this->renewMessage($mode, $hours, isset($set['status'])),
            'key'     => $this->describe($fresh),
            'csrf'    => csrf_hash(),
        ]);
    }

    // ------------------------------------------------------------------
    //  BULK — add time to every ACTIVE, IN-USE key at once, optionally
    //  narrowed to one game and/or one seller. A key is touched ONLY when it is
    //  status = 1, still valid (expired_date set and in the future), AND bound
    //  to a device (devices not empty) — i.e. active on someone's device.
    //  Inactive, unused, and expired keys are never touched.
    // ------------------------------------------------------------------

    /** POST admin/keys/renew/bulk-preview — how many keys a scope would touch. */
    public function bulkPreview()
    {
        if ($deny = $this->denyNonAdminJson()) {
            return $deny;
        }

        [$game, $ownerId, $err] = $this->bulkScope();
        if ($err !== null) {
            return $this->response->setJSON(['ok' => false, 'error' => $err, 'csrf' => csrf_hash()]);
        }

        $count = $this->bulkBuilder($game, $ownerId)->countAllResults();

        return $this->response->setJSON([
            'ok'    => true,
            'count' => $count,
            'scope' => $this->scopeLabel($game, $ownerId),
            'csrf'  => csrf_hash(),
        ]);
    }

    /** POST admin/keys/renew/bulk-apply — add the time to every matching key. */
    public function bulkApply()
    {
        if ($deny = $this->denyNonAdminJson()) {
            return $deny;
        }

        // A mass write needs an explicit confirm, so it cannot fire on a stray
        // click or a replayed preview request.
        if (! filter_var($this->request->getPost('confirm'), FILTER_VALIDATE_BOOLEAN)) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Not confirmed.', 'csrf' => csrf_hash()]);
        }

        [$game, $ownerId, $err] = $this->bulkScope();
        if ($err !== null) {
            return $this->response->setJSON(['ok' => false, 'error' => $err, 'csrf' => csrf_hash()]);
        }

        $amount = (int) $this->request->getPost('amount');
        $unit   = strtolower((string) $this->request->getPost('unit'));
        $hours  = $unit === 'days' ? $amount * 24 : $amount;

        if ($hours < 1) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Amount must be at least 1.', 'csrf' => csrf_hash()]);
        }
        if ($hours > self::MAX_HOURS) {
            return $this->response->setJSON([
                'ok'    => false,
                'error' => 'That is too long. The most you can add at once is 10 years.',
                'csrf'  => csrf_hash(),
            ]);
        }

        // One atomic UPDATE for the whole set — fast for thousands of rows, and
        // it extends each key from ITS OWN expiry (DATE_ADD on the column), so
        // every key keeps the exact remainder it had. $hours is an int, so the
        // inline interval carries no untrusted input.
        $affected = 0;
        try {
            $builder = $this->bulkBuilder($game, $ownerId);
            $builder->set('expired_date', 'DATE_ADD(expired_date, INTERVAL ' . (int) $hours . ' HOUR)', false)
                    ->update();
            $affected = db_connect()->affectedRows();
        } catch (\Throwable $e) {
            log_message('error', 'Bulk renew failed: {m}', ['m' => $e->getMessage()]);
            return $this->response->setJSON(['ok' => false, 'error' => 'Could not renew. Nothing was changed.', 'csrf' => csrf_hash()]);
        }

        // One audit line for the whole batch (per-key history rows would be
        // thousands of writes for one action).
        log_message('info', 'Bulk renew by {who}: +{h}h to {n} key(s), scope [{s}].', [
            'who' => $this->user->username,
            'h'   => $hours,
            'n'   => $affected,
            's'   => $this->scopeLabel($game, $ownerId),
        ]);

        $amountTxt = $hours % 24 === 0
            ? ($hours / 24) . ' day' . ($hours === 24 ? '' : 's')
            : $hours . ' hour' . ($hours === 1 ? '' : 's');

        return $this->response->setJSON([
            'ok'       => true,
            'affected' => $affected,
            'message'  => $affected > 0
                ? "Added {$amountTxt} to {$affected} valid key" . ($affected === 1 ? '' : 's') . '.'
                : 'No valid keys matched — nothing was changed.',
            'csrf'     => csrf_hash(),
        ]);
    }

    /**
     * Resolve the bulk scope from the request.
     * @return array{0:string,1:?int,2:?string}  [game, ownerId, error]
     */
    private function bulkScope(): array
    {
        $game = strtoupper(trim((string) $this->request->getPost('game')));
        if ($game === '') {
            $game = 'ALL';
        }
        if ($game !== 'ALL' && ! preg_match('/^[A-Z0-9_]{1,32}$/', $game)) {
            return ['ALL', null, 'That game code is not valid.'];
        }

        $ownerId = null;
        $owner   = trim((string) $this->request->getPost('owner'));
        if ($owner !== '') {
            $found = (new UserModel())->getUser($owner, 'username');
            if (! $found) {
                return [$game, null, 'No user with that username.'];
            }
            $ownerId = (int) $found->id_users;
        }

        return [$game, $ownerId, null];
    }

    /** The query builder for the renewable set (active + valid + device bound). */
    private function bulkBuilder(string $game, ?int $ownerId)
    {
        $b = db_connect()->table('keys_code')
            ->where('status', 1)                                   // active only
            ->where('expired_date IS NOT NULL', null, false)      // has started
            ->where('expired_date >', date('Y-m-d H:i:s'))        // still valid
            ->where('devices IS NOT NULL', null, false)           // bound to
            ->where("devices <> ''", null, false);                // ...a device

        if ($game !== 'ALL') {
            $b->where('game', $game);
        }
        if ($ownerId !== null) {
            $b->where('registrator_id', $ownerId);
        }
        return $b;
    }

    private function scopeLabel(string $game, ?int $ownerId): string
    {
        $parts = [$game === 'ALL' ? 'all games' : $game];
        if ($ownerId !== null) {
            $parts[] = 'owner #' . $ownerId;
        }
        return implode(', ', $parts);
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** Find a key by its user_key, or by numeric id if the value is all digits. */
    private function find(string $q)
    {
        if (ctype_digit($q)) {
            $byId = $this->model->getKeys((int) $q, 'id_keys');
            if ($byId) {
                return $byId;
            }
        }
        return $this->model->getKeys($q, 'user_key');
    }

    /** Describe a key's live state for the UI. */
    private function describe(object $k): array
    {
        $now      = Time::now();
        $state    = 'unused';
        $remain   = null;

        if (! empty($k->expired_date)) {
            try {
                $exp = Time::parse($k->expired_date);
                if ($exp->isAfter($now)) {
                    $state  = 'active';
                    $remain = $this->human($now, $exp);
                } else {
                    $state = 'expired';
                }
            } catch (\Throwable $e) {
                $state = 'expired';
            }
        }

        // A blocked key overrides the time-based label for display.
        $blocked  = (int) $k->status !== 1;
        $devCount = $k->devices ? count(array_filter(explode(',', (string) $k->devices))) : 0;

        // A key may be renewed ONLY when it is active, still valid, and bound to
        // a device (i.e. actually in use). Inactive, unused, and expired keys are
        // refused — the reason says which.
        $reason = '';
        if ($blocked)            { $reason = 'This key is inactive (blocked).'; }
        elseif ($state === 'unused')  { $reason = 'This key is unused — it has no device bound yet.'; }
        elseif ($state === 'expired') { $reason = 'This key has expired.'; }
        elseif ($devCount < 1)        { $reason = 'This key has no device bound yet.'; }
        $renewable = ($reason === '');

        return [
            'id'           => (int) $k->id_keys,
            'user_key'     => (string) $k->user_key,
            'game'         => (string) $k->game,
            'status'       => (int) $k->status,
            'blocked'      => $blocked,
            'duration'     => (int) $k->duration,
            'max_devices'  => (int) $k->max_devices,
            'devices_used' => $devCount,
            'expired_date' => $k->expired_date ?: null,
            'state'        => $state,           // unused | active | expired
            'remaining'    => $remain,          // human string, only when active
            'registrator'  => (string) ($k->registrator ?? ''),
            'renewable'    => $renewable,       // active + valid + device bound
            'reason'       => $reason,          // why not, when not renewable
        ];
    }

    /**
     * The one rule: renew only an ACTIVE, still-VALID, device-BOUND key.
     * Returns '' when renewable, otherwise the reason it is refused.
     */
    private function renewBlock(object $k): string
    {
        if ((int) $k->status !== 1) {
            return 'This key is inactive (blocked). Renew only applies to active keys.';
        }
        if (empty($k->expired_date)) {
            return 'This key is unused (no device bound yet). Renew only applies to active, in-use keys.';
        }
        try {
            if (! Time::parse($k->expired_date)->isAfter(Time::now())) {
                return 'This key has expired. Renew only applies to keys that are still valid.';
            }
        } catch (\Throwable $e) {
            return 'This key has an unreadable expiry date.';
        }
        $devCount = $k->devices ? count(array_filter(explode(',', (string) $k->devices))) : 0;
        if ($devCount < 1) {
            return 'This key has no device bound yet. Renew only applies to keys active on a device.';
        }
        return '';
    }

    /** A compact "3 days, 4 hours" between two Times. */
    private function human(Time $from, Time $to): string
    {
        $secs = max(0, $to->getTimestamp() - $from->getTimestamp());
        $d = intdiv($secs, 86400);
        $h = intdiv($secs % 86400, 3600);
        $m = intdiv($secs % 3600, 60);

        $parts = [];
        if ($d > 0) { $parts[] = $d . ' day'  . ($d === 1 ? '' : 's'); }
        if ($h > 0) { $parts[] = $h . ' hour' . ($h === 1 ? '' : 's'); }
        if ($d === 0 && $m > 0) { $parts[] = $m . ' min'; }
        return $parts ? implode(', ', $parts) : 'under a minute';
    }

    private function renewMessage(string $mode, int $hours, bool $reactivated): string
    {
        $amount = $hours % 24 === 0
            ? ($hours / 24) . ' day' . ($hours === 24 ? '' : 's')
            : $hours . ' hour' . ($hours === 1 ? '' : 's');

        switch ($mode) {
            case 'duration':
                return "Added {$amount} to this key's duration. It starts when the key is first activated.";
            case 'extend':
                return "Extended by {$amount} from the current expiry.";
            case 'restart':
                return "Restarted for {$amount} from now" . ($reactivated ? ' and re-activated.' : '.');
            default:
                return "Renewed by {$amount}.";
        }
    }
}
