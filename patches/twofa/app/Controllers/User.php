<?php

namespace App\Controllers;

use App\Models\BalanceModel;
use App\Models\CodeModel;
use App\Models\HistoryModel;
use App\Models\LoginSessionModel;
use App\Models\UserModel;
use CodeIgniter\Config\Services;

class User extends BaseController
{
    protected $model, $userid, $user, $time;

    public function __construct()
    {
        $this->userid = session()->userid;
        $this->model = new UserModel();
        $this->user = $this->model->getUser($this->userid);
        $this->time = new \CodeIgniter\I18n\Time();
    }

    public function index()
    {
        $historyModel = new HistoryModel();
        $keysModel    = new \App\Models\KeysModel();

        $data = [
            'title'   => 'Dashboard',
            'user'    => $this->user,
            'time'    => $this->time,
            // Both are scoped by role inside the model, not here.
            'history' => $historyModel->getAll(10, 'DESC', $this->user),
            'stats'   => $keysModel->stats($this->user),
            // The app connector is deliberately not shipped, so that an
            // upgrade never overwrites the copy you wrote for your own
            // apps. That makes forgetting to put it back the one mistake
            // this install can make quietly: the panel looks perfectly
            // healthy while every app in the field fails to check its key.
            // So say it here, where an admin will see it.
            'connectorMissing' => (int) $this->user->level === 1
                && ! is_file(APPPATH . 'Controllers/Connect.php'),
        ];
        return view('User/dashboard', $data);
    }

    public function ref_index()
    {
        $user  = $this->user;
        if ($user->level != 1)
            return redirect()->to('dashboard')->with('msgWarning', 'Access Denied!');

        if ($this->request->getPost())
            return $this->reff_action();

        $mCode = new CodeModel();
        $validation = Services::validation();
        $data = [
            'title' => 'Referral',
            'user' => $user,
            'time' => $this->time,
            'code' => $mCode->getCode(),
            'total_code' => $mCode->countAllResults(),
            'validation' => $validation
        ];
        return view('Admin/referral', $data);
    }

    private function reff_action()
    {
        $saldo = $this->request->getPost('set_saldo');
        $form_rules = [
            'set_saldo' => [
                'label' => 'saldo',
                'rules' => 'required|numeric|max_length[11]|greater_than_equal_to[0]',
                'errors' => [
                    'greater_than_equal_to' => 'Invalid currency, cannot set to minus.'
                ]
            ]
        ];

        if (!$this->validate($form_rules)) {
            return redirect()->back()->withInput()->with('msgDanger', 'Failed, check the form');
        } else {
            $code = random_string('alnum', 6);
            $codeHash = code_digest($code);
            $referral_code = [
                'code' => $codeHash,
                'set_saldo' => ($saldo < 1 ? 0 : $saldo),
                'created_by' => session('unames')
            ];
            $mCode = new CodeModel();
            $ids = $mCode->insert($referral_code, true);
            if ($ids) {
                $msg = "Referral : $code";
                return redirect()->back()->with('msgSuccess', $msg);
            }
        }
    }

    public function api_get_users()
    {
        // API for DataTables.
        //
        // Guarded here, not only by the route group. A group nested inside a
        // filtered group does not inherit that filter on CodeIgniter 4.7, and
        // this endpoint answered sellers for a while because of it. A method's
        // own check cannot be undone by a routing change.
        if (! $this->user || (int) $this->user->level !== 1) {
            return $this->response->setStatusCode(403)
                ->setJSON(['error' => 'Access denied.']);
        }

        // Same whitelist idea as the keys endpoint: only the aliases
        // UserModel::API_getUser() selects, and the real column names.
        $this->normalizeDataTableRequest(
            ['id', 'username', 'fullname', 'saldo', 'level', 'status', 'uplink'],
            ['id_users', 'username', 'fullname', 'saldo', 'level', 'status', 'uplink']
        );

        return $this->model->API_getUser();
    }

    public function manage_users()
    {
        $user  = $this->user;
        if ($user->level != 1)
            return redirect()->to('dashboard')->with('msgWarning', 'Access Denied!');

        $model = $this->model;
        $validation = Services::validation();
        $data = [
            'title' => 'Users',
            'user' => $user,
            // Table is filled by the server-side DataTables endpoint.
            // getUserList() loaded every user row for a check that lives
            // inside an HTML comment in the view.
            'user_list' => $model->countAllResults(),
            'time' => $this->time,
            'validation' => $validation
        ];
        return view('Admin/users', $data);
    }

    public function user_edit($userid = false)
    {
        $user = $this->user;
        if ($user->level != 1)
            return redirect()->to('dashboard')->with('msgWarning', 'Access Denied!');

        if ($this->request->getPost())
            return $this->user_edit_action();

        $model = $this->model;
        $validation = Services::validation();

        $data = [
            'title' => 'Settings',
            'user' => $user,
            'target' => $model->getUser($userid),
            'time' => $this->time,
            'validation' => $validation,
        ];
        return view('Admin/user_edit', $data);
    }

    private function user_edit_action()
    {
        $model = $this->model;
        $userid = $this->request->getPost('user_id');

        $target = $model->getUser($userid);
        if (!$target) {
            $msg = "User no longer exists.";
            return redirect()->to('dashboard')->with('msgDanger', $msg);
        }

        $username = $this->request->getPost('username');

        $form_rules = [
            'username' => [
                'label' => 'username',
                'rules' => "required|alpha_numeric|min_length[4]|max_length[25]|is_unique[users.username,username,$target->username]",
                'errors' => [
                    'is_unique' => 'The {field} has taken by other.'
                ]
            ],
            'fullname' => [
                'label' => 'name',
                'rules' => 'permit_empty|alpha_space|min_length[4]|max_length[155]',
                'errors' => [
                    'alpha_space' => 'The {field} only allow alphabetical characters and spaces.'
                ]
            ],
            'level' => [
                'label' => 'roles',
                'rules' => 'required|numeric|in_list[1,2]',
                'errors' => [
                    'in_list' => 'Invalid {field}.'
                ]
            ],
            'status' => [
                'label' => 'status',
                'rules' => 'required|numeric|in_list[0,1]',
                'errors' => [
                    'in_list' => 'Invalid {field} account.'
                ]
            ],
            'saldo' => [
                'label' => 'saldo',
                'rules' => 'permit_empty|numeric|max_length[11]|greater_than_equal_to[0]',
                'errors' => [
                    'greater_than_equal_to' => 'Invalid currency, cannot set to minus.'
                ]
            ],
            'uplink' => [
                'label' => 'uplink',
                'rules' => 'required|alpha_numeric|is_not_unique[users.username,username,]',
                'errors' => [
                    'is_not_unique' => 'Uplink not registered anymore.'
                ]
            ]
        ];

        if (!$this->validate($form_rules)) {
            return redirect()->back()->withInput()->with('msgDanger', 'Something wrong! Please check the form');
        } else {
            $fullname = $this->request->getPost('fullname');
            $level = $this->request->getPost('level');
            $status = $this->request->getPost('status');
            $saldo = $this->request->getPost('saldo');
            $uplink = $this->request->getPost('uplink');

            $newSaldo = ($saldo < 1) ? 0.0 : (float) $saldo;

            $data_update = [
                'username' => $username,
                'fullname' => $fullname,   // escaped on output, not stored escaped
                'level' => $level,
                'status' => $status,
                'saldo' => $newSaldo,
                'uplink' => $uplink,
            ];

            $db = \Config\Database::connect();
            $db->transBegin();

            try {
                // This form sets an absolute balance, so the movement has to
                // be derived: read the current figure FOR UPDATE, which holds
                // the row until this transaction ends, and take the difference.
                // Reading it outside the lock would let a key purchase land in
                // between and be silently overwritten by this update.
                $locked = $db->query(
                    'SELECT saldo FROM users WHERE id_users = ? FOR UPDATE',
                    [$userid]
                )->getRowObject();

                if (! $locked) {
                    $db->transRollback();
                    return redirect()->back()->with('msgDanger', 'That user no longer exists.');
                }

                $oldSaldo = (float) $locked->saldo;
                $delta    = $newSaldo - $oldSaldo;

                if (! $model->update($userid, $data_update)) {
                    throw new \RuntimeException('User update failed');
                }

                // Only a real movement is worth a row. Editing a name or a
                // role leaves the balance alone and should not show up in
                // the ledger as a zero entry.
                if (abs($delta) >= 0.005) {
                    (new BalanceModel())->record(
                        (int) $userid,
                        $delta,
                        $newSaldo,
                        $delta > 0 ? BalanceModel::TOPUP : BalanceModel::ADJUST,
                        $this->user->username ?? null,
                        $delta > 0
                            ? 'Topped up by admin'
                            : 'Balance reduced by admin'
                    );
                }

                if ($db->transStatus() === false) {
                    throw new \RuntimeException('Transaction failed');
                }
                $db->transCommit();
            } catch (\Throwable $e) {
                $db->transRollback();
                log_message('error', 'Balance edit failed: {msg}', ['msg' => $e->getMessage()]);
                return redirect()->back()->withInput()
                    ->with('msgDanger', 'Could not save. Nothing was changed.');
            }

            return redirect()->back()->with('msgSuccess', "Successfuly update " . esc($target->username) . ".");
        }
    }
    
    public function settings()
    {
        if ($this->request->getPost('password_form'))
            return $this->passwd_act();

        if ($this->request->getPost('fullname_form'))
            return $this->fullname_act();

        $user = $this->user;
        $validation = Services::validation();

        // Sign-in history. Everyone sees their own; an admin additionally
        // gets everyone's, with the username on each row. The scoping lives
        // in the model, so a view cannot widen it by passing a flag.
        $isAdmin = (int) $user->level === 1;

        // Each list carries its own page and page size, so an admin can keep
        // a short list of their own sign-ins next to a long one of everyone's.
        //   pm / sm - page and size for your own
        //   pa / sa - page and size for everyone's
        $myPer  = $this->pageSize('sm');
        $allPer = $this->pageSize('sa');

        // If login_sessions is missing - the migration has not been run yet -
        // show the page without the history rather than a 500. The empty
        // lists say the same thing to the reader either way, and the log
        // says why.
        $mine = $all = [];
        $myPager = $allPager = null;

        try {
            // Separate instances: a CI4 model carries its query builder, and
            // reusing one for two differently-scoped reads is how a `where`
            // ends up on a query that was meant to be unscoped.
            $myPager = $this->pager(
                'pm', 'sm', $myPer,
                (new LoginSessionModel())->countFor($user, false),
                'your-sign-ins'
            );

            $allPager = $isAdmin ? $this->pager(
                'pa', 'sa', $allPer,
                (new LoginSessionModel())->countFor($user, true),
                'all-sign-ins'
            ) : null;

            // Both pagers are built first, then told about each other: every
            // link has to carry the whole page's state, or paging one list
            // would silently reset the other's page and size.
            $myPager['keep'] = ['sm' => $myPer]
                + ($allPager ? ['pa' => $allPager['page'], 'sa' => $allPer] : []);

            if ($allPager) {
                $allPager['keep'] = ['sa' => $allPer, 'pm' => $myPager['page'], 'sm' => $myPer];
            }

            $mine = (new LoginSessionModel())
                ->historyFor($user, false, $myPer, $myPager['offset']);

            if ($allPager) {
                $all = (new LoginSessionModel())
                    ->historyFor($user, true, $allPer, $allPager['offset']);
            }
        } catch (\Throwable $e) {
            log_message('critical',
                'login_sessions unavailable ({msg}). Sign-in history is empty and '
                . 'session-theft protection is NOT active.',
                ['msg' => $e->getMessage()]);
            $myPager = $allPager = null;
        }

        $data = [
            'title' => 'Settings',
            'user' => $user,
            'time' => $this->time,
            'validation' => $validation,
            'mySessions'  => $mine,
            'allSessions' => $all,
            'myPager'     => $myPager,
            'allPager'    => $allPager,
            'thisSession' => LoginSessionModel::hashSessionId(LoginSessionModel::currentId()),
        ];

        // A sign-in pager click or IP toggle asks for just the sign-in panels
        // (frag=1) and swaps them in place — no page reload. Same method, same
        // scoping (historyFor/countFor already limit a seller to their own
        // rows and gate the "all" list on level), so the fragment can show
        // nothing the full page would not.
        if ($this->request->getGet('frag') === '1') {
            return view('User/_signins', $data);
        }

        return view('User/settings', $data);
    }
    
    /**
     * A page size from the query string, or the default.
     *
     * Only the offered sizes are accepted. Taking the number as given would
     * let ?sm=100000 ask the database for the whole table in one query.
     */
    private function pageSize(string $param): int
    {
        $wanted = $this->request->getGet($param);
        $wanted = is_scalar($wanted) ? (int) $wanted : 0;

        return in_array($wanted, LoginSessionModel::PAGE_SIZES, true)
            ? $wanted
            : LoginSessionModel::PAGE_SIZES[1];   // 50
    }

    /**
     * Everything the pager partial needs for one list.
     *
     * The page is clamped to what exists, so ?pm=9999 shows the last page
     * rather than an empty table, and ?pm=-1 shows the first.
     */
    private function pager(
        string $pageParam,
        string $sizeParam,
        int $per,
        int $total,
        string $anchor
    ): array {
        $pages = max(1, (int) ceil($total / $per));

        $page = $this->request->getGet($pageParam);
        $page = is_scalar($page) ? (int) $page : 1;
        $page = min($pages, max(1, $page));

        $offset = ($page - 1) * $per;

        return [
            'offset' => $offset,
            'param'  => $pageParam,
            'size'   => $sizeParam,
            'page'   => $page,
            'pages'  => $pages,
            'total'  => $total,
            'per'    => $per,
            'from'   => $total === 0 ? 0 : $offset + 1,
            'to'     => min($total, $page * $per),
            'sizes'  => LoginSessionModel::PAGE_SIZES,
            'anchor' => $anchor,
            // Filled in by the caller, once every list on the page is known.
            'keep'   => [],
        ];
    }

    private function passwd_act()
    {
        $current = $this->request->getPost('current');
        $password = $this->request->getPost('password');

        $user = $this->user;
        $validation = Services::validation();

        // Argon2id only, matching login. The legacy md5-then-bcrypt scheme has
        // been removed everywhere, so the current password is verified the one
        // modern way.
        $currentOk = password_verify($current, $user->password);

        if (! $currentOk) {
            $msg = "Wrong current password.";
            $validation->setError('current', $msg);
        } elseif ($current == $password) {
            $msg = "Nothing to change.";
            $validation->setError('password', $msg);
        }

        $form_rules = [
            'current' => [
                'label' => 'current',
                'rules' => 'required|min_length[6]|max_length[45]',
            ],
            'password' => [
                'label' => 'password',
                'rules' => 'required|min_length[6]|max_length[45]',
            ],
            'password2' => [
                'label' => 'confirm',
                'rules' => 'required|min_length[6]|max_length[45]|matches[password]',
                'errors' => [
                    'matches' => '{field} not match, check the {field}.'
                ]
            ],
        ];

        if (!$this->validate($form_rules)) {
            return redirect()->back()->withInput()->with('msgDanger', 'Something wrong! Please check the form');
        } else {
            // Argon2id, the only password hasher in the panel.
            $newPassword = create_passwordV2($password);
            $this->model->update(session('userid'), ['password' => $newPassword]);
            return redirect()->back()->with('msgSuccess', 'Password Successfuly Changed.');
        }
    }

    /**
     * Two-factor settings. One page, several states:
     *   - off, nothing pending      -> a button to begin
     *   - off, a secret pending      -> the key + a box to confirm a live code
     *   - on                         -> status, remaining recovery codes,
     *                                   regenerate, and disable (needs a code)
     *
     * The pending secret lives in the session, never the database, until the
     * user proves they can read a code from it — so a half-finished enrolment
     * can never lock anyone out.
     */
    public function twofa()
    {
        $user = $this->user;
        if (! $user) {
            return redirect()->to('login');
        }

        $tfm  = \App\Models\TwoFactorModel::class;
        $post = $this->request->getPost('action');

        if ($post === 'begin' && ! $tfm::isEnabled($user)) {
            session()->set('2fa_setup_secret', \App\Libraries\Totp::secret());
            return redirect()->to('settings/2fa');
        }

        if ($post === 'cancel') {
            session()->remove('2fa_setup_secret');
            return redirect()->to('settings/2fa')->with('msgWarning', 'Setup cancelled.');
        }

        if ($post === 'enable' && ! $tfm::isEnabled($user)) {
            $secret = (string) session()->get('2fa_setup_secret');
            $code   = (string) $this->request->getPost('code');
            if ($secret === '') {
                return redirect()->to('settings/2fa')->with('msgDanger', 'Start again — the setup expired.');
            }
            if (! \App\Libraries\Totp::verify($secret, $code)) {
                return redirect()->to('settings/2fa')->with('msgDanger', 'That code did not match. Check your phone clock and try again.');
            }
            $codes = $tfm::enable((int) $user->id_users, $secret);
            session()->remove('2fa_setup_secret');
            // Show the recovery codes exactly once, on the next render.
            session()->setFlashdata('recovery_codes', $codes);
            return redirect()->to('settings/2fa')->with('msgSuccess', 'Two-factor authentication is now on. Save your recovery codes below.');
        }

        // The two actions that must not be doable with a stolen session alone:
        // both demand a live factor first.
        if (($post === 'disable' || $post === 'regen') && $tfm::isEnabled($user)) {
            $code  = (string) $this->request->getPost('code');
            $ok    = $tfm::verifyCode($user, $code)
                  || $tfm::useRecoveryCode((int) $user->id_users, $code);
            if (! $ok) {
                return redirect()->to('settings/2fa')->with('msgDanger', 'Enter a valid current code to do that.');
            }
            if ($post === 'disable') {
                $tfm::disable((int) $user->id_users);
                return redirect()->to('settings/2fa')->with('msgSuccess', 'Two-factor authentication is off.');
            }
            $codes = $tfm::resetRecoveryCodes((int) $user->id_users);
            session()->setFlashdata('recovery_codes', $codes);
            return redirect()->to('settings/2fa')->with('msgSuccess', 'New recovery codes generated. The old ones no longer work.');
        }

        // ---- render ----
        $pending = (string) session()->get('2fa_setup_secret');
        $uri = $pending !== ''
            ? \App\Libraries\Totp::otpauthUri($pending, $user->username, 'ZERO Panel')
            : null;

        return view('User/twofa', [
            'title'      => 'Two-Factor',
            'user'       => $user,
            'time'       => $this->time,
            'enabled'    => $tfm::isEnabled($user),
            'pending'    => $pending !== '' ? $pending : null,
            'otpauthUri' => $uri,
            'remaining'  => $tfm::isEnabled($user) ? $tfm::remainingRecoveryCodes((int) $user->id_users) : 0,
            'recovery'   => session()->getFlashdata('recovery_codes'),
        ]);
    }

    private function fullname_act()
    {
        $user = $this->user;
        $newName = $this->request->getPost('fullname');

        if ($user->fullname == $newName) {
            $validation = Services::validation();
            $msg = "Nothing to change.";
            $validation->setError('fullname', $msg);
        }

        $form_rules = [
            'fullname' => [
                'label' => 'name',
                'rules' => 'required|alpha_space|min_length[4]|max_length[155]',
                'errors' => [
                    'alpha_space' => 'The {field} only allow alphabetical characters and spaces.'
                ]
            ]
        ];

        if (!$this->validate($form_rules)) {
            return redirect()->back()->withInput()->with('msgDanger', 'Failed! Please check the form');
        } else {
            $this->model->update(session('userid'), ['fullname' => $newName]);
            return redirect()->back()->with('msgSuccess', 'Account Detail Successfuly Changed.');
        }
    }
}
