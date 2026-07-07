<?php

/**
 * AuthKeycloak — RMUTI Keycloak OIDC Single Sign-On plugin for LimeSurvey
 *
 * Flow:
 *  1. User clicks "Login with RMUTI SSO" → GET /login?sso=keycloak
 *  2. beforeLogin() generates state, stores in session, redirects to Keycloak
 *  3. Keycloak redirects back → GET /login?code=xxx&state=xxx
 *  4. beforeLogin() exchanges code → token, fetches userinfo, finds/creates user,
 *     sets up LimeSurvey session (mirrors LSUserIdentity::postLogin()), redirects to admin home
 */
class AuthKeycloak extends LimeSurvey\PluginManager\AuthPluginBase
{
    protected $storage = 'DbStorage';

    protected static $name = 'RMUTI Passport';
    protected static $description = 'RMUTI Keycloak OIDC Single Sign-On (passport.rmuti.ac.th)';

    public $allowedPublicMethods = [];

    protected $settings = [
        'keycloak_url' => [
            'type'    => 'string',
            'label'   => 'Keycloak Base URL',
            'help'    => 'e.g. https://passport.rmuti.ac.th',
            'default' => 'https://passport.rmuti.ac.th',
        ],
        'realm' => [
            'type'    => 'string',
            'label'   => 'Realm',
            'default' => 'RMUTi-LDAP',
        ],
        'client_id' => [
            'type'    => 'string',
            'label'   => 'Client ID',
            'default' => 'rmuti-limesurvey',
        ],
        'client_secret' => [
            'type'  => 'password',
            'label' => 'Client Secret',
        ],
        'verify_ssl' => [
            'type'    => 'checkbox',
            'label'   => 'Verify SSL certificate when talking to Keycloak',
            'help'    => 'Uncheck only if your Keycloak endpoint uses a self-signed or internal CA certificate.',
            'default' => '1',
        ],
        'is_default' => [
            'type'    => 'checkbox',
            'label'   => 'Make Keycloak SSO the default login method',
            'default' => '0',
        ],
        'autocreate' => [
            'type'    => 'checkbox',
            'label'   => 'Auto-create LimeSurvey user on first SSO login',
            'default' => '1',
        ],
        'automaticsurveycreation' => [
            'type'    => 'checkbox',
            'label'   => 'Grant survey creation permission to auto-created users',
            'default' => '0',
        ],
        'allow_initial_user' => [
            'type'    => 'checkbox',
            'label'   => 'Allow initial admin user (uid=1) to login via Keycloak',
            'default' => '0',
        ],
    ];

    // ─── Init ────────────────────────────────────────────────────────────────

    public function init()
    {
        $this->subscribe('beforeLogin');
        $this->subscribe('newLoginForm');
        $this->subscribe('newUserSession');
        $this->subscribe('getGlobalBasePermissions');
        $this->subscribe('afterLogout');

        // Hide "Change password" and "Change email" buttons for SSO users.
        // SSO accounts are managed by Keycloak/LDAP — changing them inside
        // LimeSurvey is meaningless and would be reset on next login anyway.
        if (!empty(Yii::app()->session['is_sso_user'])) {
            Yii::app()->clientScript->registerScript(
                'keycloak-hide-auth-ui',
                '(function(){
    function hideSsoAuthButtons(){
        document.querySelectorAll(
            \'a[href*="changePassword"],a[href*="changeEmail"],\' +
            \'a[href*="change_password"],a[href*="change_email"]\'
        ).forEach(function(el){ el.style.display="none"; });
        document.querySelectorAll("a,button").forEach(function(el){
            var t=(el.textContent||"").trim().toLowerCase();
            if(t.indexOf("change password")>-1||t.indexOf("change email")>-1)
                el.style.display="none";
        });
    }
    if(document.readyState==="loading")
        document.addEventListener("DOMContentLoaded",hideSsoAuthButtons);
    else hideSsoAuthButtons();
})();',
                CClientScript::POS_END
            );
        }
    }

    // ─── Permissions ─────────────────────────────────────────────────────────

    public function getGlobalBasePermissions()
    {
        $this->getEvent()->append('globalBasePermissions', [
            'auth_keycloak' => [
                'create'      => false,
                'update'      => false,
                'delete'      => false,
                'import'      => false,
                'export'      => false,
                'title'       => gT('Use Keycloak SSO authentication'),
                'description' => gT('Use Keycloak SSO authentication'),
                'img'         => 'usergroup',
            ],
        ]);
    }

    // ─── Events ──────────────────────────────────────────────────────────────

    public function afterLogout()
    {
        $base = getenv('PUBLIC_URL') ?: 'https://e-survey.oarit.rmuti.ac.th';
        Yii::app()->getController()->redirect(rtrim($base, '/'));
        exit();
    }

    public function beforeLogin()
    {
        $event = $this->getEvent();

        if ($this->get('is_default', null, null, false)) {
            $event->set('default', get_class($this));
        }

        // Step 1: User clicked the SSO button → initiate OAuth2
        if (isset($_GET['sso']) && $_GET['sso'] === 'keycloak') {
            $this->initiateOAuth();
            return;
        }

        // Step 2: Keycloak redirected back with authorization code
        if (isset($_GET['code']) && isset($_GET['state'])) {
            $this->handleOAuthCallback($_GET['code'], $_GET['state']);
            return;
        }
    }

    public function newLoginForm()
    {
        if (!$this->isConfigured()) {
            return;
        }

        $ssoUrl = Yii::app()->getController()->createUrl(
            '/admin/authentication/sa/login',
            ['sso' => 'keycloak']
        );
        $ssoUrlEncoded = CHtml::encode($ssoUrl);

        $html  = '<div style="margin-top:16px;">';
        $html .= '<hr style="margin-bottom:16px;">';
        $html .= '<a href="' . $ssoUrlEncoded . '" class="btn btn-default btn-block">';
        $html .= '<span class="fa fa-key" style="margin-right:6px;"></span>';
        $html .= gT('เข้าสู่ระบบด้วย RMUTI SSO');
        $html .= '</a>';
        $html .= '</div>';

        // Auto-redirect when "RMUTI Passport" is selected from the auth-plugin dropdown.
        // LimeSurvey uses bootstrap-select (custom div widget), so native "change" alone
        // is unreliable. We combine: native change, click-then-check, form submit
        // intercept, and short-interval polling to cover all cases.
        $html .= '<script>
(function(){
    var ssoUrl="' . $ssoUrlEncoded . '";
    var gone=false;
    function go(){ if(!gone){ gone=true; window.location.href=ssoUrl; } }

    function check(){
        var sels=document.querySelectorAll("select");
        for(var i=0;i<sels.length;i++){
            var s=sels[i];
            if(s.value==="AuthKeycloak") return go();
            var o=s.querySelector("option[value=\'AuthKeycloak\']");
            if(o&&o.selected) return go();
        }
    }

    // native change (works without bootstrap-select)
    document.addEventListener("change",check,true);
    // bootstrap-select sets value after click, check 150ms later
    document.addEventListener("click",function(){ setTimeout(check,150); },true);

    // intercept form submit — most reliable fallback
    function wireSubmit(){
        var form=document.querySelector("form");
        if(form) form.addEventListener("submit",function(e){
            var sels=this.querySelectorAll("select");
            for(var i=0;i<sels.length;i++){
                if(sels[i].value==="AuthKeycloak"){ e.preventDefault(); go(); return; }
            }
        });
    }

    function init(){ check(); wireSubmit(); }
    if(document.readyState==="loading"){
        document.addEventListener("DOMContentLoaded",init);
    } else { init(); }

    // poll for 3 s in case bootstrap-select initialises after our script
    var n=0,t=setInterval(function(){ check(); if(++n>=15) clearInterval(t); },200);
})();
</script>';

        $this->getEvent()->getContent($this)->addContent($html);
    }

    public function newUserSession()
    {
        $identity = $this->getEvent()->get('identity');
        if ($identity->plugin !== 'AuthKeycloak') {
            return;
        }
        // User selected "Keycloak SSO" from dropdown and clicked submit —
        // initiate OAuth2 redirect instead of username/password auth.
        $this->initiateOAuth();
    }

    // ─── OAuth2 helpers ──────────────────────────────────────────────────────

    private function initiateOAuth()
    {
        if (!$this->isConfigured()) {
            Yii::app()->setFlashMessage(gT('Keycloak ยังไม่ได้ตั้งค่า กรุณาติดต่อผู้ดูแลระบบ'), 'error');
            $this->redirectToLogin();
        }

        $kc    = $this->getKcConfig();
        $ep    = $this->getEndpoints($kc);
        $state = bin2hex(random_bytes(16));

        Yii::app()->session['keycloak_state']      = $state;
        Yii::app()->session['keycloak_state_time'] = time();

        $params = http_build_query([
            'client_id'     => $kc['client_id'],
            'redirect_uri'  => $this->getRedirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid profile email',
            'state'         => $state,
        ]);

        Yii::app()->getController()->redirect($ep['auth'] . '?' . $params);
        exit();
    }

    private function handleOAuthCallback($code, $state)
    {
        // Verify state to prevent CSRF
        $storedState = isset(Yii::app()->session['keycloak_state'])
            ? Yii::app()->session['keycloak_state'] : null;
        $stateTime   = isset(Yii::app()->session['keycloak_state_time'])
            ? Yii::app()->session['keycloak_state_time'] : 0;

        unset(Yii::app()->session['keycloak_state']);
        unset(Yii::app()->session['keycloak_state_time']);

        if (!$storedState || $storedState !== $state || (time() - $stateTime) > 600) {
            Yii::app()->setFlashMessage(gT('SSO ล้มเหลว: session หมดอายุหรือข้อมูลไม่ถูกต้อง กรุณาลองใหม่'), 'error');
            $this->redirectToLogin();
        }

        if (!$this->isConfigured()) {
            Yii::app()->setFlashMessage(gT('Keycloak ยังไม่ได้ตั้งค่า'), 'error');
            $this->redirectToLogin();
        }

        $kc = $this->getKcConfig();
        $ep = $this->getEndpoints($kc);

        // 1. Exchange authorization code → tokens
        $tokenRes = $this->httpPost($ep['token'], [
            'grant_type'    => 'authorization_code',
            'client_id'     => $kc['client_id'],
            'client_secret' => $kc['client_secret'],
            'code'          => $code,
            'redirect_uri'  => $this->getRedirectUri(),
        ], $kc['verify_ssl']);

        if (!$tokenRes || $tokenRes['code'] !== 200) {
            // The raw Keycloak error (error_description / response body) is logged
            // server-side only — this endpoint is reachable pre-login, so echoing it
            // back to the browser would hand an unauthenticated visitor internal
            // details about the IdP's failure mode.
            $detail = $tokenRes
                ? (json_decode($tokenRes['body'], true)['error_description'] ?? $tokenRes['body'])
                : 'curl error';
            error_log('AuthKeycloak token exchange failed: ' . $detail);
            Yii::app()->setFlashMessage(gT('SSO ล้มเหลว: ไม่สามารถแลก token กับ Keycloak ได้ กรุณาติดต่อผู้ดูแลระบบ'), 'error');
            $this->redirectToLogin();
        }

        $tokenData   = json_decode($tokenRes['body'], true);
        $accessToken = $tokenData['access_token'] ?? null;

        if (!$accessToken) {
            Yii::app()->setFlashMessage(gT('SSO ล้มเหลว: ไม่ได้รับ access token'), 'error');
            $this->redirectToLogin();
        }

        // 2. Fetch user info from Keycloak
        $infoRes = $this->httpGet($ep['userinfo'], $accessToken, $kc['verify_ssl']);

        if (!$infoRes || $infoRes['code'] !== 200) {
            Yii::app()->setFlashMessage(gT('SSO ล้มเหลว: ไม่สามารถดึงข้อมูลผู้ใช้จาก Keycloak'), 'error');
            $this->redirectToLogin();
        }

        $kcu      = json_decode($infoRes['body'], true);
        $username = $kcu['preferred_username'] ?? $kcu['sub'] ?? null;

        if (!$username) {
            Yii::app()->setFlashMessage(gT('SSO ล้มเหลว: ไม่พบ username ใน userinfo'), 'error');
            $this->redirectToLogin();
        }

        // RMUTI LDAP stores Thai name in firstNameThai / lastNameThai
        $kcEmail  = !empty($kcu['email']) ? $kcu['email'] : null;
        $fullName = trim(($kcu['firstNameThai'] ?? '') . ' ' . ($kcu['lastNameThai'] ?? ''));
        if ($fullName === '') {
            $fullName = trim(($kcu['given_name'] ?? '') . ' ' . ($kcu['family_name'] ?? ''));
        }
        if ($fullName === '') {
            $fullName = $kcu['name'] ?? $username;
        }

        // 3. Find or create LimeSurvey user
        $oUser = $this->api->getUserByName($username);

        if ($oUser === null) {
            if (!$this->get('autocreate', null, null, true)) {
                Yii::app()->setFlashMessage(gT('ไม่พบบัญชีผู้ใช้ในระบบ กรุณาติดต่อผู้ดูแลระบบ'), 'error');
                $this->redirectToLogin();
            }

            $email   = $kcEmail ?: ($username . '@sso.rmuti.ac.th');
            $newPass = createPassword();
            $iNewUID = User::insertUser($username, $newPass, $fullName, 1, $email);

            if (!$iNewUID) {
                Yii::app()->setFlashMessage(gT('SSO ล้มเหลว: ไม่สามารถสร้างบัญชีผู้ใช้ในระบบ'), 'error');
                $this->redirectToLogin();
            }

            Permission::model()->setGlobalPermission($iNewUID, 'auth_keycloak');

            if ($this->get('automaticsurveycreation', null, null, false)) {
                Permission::model()->setGlobalPermission($iNewUID, 'surveys', ['create_p']);
            }

            $oUser = $this->api->getUserByName($username);

            if ($oUser === null) {
                Yii::app()->setFlashMessage(gT('SSO ล้มเหลว: สร้างบัญชีไม่สำเร็จ'), 'error');
                $this->redirectToLogin();
            }
        } else {
            // Check if initial admin is allowed to use SSO
            if ((int) $oUser->uid === 1 && !$this->get('allow_initial_user', null, null, false)) {
                Yii::app()->setFlashMessage(gT('ผู้ดูแลระบบหลัก (uid=1) ไม่อนุญาตให้ใช้ Keycloak SSO'), 'error');
                $this->redirectToLogin();
            }

            // Sync name from Keycloak; preserve existing email if Keycloak provides none
            $oUser->full_name = !empty($fullName) ? $fullName : $oUser->full_name;
            if ($kcEmail) {
                $oUser->email = $kcEmail;
            }
            $oUser->save();

            // Mark pre-existing account as SSO user so UI restrictions apply
            if (!Permission::model()->hasGlobalPermission('auth_keycloak', 'read', $oUser->uid)) {
                Permission::model()->setGlobalPermission($oUser->uid, 'auth_keycloak');
            }
        }

        // 4. Complete login — mirrors LSUserIdentity::postLogin()
        Yii::app()->session['is_sso_user'] = true;
        regenerateCSRFToken();

        $identity       = new LSUserIdentity($oUser->users_name, null);
        $identity->id   = $oUser->uid;
        $identity->user = $oUser;
        App()->user->login($identity);

        Yii::app()->session['loginID']              = (int) $oUser->uid;
        Yii::app()->session['user']                 = $oUser->users_name;
        Yii::app()->session['full_name']            = $oUser->full_name;
        Yii::app()->session['htmleditormode']       = $oUser->htmleditormode;
        Yii::app()->session['templateeditormode']   = $oUser->templateeditormode;
        Yii::app()->session['questionselectormode'] = $oUser->questionselectormode;
        Yii::app()->session['dateformat']           = $oUser->dateformat;
        Yii::app()->session['session_hash']         = hash(
            'sha256',
            Yii::app()->getConfig('SessionName') . $oUser->users_name . $oUser->uid
        );

        $lang = ($oUser->lang && $oUser->lang !== 'auto') ? $oUser->lang : getBrowserLanguage();
        Yii::app()->session['adminlang'] = $lang;
        App()->setLanguage($lang);

        if (Permission::model()->hasGlobalPermission('superadmin', 'read', $oUser->uid)) {
            Yii::app()->getPluginManager()->readConfigFiles();
        }

        $oUser->last_login = date('Y-m-d H:i:s');
        $oUser->save();

        FailedLoginAttempt::model()->deleteAttempts(FailedLoginAttempt::TYPE_LOGIN);

        Yii::app()->getController()->redirect(array('/admin/index'));
        exit();
    }

    // ─── HTTP helpers (curl) ──────────────────────────────────────────────────

    private function httpPost($url, $data, $verifySsl = true)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $body !== false ? ['body' => $body, 'code' => $code] : null;
    }

    private function httpGet($url, $bearerToken, $verifySsl = true)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $bearerToken],
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $body !== false ? ['body' => $body, 'code' => $code] : null;
    }

    // ─── Config helpers ───────────────────────────────────────────────────────

    /**
     * Env vars take priority over plugin DB settings — useful when the
     * plugin configure UI is unavailable (K8s deployment via ConfigMap/Secret).
     *
     * Env vars: KEYCLOAK_URL, KEYCLOAK_REALM, KEYCLOAK_CLIENT_ID, KEYCLOAK_CLIENT_SECRET,
     * KEYCLOAK_VERIFY_SSL
     */
    private function getKcConfig()
    {
        $envVerify = getenv('KEYCLOAK_VERIFY_SSL');
        return [
            'url'           => rtrim(
                getenv('KEYCLOAK_URL') ?: $this->get('keycloak_url', null, null, 'https://passport.rmuti.ac.th'),
                '/'
            ),
            'realm'         => getenv('KEYCLOAK_REALM')
                               ?: $this->get('realm', null, null, 'RMUTi-LDAP'),
            'client_id'     => getenv('KEYCLOAK_CLIENT_ID')
                               ?: $this->get('client_id', null, null, 'rmuti-limesurvey'),
            'client_secret' => getenv('KEYCLOAK_CLIENT_SECRET')
                               ?: $this->get('client_secret', null, null, ''),
            'verify_ssl'    => $envVerify !== false
                               ? filter_var($envVerify, FILTER_VALIDATE_BOOLEAN)
                               : (bool) $this->get('verify_ssl', null, null, true),
        ];
    }

    private function getEndpoints($kc)
    {
        $base = $kc['url'] . '/realms/' . $kc['realm'] . '/protocol/openid-connect';
        return [
            'auth'     => $base . '/auth',
            'token'    => $base . '/token',
            'userinfo' => $base . '/userinfo',
        ];
    }

    private function getRedirectUri()
    {
        // Use PUBLIC_URL env var (set in K8s ConfigMap) to get the correct public-facing URL.
        // App()->getBaseUrl(true) may return the internal cluster URL in K8s.
        $base = getenv('PUBLIC_URL') ?: rtrim(App()->getBaseUrl(true), '/');
        return rtrim($base, '/') . '/index.php/admin/authentication/sa/login';
    }

    private function isConfigured()
    {
        $kc = $this->getKcConfig();
        return !empty($kc['url']) && !empty($kc['client_id']) && !empty($kc['client_secret']);
    }

    private function redirectToLogin()
    {
        Yii::app()->getController()->redirect(['/admin/authentication/sa/login']);
        exit();
    }
}
