<?php

namespace App\Controllers;

use App\Models\KeysModel;
use App\Models\HistoryModel;
use App\Models\UserModel;
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

        return view('Keys/renew', [
            'title'   => 'Renew keys',
            'user'    => $this->user,
            'presets' => $presets,
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

        $reactivate = filter_var($this->request->getPost('reactivate'), FILTER_VALIDATE_BOOLEAN);

        // getKeys(), not find(): the model has no $returnType, so find() would
        // hand back an array and every ->property read below would be fatal.
        $key = $this->model->getKeys($id, 'id_keys');
        if (! $key) {
            return $this->response->setJSON(['ok' => false, 'error' => 'That key no longer exists.', 'csrf' => csrf_hash()]);
        }

        $now  = Time::now();
        $set  = [];
        $mode = '';

        if (empty($key->expired_date)) {
            // UNUSED — the clock has not started. Add to the stored duration so
            // the key simply lasts longer once it is first activated.
            $newDuration = max(0, (int) $key->duration) + $hours;
            $set['duration'] = $newDuration;
            $mode = 'duration';
        } else {
            try {
                $exp = Time::parse($key->expired_date);
            } catch (\Throwable $e) {
                return $this->response->setJSON(['ok' => false, 'error' => 'This key has an unreadable expiry date.', 'csrf' => csrf_hash()]);
            }

            if ($exp->isAfter($now)) {
                // ACTIVE — extend from the current expiry, keeping the remainder.
                $base = $exp;
                $mode = 'extend';
            } else {
                // EXPIRED / consumed — restart from now and bring it back to life.
                $base = $now;
                $mode = 'restart';
            }
            $set['expired_date'] = $base->addHours($hours)->toDateTimeString();
        }

        // Re-activate when the admin asked, or always when we restarted an
        // expired key (a consumed key that is renewed should come back on).
        if (($reactivate || $mode === 'restart') && (int) $key->status !== 1) {
            $set['status'] = 1;
        }

        $db = \Config\Database::connect();
        $db->transBegin();
        try {
            $this->model->update($id, $set);

            (new HistoryModel())->insert([
                'keys_id' => (int) $id,
                'user_do' => $this->user->username,
                'info'    => 'renew|' . $mode . '|+' . $hours . 'h'
                    . (isset($set['status']) ? '|reactivated' : ''),
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
        $blocked = (int) $k->status !== 1;

        return [
            'id'           => (int) $k->id_keys,
            'user_key'     => (string) $k->user_key,
            'game'         => (string) $k->game,
            'status'       => (int) $k->status,
            'blocked'      => $blocked,
            'duration'     => (int) $k->duration,
            'max_devices'  => (int) $k->max_devices,
            'devices_used' => $k->devices ? count(array_filter(explode(',', $k->devices))) : 0,
            'expired_date' => $k->expired_date ?: null,
            'state'        => $state,           // unused | active | expired
            'remaining'    => $remain,          // human string, only when active
            'registrator'  => (string) ($k->registrator ?? ''),
        ];
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
