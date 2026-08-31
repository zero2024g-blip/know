<?php

namespace App\Controllers;

use App\Models\CodeModel;
use App\Models\UserModel;
use CodeIgniter\Config\Services;

class Auth extends BaseController
{
    protected $user;
    protected $userModel;

    // ---- Rate limiting (DB-backed, works on shared hosting) ----
    private const RL_TABLE = 'auth_ratelimit';
    // Login: N failed logins within WINDOW seconds -> hard block for BLOCK seconds.
    private const LOGIN_LIMIT  = 6;
    private const LOGIN_WINDOW = 600;   // 10 min
    private const LOGIN_BLOCK  = 900;   // 15 min
    // Register: stricter and over a longer window.
    private const REG_LIMIT  = 5;
    private const REG_WINDOW = 3600;    // 1 hour
    private const REG_BLOCK  = 3600;    // 1 hour

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function index()
    {
        return redirect()->to(session()->has('userid') ? 'dashboard' : 'login');
    }

    public function login()
    {
        if (session()->has('userid')) {
            return redirect()->to('dashboard');
        }

        if ($this->request->getPost()) {
            return $this->login_action();
        }

        return view('Auth/login', [
            'title'      => 'Login',
            'validation' => Services::validation(),
        ]);
    }

    public function register()
    {
        if (session()->has('userid')) {
            return redirect()->to('dashboard');
        }

        if ($this->request->getPost()) {
            return $this->register_action();
        }

        return view('Auth/register', [
            'title'      => 'Register',
            'validation' => Services::validation(),
        ]);
    }

    private function login_action()
    {
        $ipHash = md5('login_' . $this->clientIp());

        // 1) Already hard-blocked? show remaining time, no auth attempt.
        $remain = $this->rateRemaining($ipHash);
        if ($remain > 0) {
            $this->noteAttempt(\App\Models\SecurityLogModel::BLOCKED, 'login',
                $this->request->getPost('username'), 'hit while blocked (' . $remain . 's left)');
            return redirect()->route('login')->with('msgDanger', $this->waitMessage($remain));
        }

        $username = $this->request->getPost('username');
        $password = (string) $this->request->getPost('password');
        $stay_log = $this->request->getPost('stay_log');

        $form_rules = [
            'username' => ['label' => 'username', 'rules' => 'required|alpha_numeric|min_length[4]|max_length[25]'],
            'password' => ['label' => 'password', 'rules' => 'required|min_length[6]|max_length[70]'],
            'stay_log' => ['rules' => 'permit_empty|max_length[3]'],
        ];

        if (! $this->validate($form_rules)) {
            return $this->loginFail($ipHash);
        }

        $cekUser      = $this->userModel->getUser($username, 'username');
        $loginSuccess = false;
        $needsRehash  = false;

        if ($cekUser) {
            // Argon2id only. The legacy md5-then-bcrypt bridge for pre-upgrade
            // accounts has been removed; every stored password is Argon2id, and
            // the rehash below keeps parameters current if they ever change.
            if (password_verify($password, $cekUser->password)) {
                $loginSuccess = true;
                if (password_needs_rehash($cekUser->password, PASSWORD_ARGON2ID, [
                    'memory_cost' => 65536,
                    'time_cost'   => 4,
                    'threads'     => 2,
                ])) {
                    $needsRehash = true;
                }
            }
        } else {
            // Timing-safe: run the KDF even when the username doesn't exist.
            password_hash($password, PASSWORD_ARGON2ID);
        }

        // Correct password but disabled account -> not a brute-force, clear the counter.
        if ($loginSuccess && isset($cekUser->status) && (int) $cekUser->status !== 1) {
            $this->rateClear($ipHash);
            return redirect()->route('login')->withInput()->with('msgDanger', 'This account is disabled. Please contact your admin.');
        }

        if ($loginSuccess) {
            $this->rateClear($ipHash); // legit -> wipe fail history

            if ($needsRehash) {
                $newHashV2 = create_passwordV2($password);
                $this->userModel->update($cekUser->id_users, ['password' => $newHashV2]);
            }

            // Second factor. The password is correct, but if this account has
            // an authenticator enrolled the session is NOT created yet: we hand
            // out only a short-lived "half-authenticated" ticket and send the
            // browser to the code step. A stolen password alone gets no further.
            if (\App\Models\TwoFactorModel::isEnabled($cekUser)) {
                session()->regenerate(true); // fresh id for the pending ticket
                session()->set('2fa_pending', [
                    'uid'     => $cekUser->id_users,
                    'uname'   => $cekUser->username,
                    'stay'    => $stay_log ? 1 : 0,
                    'expires' => time() + 300,   // 5 minutes to enter a code
                    'iphash'  => $ipHash,
                ]);
                return redirect()->to('login/2fa');
            }

            return $this->completeLogin($cekUser, (bool) $stay_log, $ipHash);
        }

        // Wrong credentials -> count the failed attempt.
        return $this->loginFail($ipHash);
    }

    /**
     * Finish a sign-in once every factor has passed: create the session, bind
     * it to this browser in the history table, and land on the dashboard.
     * Shared by the password-only path and the second-factor path so both
     * establish exactly the same session.
     */
    private function completeLogin($user, bool $stay, string $ipHash)
    {
        session()->regenerate(true); // prevent session fixation

        $time = new \CodeIgniter\I18n\Time();
        session()->set([
            'userid'     => $user->id_users,
            'unames'     => $user->username,
            'time_login' => $stay ? $time::now()->addHours(24) : $time::now()->addMinutes(30),
            'time_since' => $time::now(),
        ]);

        // Record the sign-in AFTER regenerate(), so the id stored here is the
        // one the browser will send back. This also binds the session to this
        // browser: see LoginSessionModel.
        try {
            (new \App\Models\LoginSessionModel())
                ->begin($user, \App\Models\LoginSessionModel::currentId(), $ipHash, $this->clientIp());
        } catch (\Throwable $e) {
            log_message('critical',
                'Could not record sign-in for {u}: {msg}. Session-theft protection is NOT active for this session.',
                ['u' => $user->username, 'msg' => $e->getMessage()]);
        }

        return redirect()->to('dashboard')
            ->with('msgSuccess', 'Welcome back, ' . getName($user) . '.');
    }

    /**
     * The second-factor step. Reached only with a valid, unexpired pending
     * ticket set by login_action(). GET shows the code form; POST verifies a
     * TOTP code or a single-use recovery code and, on success, completes the
     * sign-in. Failures are rate limited on the same counter as passwords.
     */
    public function twofa()
    {
        // Already fully signed in? Nothing to do here.
        if (session()->has('userid')) {
            return redirect()->to('dashboard');
        }

        $pending = session()->get('2fa_pending');
        if (! is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            session()->remove('2fa_pending');
            return redirect()->to('login')->with('msgDanger', 'Your sign-in timed out. Please start again.');
        }

        if (! $this->request->getPost()) {
            return view('Auth/twofa', ['title' => 'Two-factor']);
        }

        $ipHash = md5('2fa_' . $this->clientIp());
        $remain = $this->rateRemaining($ipHash);
        if ($remain > 0) {
            return redirect()->to('login/2fa')->with('msgDanger', $this->waitMessage($remain));
        }

        $user = $this->userModel->getUser((int) $pending['uid'], 'id_users');
        if (! $user || (int) ($user->status ?? 0) !== 1) {
            session()->remove('2fa_pending');
            return redirect()->to('login')->with('msgDanger', 'This account is not available.');
        }

        $code   = (string) $this->request->getPost('code');
        $isRec  = (bool) $this->request->getPost('recovery');
        $passed = $isRec
            ? \App\Models\TwoFactorModel::useRecoveryCode((int) $user->id_users, $code)
            : \App\Models\TwoFactorModel::verifyCode($user, $code);

        if (! $passed) {
            $blk = $this->rateFail($ipHash, self::LOGIN_LIMIT, self::LOGIN_WINDOW, self::LOGIN_BLOCK);
            $this->noteAttempt(
                $blk > 0 ? \App\Models\SecurityLogModel::BLOCKED : \App\Models\SecurityLogModel::LOGIN_FAIL,
                'login', $user->username,
                $blk > 0 ? 'rate limit: blocked ' . $blk . 's' : 'wrong second factor'
            );
            $msg = $blk > 0 ? $this->waitMessage($blk) : 'That code is not correct.';
            return redirect()->to('login/2fa')->with('msgDanger', $msg);
        }

        // Passed. Clear the ticket and the counter, then establish the session.
        $this->rateClear($ipHash);
        $stay = (bool) ($pending['stay'] ?? 0);
        session()->remove('2fa_pending');

        if ($isRec) {
            $left = \App\Models\TwoFactorModel::remainingRecoveryCodes((int) $user->id_users);
            session()->setFlashdata('msgWarning',
                'You signed in with a recovery code. ' . $left . ' left. Consider regenerating them in Settings.');
        }

        return $this->completeLogin($user, $stay, $pending['iphash'] ?? $ipHash);
    }

    private function loginFail(string $ipHash)
    {
        $blk = $this->rateFail($ipHash, self::LOGIN_LIMIT, self::LOGIN_WINDOW, self::LOGIN_BLOCK);
        $this->noteAttempt(
            $blk > 0 ? \App\Models\SecurityLogModel::BLOCKED : \App\Models\SecurityLogModel::LOGIN_FAIL,
            'login',
            $this->request->getPost('username'),
            $blk > 0 ? 'rate limit: blocked ' . $blk . 's' : 'wrong credentials'
        );
        $msg = $blk > 0 ? $this->waitMessage($blk) : '<strong>Failed</strong> The login information is incorrect.';
        return redirect()->route('login')->withInput()->with('msgDanger', $msg);
    }

    /**
     * Record one refused attempt for the admin security log. Fail-open: the
     * model swallows its own errors, so a login still fails cleanly if the
     * table is gone. The username is whatever was typed — never trusted.
     */
    private function noteAttempt(string $event, string $scope, $username, string $detail): void
    {
        (new \App\Models\SecurityLogModel())->note(
            $event, $scope,
            is_scalar($username) ? (string) $username : null,
            $this->clientIp(),
            $detail
        );
    }

    public function register_action()
    {
        $ipHash = md5('register_' . $this->clientIp());

        $remain = $this->rateRemaining($ipHash);
        if ($remain > 0) {
            return redirect()->route('register')->with('msgDanger', $this->waitMessage($remain));
        }

        $username = $this->request->getPost('username');
        $password = (string) $this->request->getPost('password');
        $referral = $this->request->getPost('referral');

        $form_rules = [
            'username'  => ['label' => 'username', 'rules' => 'required|alpha_numeric|min_length[4]|max_length[25]|is_unique[users.username]'],
            'password'  => ['label' => 'password', 'rules' => 'required|min_length[6]|max_length[70]'],
            'password2' => ['label' => 'confirm password', 'rules' => 'required|matches[password]'],
            'referral'  => ['label' => 'referral code', 'rules' => 'required|alpha_numeric'],
        ];

        if (! $this->validate($form_rules)) {
            return $this->registerFail($ipHash, 'Invalid input data.');
        }

        $mCode  = new CodeModel();
        $rCheck = $mCode->checkCode($referral);

        if (! $rCheck) {
            return $this->registerFail($ipHash, 'The referral code is invalid.');
        }

        if ($rCheck->used_by) {
            return $this->registerFail($ipHash, 'This referral code has already been used.');
        }

        $secure_password = create_passwordV2($password);
        $data_register   = [
            'username' => $username,
            'password' => $secure_password,
            'saldo'    => $rCheck->set_saldo ?: 0,
            'uplink'   => $rCheck->created_by,
            'status'   => 1,
            // No 'level' here on purpose: a registrant takes the column
            // default. Accepting it from input would let anyone sign up
            // as an administrator.
        ];

        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            // Claim the code FIRST. It was previously marked used after the
            // insert and with a missing argument, so it was never marked at
            // all and one code funded unlimited accounts. Claiming first
            // also means a losing race creates no account.
            if (! $mCode->useReferral($referral, $username)) {
                $db->transRollback();
                return $this->registerFail($ipHash, 'This referral code has already been used.');
            }

            $newUserId = $this->userModel->insert($data_register);
            if (! $newUserId) {
                throw new \RuntimeException('User insert failed');
            }

            // The opening credit the referral code carried. Recorded so the
            // account's first balance has an explanation instead of appearing
            // from nowhere, and so the code that paid for it is traceable.
            $opening = (float) ($data_register['saldo'] ?? 0);
            if ($opening > 0) {
                (new \App\Models\BalanceModel())->record(
                    (int) $newUserId,
                    $opening,
                    $opening,
                    \App\Models\BalanceModel::REFERRAL,
                    $rCheck->created_by ?: null,
                    'Opening credit from a referral code'
                );
            }

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Transaction failed');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            // The claim and the account live or die together, so a failed
            // signup never burns the code.
            $db->transRollback();
            log_message('error', 'Registration rolled back: ' . $e->getMessage());
            return redirect()->route('register')->withInput()
                ->with('msgDanger', 'An error occurred. Please try again.');
        }

        $this->rateClear($ipHash); // success -> clear
        return redirect()->to('login')->with('msgSuccess', 'Registration successful! Please login.');
    }

    private function registerFail(string $ipHash, string $reason)
    {
        $blk = $this->rateFail($ipHash, self::REG_LIMIT, self::REG_WINDOW, self::REG_BLOCK);
        $this->noteAttempt(
            $blk > 0 ? \App\Models\SecurityLogModel::BLOCKED : \App\Models\SecurityLogModel::REGISTER_FAIL,
            'register',
            $this->request->getPost('username'),
            $blk > 0 ? 'rate limit: blocked ' . $blk . 's' : mb_substr(strip_tags($reason), 0, 96)
        );
        $msg = $blk > 0 ? $this->waitMessage($blk) : $reason;
        return redirect()->back()->withInput()->with('msgDanger', $msg);
    }

    public function logout()
    {
        if (session()->has('userid')) {
            // Close the history row before the id changes, or the sign-in is
            // left looking like it never ended.
            try {
                (new \App\Models\LoginSessionModel())->end(\App\Models\LoginSessionModel::currentId(), 'logout');
            } catch (\Throwable $e) {
                log_message('error', 'Could not close sign-in record: {msg}', ['msg' => $e->getMessage()]);
            }

            session()->remove(['userid', 'unames', 'time_login', 'time_since']);
            session()->regenerate(true);
            session()->setFlashdata('msgSuccess', 'Signed out.');
        }
        return redirect()->to('login');
    }

    // ==========================================================
    //  DB-backed rate limiter (same approach as KeyCheck)
    // ==========================================================

    /** Remaining block seconds for this IP hash, or 0 if not blocked. */
    private function rateRemaining(string $ipHash): int
    {
        try {
            $db  = db_connect();
            $now = time();

            // ~5% of requests: purge fully-stale rows so the table stays tiny.
            if (random_int(1, 20) === 1) {
                try {
                    $db->table(self::RL_TABLE)->where('blocked_until <', $now)->where('window_end <', $now)->delete();
                } catch (\Throwable $e) {
                }
            }

            $row = $db->table(self::RL_TABLE)->where('ip_hash', $ipHash)->get()->getRowArray();
            if ($row && (int) $row['blocked_until'] > $now) {
                return (int) $row['blocked_until'] - $now;
            }
        } catch (\Throwable $e) {
            $this->rateLimiterBroken($e);
        }
        return 0;
    }

    /** Count a failed attempt. Returns remaining block seconds if it just got blocked, else 0. */
    private function rateFail(string $ipHash, int $limit, int $window, int $block): int
    {
        try {
            $db  = db_connect();
            $now = time();
            $row = $db->table(self::RL_TABLE)->where('ip_hash', $ipHash)->get()->getRowArray();

            $fails     = $row ? (int) $row['fails'] : 0;
            $windowEnd = $row ? (int) $row['window_end'] : 0;

            if ($windowEnd < $now) {
                $fails     = 0;
                $windowEnd = $now + $window;
            }
            $fails++;

            $blockedUntil = 0;
            $remaining    = 0;
            if ($fails >= $limit) {
                $blockedUntil = $now + $block;
                $fails        = 0;
                $remaining    = $block;
            }

            $payload = ['fails' => $fails, 'window_end' => $windowEnd, 'blocked_until' => $blockedUntil];
            if ($row) {
                $db->table(self::RL_TABLE)->where('ip_hash', $ipHash)->update($payload);
            } else {
                $payload['ip_hash'] = $ipHash;
                $db->table(self::RL_TABLE)->insert($payload);
            }
            return $remaining;
        } catch (\Throwable $e) {
            $this->rateLimiterBroken($e);
            return 0;
        }
    }

    /** Clear this IP's fail record (called on success). */
    private function rateClear(string $ipHash): void
    {
        try {
            db_connect()->table(self::RL_TABLE)->where('ip_hash', $ipHash)->delete();
        } catch (\Throwable $e) {
            $this->rateLimiterBroken($e);
        }
    }

    /**
     * A rate-limit query failed.
     *
     * It must not take the page down — a login form that 500s because a
     * counter table is missing is worse than one without a counter. But it
     * must not pass silently either: if `auth_ratelimit` was never created,
     * every call fails and brute-force protection is simply absent while the
     * panel looks perfectly healthy. Logging at critical makes a missing
     * migration visible instead.
     */
    private function rateLimiterBroken(\Throwable $e): void
    {
        log_message('critical', 'Rate limiter unavailable (' . self::RL_TABLE
            . '): {msg}. Brute-force protection is NOT active. Run the migration '
            . 'in DEPLOY.md.', ['msg' => $e->getMessage()]);
    }

    /** Human-friendly "try again in X" message. */
    private function waitMessage(int $seconds): string
    {
        if ($seconds < 1) {
            $seconds = 1;
        }
        $minutes = (int) ceil($seconds / 60);
        if ($minutes >= 2) {
            return 'Too many attempts. Please try again in ' . $minutes . ' minutes.';
        }
        if ($seconds > 60) {
            return 'Too many attempts. Please try again in about a minute.';
        }
        return 'Too many attempts. Please try again in ' . $seconds . ' seconds.';
    }

    /**
     * Real client IP (Cloudflare header first — the site is behind Cloudflare).
     */
}
