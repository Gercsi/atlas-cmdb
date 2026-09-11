<?php

declare(strict_types=1);

namespace Cmdb;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

final class Sso
{
    private const SECRET_CONTEXT = 'atlas-sso-client-secret-v1';
    private const FLOW_TTL = 600;
    private const MAX_RESPONSE = 1048576;
    private const CAPABILITIES = ['import_data','export_data','export_diagram','view_contact_details'];

    public function __construct(private App $app)
    {
    }

    public function publicStatus(): array
    {
        $row = $this->row();
        return ['enabled' => (bool)$row['enabled'], 'display_name' => $row['display_name'] ?: 'Microsoft Entra ID'];
    }

    public function adminSettings(string $callbackUrl): array
    {
        $row = $this->settings();
        unset($row['client_secret_encrypted']);
        $row['client_secret_configured'] = !empty($this->row()['client_secret_encrypted']);
        $row['callback_url'] = $callbackUrl;
        $row['suggested_issuer_url'] = $row['tenant_id'] ? self::entraIssuer($row['tenant_id']) : '';
        return $row;
    }

    public function save(array $input, string $callbackUrl): array
    {
        $old = $this->row();
        $provider = in_array($input['provider_type'] ?? '', ['entra','adfs'], true) ? $input['provider_type'] : '';
        $displayName = trim((string)($input['display_name'] ?? ''));
        $tenantId = trim((string)($input['tenant_id'] ?? ''));
        $issuerUrl = trim((string)($input['issuer_url'] ?? ''));
        $clientId = trim((string)($input['client_id'] ?? ''));
        $enabled = self::bool($input['enabled'] ?? false);
        $autoProvision = self::bool($input['auto_provision'] ?? false);
        $role = in_array($input['default_role'] ?? '', ['viewer','editor'], true) ? $input['default_role'] : '';
        $groupId = trim((string)($input['required_group_id'] ?? ''));
        $domains = self::domains($input['allowed_email_domains'] ?? []);
        $caps = array_values(array_unique(array_intersect(
            is_array($input['default_capabilities'] ?? null) ? $input['default_capabilities'] : [],
            self::CAPABILITIES
        )));
        $fields = [];
        if ($provider === '') {
            $fields['provider_type'] = 'Válassz Entra ID vagy AD FS szolgáltatót.';
        }
        if ($displayName === '' || mb_strlen($displayName) > 100 || preg_match('/[\x00-\x1F\x7F]/', $displayName)) {
            $fields['display_name'] = '1–100 karakteres gombfelirat szükséges.';
        }
        if ($clientId !== '' && (mb_strlen($clientId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $clientId))) {
            $fields['client_id'] = 'A kliensazonosító érvénytelen.';
        }
        if ($provider === 'entra' && $tenantId !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $tenantId)) {
            $fields['tenant_id'] = 'Egyetlen Entra-bérlő GUID azonosítója szükséges.';
        }
        if ($provider === 'adfs' && $issuerUrl !== '') {
            try {
                $issuerUrl = self::httpsUrl($issuerUrl, 'Kibocsátó URL');
            } catch (ApiError $e) {
                $fields['issuer_url'] = $e->getMessage();
            }
        }
        if ($groupId !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $groupId)) {
            $fields['required_group_id'] = 'A csoportazonosító formátuma érvénytelen.';
        }
        if ($role === '') {
            $fields['default_role'] = 'Az automatikus fiók csak megtekintő vagy szerkesztő lehet.';
        }

        $encrypted = $old['client_secret_encrypted'];
        if (self::bool($input['clear_client_secret'] ?? false)) {
            $encrypted = null;
        }
        if (array_key_exists('client_secret', $input) && $input['client_secret'] !== '') {
            if (!is_string($input['client_secret']) || strlen($input['client_secret']) > 4096 || str_contains($input['client_secret'], "\0")) {
                $fields['client_secret'] = 'A kliens titka legfeljebb 4096 karakter lehet.';
            } else {
                $encrypted = SecretStore::encrypt($input['client_secret'], self::SECRET_CONTEXT);
            }
        }
        if ($enabled) {
            if ($clientId === '') {
                $fields['client_id'] = 'Bekapcsoláshoz kliensazonosító szükséges.';
            }
            if (!$encrypted) {
                $fields['client_secret'] = 'Bekapcsoláshoz kliens titka szükséges.';
            }
            if ($provider === 'entra' && $tenantId === '') {
                $fields['tenant_id'] = 'Bekapcsoláshoz bérlőazonosító szükséges.';
            }
            if ($provider === 'adfs' && $issuerUrl === '') {
                $fields['issuer_url'] = 'Bekapcsoláshoz kibocsátó URL szükséges.';
            }
        }
        if ($fields) {
            throw new ApiError(422, 'Ellenőrizd az SSO-beállításokat.', $fields);
        }

        $this->app->tx(function () use ($enabled, $provider, $displayName, $tenantId, $issuerUrl, $clientId, $encrypted, $domains, $groupId, $autoProvision, $role, $caps, $old) {
            $this->app->run('UPDATE sso_settings SET enabled=?,provider_type=?,display_name=?,tenant_id=?,issuer_url=?,client_id=?,client_secret_encrypted=?,allowed_email_domains=?,required_group_id=?,auto_provision=?,default_role=?,default_capabilities=?,updated_at=UTC_TIMESTAMP(6),updated_by=? WHERE id=1', [
                (int)$enabled,$provider,$displayName,$tenantId ?: null,$issuerUrl ?: null,$clientId ?: null,$encrypted,
                json_encode($domains, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),$groupId ?: null,(int)$autoProvision,$role,
                json_encode($caps, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),$this->app->user['id'] ?? null,
            ]);
            $this->app->audit(
                'sso_settings',
                'sso_settings',
                '1',
                ['enabled' => (bool)$old['enabled'],'provider_type' => $old['provider_type'],'client_secret_configured' => !empty($old['client_secret_encrypted'])],
                ['enabled' => $enabled,'provider_type' => $provider,'client_secret_configured' => !empty($encrypted)]
            );
        });
        return $this->adminSettings($callbackUrl);
    }

    public function testConfiguration(): array
    {
        $settings = $this->settings();
        $this->assertComplete($settings, false);
        $metadata = $this->discovery($settings);
        return [
            'success' => true,
            'issuer' => $metadata['issuer'],
            'authorization_endpoint' => $metadata['authorization_endpoint'],
            'token_endpoint' => $metadata['token_endpoint'],
            'jwks_uri' => $metadata['jwks_uri'],
        ];
    }

    public function begin(string $callbackUrl): string
    {
        $settings = $this->settings();
        $this->assertComplete($settings, true);
        $metadata = $this->discovery($settings);
        $state = self::random(32);
        $nonce = self::random(32);
        $verifier = self::random(64);
        $_SESSION['sso_flow'] = ['state' => $state,'nonce' => $nonce,'verifier' => $verifier,'expires_at' => time() + self::FLOW_TTL];
        $query = http_build_query([
            'client_id' => $settings['client_id'], 'response_type' => 'code', 'redirect_uri' => $callbackUrl,
            'response_mode' => 'query', 'scope' => 'openid profile email', 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => self::b64(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        return $metadata['authorization_endpoint'].'?'.$query;
    }

    public function complete(array $query, string $callbackUrl): array
    {
        $flow = $_SESSION['sso_flow'] ?? null;
        unset($_SESSION['sso_flow']);
        if (!is_array($flow) || ($flow['expires_at'] ?? 0) < time() || !is_string($query['state'] ?? null) || !hash_equals((string)($flow['state'] ?? ''), $query['state'])) {
            throw new ApiError(401, 'Az SSO-válasz lejárt vagy érvénytelen. Indítsd újra a bejelentkezést.');
        }
        if (isset($query['error'])) {
            throw new ApiError(401, 'Az identitásszolgáltató elutasította a bejelentkezést.');
        }
        if (!is_string($query['code'] ?? null) || $query['code'] === '') {
            throw new ApiError(401, 'Az SSO-válaszból hiányzik az engedélyezési kód.');
        }
        $settings = $this->settings();
        $this->assertComplete($settings, true);
        $metadata = $this->discovery($settings);
        $secret = SecretStore::decrypt($settings['client_secret_encrypted'], self::SECRET_CONTEXT);
        $tokens = $this->fetchJson($metadata['token_endpoint'], [
            'grant_type' => 'authorization_code','client_id' => $settings['client_id'],'client_secret' => $secret,
            'code' => $query['code'],'redirect_uri' => $callbackUrl,'code_verifier' => $flow['verifier'],
        ]);
        if (!is_string($tokens['id_token'] ?? null) || $tokens['id_token'] === '') {
            throw new ApiError(502, 'Az identitásszolgáltató nem adott azonosító tokent.');
        }
        $jwks = $this->fetchJson($metadata['jwks_uri']);
        $claims = self::verifyIdToken($tokens['id_token'], $metadata, $jwks, $settings, $flow['nonce']);
        return $this->resolveUser($claims, $settings);
    }

    public static function verifyIdToken(string $token, array $metadata, array $jwks, array $settings, string $nonce, ?int $now = null): array
    {
        try {
            $segments = explode('.', $token);
            if (count($segments) !== 3) {
                throw new \UnexpectedValueException('segments');
            }
            $header = json_decode(self::unb64($segments[0]), true, 16, JSON_THROW_ON_ERROR);
            if (($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
                throw new \UnexpectedValueException('header');
            }
            $rawKey = null;
            foreach ($jwks['keys'] ?? [] as $candidate) {
                if (($candidate['kid'] ?? null) === $header['kid']) {
                    $rawKey = $candidate;
                    break;
                }
            }
            if (!$rawKey || ($rawKey['kty'] ?? '') !== 'RSA' || isset($rawKey['alg']) && $rawKey['alg'] !== 'RS256' || isset($rawKey['use']) && $rawKey['use'] !== 'sig') {
                throw new \UnexpectedValueException('key');
            }
            $oldLeeway = JWT::$leeway;
            $oldTimestamp = JWT::$timestamp;
            JWT::$leeway = 60;
            JWT::$timestamp = $now;
            try {
                $decoded = JWT::decode($token, JWK::parseKeySet(['keys' => [$rawKey]], 'RS256'));
                $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($claims)) {
                    throw new \UnexpectedValueException('claims');
                }
            } finally {
                JWT::$leeway = $oldLeeway;
                JWT::$timestamp = $oldTimestamp;
            }
        } catch (\Throwable) {
            throw new ApiError(401, 'Az SSO azonosító token aláírása vagy érvényessége hibás.');
        }
        $expectedIssuer = (string)($metadata['issuer'] ?? '');
        if ((!is_int($claims['exp'] ?? null) && !is_float($claims['exp'] ?? null)) || (!is_int($claims['iat'] ?? null) && !is_float($claims['iat'] ?? null))) {
            throw new ApiError(401, 'Az SSO tokenből hiányzik a kötelező időbeli érvényesség.');
        }
        if (!is_string($claims['iss'] ?? null) || !hash_equals($expectedIssuer, $claims['iss'])) {
            throw new ApiError(401, 'Az SSO token kibocsátója nem egyezik a beállítással.');
        }
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (!in_array($settings['client_id'], $audiences, true)) {
            throw new ApiError(401, 'Az SSO token másik alkalmazásnak készült.');
        }
        if ((count($audiences) > 1 || isset($claims['azp'])) && ($claims['azp'] ?? '') !== $settings['client_id']) {
            throw new ApiError(401, 'Az SSO token jogosult kliense nem megfelelő.');
        }
        if (!is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw new ApiError(401, 'Az SSO token egyszer használatos azonosítója hibás.');
        }
        if (($settings['provider_type'] ?? '') === 'entra' && (!is_string($claims['tid'] ?? null) || strcasecmp($claims['tid'], $settings['tenant_id']) !== 0)) {
            throw new ApiError(401, 'Az SSO token másik Entra-bérlőből érkezett.');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new ApiError(401, 'Az SSO tokenből hiányzik a felhasználó azonosítója.');
        }
        if (isset($rawKey['issuer']) && is_string($rawKey['issuer'])) {
            $keyIssuer = str_replace('{tenantid}', strtolower((string)($claims['tid'] ?? '')), strtolower($rawKey['issuer']));
            if (!hash_equals(rtrim(strtolower($claims['iss']), '/'), rtrim($keyIssuer, '/'))) {
                throw new ApiError(401, 'Az SSO aláírókulcs másik kibocsátóhoz tartozik.');
            }
        }
        return $claims;
    }

    public function resolveUser(array $claims, ?array $settings = null): array
    {
        $settings ??= $this->settings();
        $loginClaim = $claims['preferred_username'] ?? $claims['email'] ?? $claims['upn'] ?? '';
        $login = is_string($loginClaim) ? trim($loginClaim) : '';
        $emailClaim = is_string($claims['email'] ?? null) ? $claims['email'] : $login;
        $email = filter_var($emailClaim, FILTER_VALIDATE_EMAIL) ?: null;
        if ($settings['allowed_email_domains']) {
            $domainSource = $email ?: $login;
            $at = strrpos($domainSource, '@');
            $domain = $at === false ? '' : strtolower(substr($domainSource, $at + 1));
            if (!in_array($domain, $settings['allowed_email_domains'], true)) {
                throw new ApiError(403, 'Ez az e-mail-tartomány nem használhatja az SSO-bejelentkezést.');
            }
        }
        if ($settings['required_group_id']) {
            if (isset($claims['_claim_names']['groups']) || !empty($claims['hasgroups'])) {
                throw new ApiError(403, 'A felhasználó túl sok csoport tagja; a szükséges csoport nem ellenőrizhető a tokenből.');
            }
            if (!is_array($claims['groups'] ?? null) || !in_array($settings['required_group_id'], $claims['groups'], true)) {
                throw new ApiError(403, 'A felhasználó nem tagja az engedélyezett SSO-csoportnak.');
            }
        }
        $issuer = self::issuer($settings);
        $providerKey = hash('sha256', $issuer."\n".$settings['client_id']);
        $subject = ($settings['provider_type'] === 'entra' && is_string($claims['oid'] ?? null) && $claims['oid'] !== '')
            ? strtolower($settings['tenant_id']).':'.$claims['oid'] : $claims['sub'];
        if (!is_string($subject) || strlen($subject) > 255) {
            throw new ApiError(403, 'Az SSO felhasználói azonosítója túl hosszú vagy érvénytelen.');
        }
        $identity = $this->app->one('SELECT u.* FROM user_identities i JOIN users u ON u.id=i.user_id WHERE i.provider_key=? AND i.subject=?', [$providerKey,$subject]);
        if ($identity) {
            if (!(bool)$identity['active']) {
                throw new ApiError(403, 'A CMDB-fiók le van tiltva.');
            }
            $this->app->run('UPDATE user_identities SET email=?,last_login_at=UTC_TIMESTAMP(6) WHERE provider_key=? AND subject=?', [$email,$providerKey,$subject]);
            return $identity;
        }
        if (!$settings['auto_provision']) {
            throw new ApiError(403, 'Ehhez az SSO-azonosítóhoz még nincs CMDB-fiók. Kérd az adminisztrátor segítségét.');
        }
        return $this->app->tx(function () use ($providerKey, $subject, $email, $login, $settings) {
            $existing = $this->app->one('SELECT u.* FROM user_identities i JOIN users u ON u.id=i.user_id WHERE i.provider_key=? AND i.subject=? FOR UPDATE', [$providerKey,$subject]);
            if ($existing) {
                return $existing;
            }
            $base = strtolower((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', strstr($login, '@', true) ?: $login));
            $base = trim($base, '.-_');
            if (strlen($base) < 3) {
                $base = 'sso-user';
            }
            $base = substr($base, 0, 82);
            $username = $base;
            if ($this->app->one('SELECT id FROM users WHERE username=?', [$username])) {
                $username = $base.'-'.substr(hash('sha256', $subject), 0, 10);
            }
            $id = App::id();
            $password = password_hash(self::random(48), PASSWORD_DEFAULT);
            $this->app->run('INSERT INTO users(id,username,password_hash,role,capabilities,active) VALUES (?,?,?,?,?,1)', [$id,$username,$password,$settings['default_role'],json_encode($settings['default_capabilities'], JSON_THROW_ON_ERROR)]);
            $this->app->run('INSERT INTO user_identities(provider_key,subject,user_id,email,created_at,last_login_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))', [$providerKey,$subject,$id,$email]);
            $this->app->audit('sso_provision', 'users', $id, [], ['username' => $username,'role' => $settings['default_role'],'provider' => $settings['provider_type']]);
            return $this->app->one('SELECT * FROM users WHERE id=?', [$id]);
        });
    }

    private function row(): array
    {
        return $this->app->one('SELECT * FROM sso_settings WHERE id=1') ?? throw new ApiError(503, 'Az SSO-adatbázisséma hiányzik. Futtasd: php install.php');
    }

    private function settings(): array
    {
        $row = $this->row();
        $row['enabled'] = (bool)$row['enabled'];
        $row['auto_provision'] = (bool)$row['auto_provision'];
        foreach (['allowed_email_domains','default_capabilities'] as $field) {
            try {
                $row[$field] = json_decode($row[$field], true, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new ApiError(503, 'A tárolt SSO-beállítás sérült.');
            }
            if (!is_array($row[$field])) {
                throw new ApiError(503, 'A tárolt SSO-beállítás sérült.');
            }
        }
        return $row;
    }

    private function assertComplete(array $settings, bool $mustBeEnabled): void
    {
        if ($mustBeEnabled && !$settings['enabled']) {
            throw new ApiError(403, 'Az SSO-bejelentkezés nincs bekapcsolva.');
        }
        if (!$settings['client_id'] || !$settings['client_secret_encrypted']) {
            throw new ApiError(422, 'Hiányzik az SSO kliensazonosítója vagy titka.');
        }
        if ($settings['provider_type'] === 'entra' && !$settings['tenant_id']) {
            throw new ApiError(422, 'Hiányzik az Entra-bérlő azonosítója.');
        }
        if ($settings['provider_type'] === 'adfs' && !$settings['issuer_url']) {
            throw new ApiError(422, 'Hiányzik az AD FS kibocsátó URL-je.');
        }
    }

    private function discovery(array $settings): array
    {
        $expected = self::issuer($settings);
        $metadata = $this->fetchJson($expected.'/.well-known/openid-configuration');
        foreach (['issuer','authorization_endpoint','token_endpoint','jwks_uri'] as $field) {
            if (!is_string($metadata[$field] ?? null)) {
                throw new ApiError(502, 'Az OIDC felderítési dokumentum hiányos.');
            }
        }
        if (!hash_equals($expected, rtrim($metadata['issuer'], '/'))) {
            throw new ApiError(502, 'Az OIDC felderítési dokumentum kibocsátója eltér a beállítástól.');
        }
        foreach (['authorization_endpoint','token_endpoint','jwks_uri'] as $field) {
            $metadata[$field] = self::httpsUrl($metadata[$field], $field);
        }
        $methods = $metadata['token_endpoint_auth_methods_supported'] ?? [];
        if (is_array($methods) && $methods && !in_array('client_secret_post', $methods, true)) {
            throw new ApiError(422, 'Az identitásszolgáltató nem támogatja a client_secret_post hitelesítést.');
        }
        return $metadata;
    }

    private static function requestJson(string $url, ?array $form = null): array
    {
        $url = self::httpsUrl($url, 'OIDC végpont');
        $headers = "Accept: application/json\r\nUser-Agent: Atlas-CMDB/1.0\r\n";
        $method = 'GET';
        $content = '';
        if ($form !== null) {
            $method = 'POST';
            $content = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
            $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }
        $context = stream_context_create(['http' => ['method' => $method,'header' => $headers,'content' => $content,'timeout' => 10,'ignore_errors' => true,'follow_location' => 0,'max_redirects' => 0], 'ssl' => ['verify_peer' => true,'verify_peer_name' => true,'allow_self_signed' => false]]);
        $stream = @fopen($url, 'rb', false, $context);
        if (!$stream) {
            throw new ApiError(502, 'Az identitásszolgáltató nem érhető el.');
        }
        $body = stream_get_contents($stream, self::MAX_RESPONSE + 1);
        $meta = stream_get_meta_data($stream);
        fclose($stream);
        $status = 0;
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $line, $m)) {
                $status = (int)$m[1];
            }
        }
        if ($status < 200 || $status >= 300 || !is_string($body) || strlen($body) > self::MAX_RESPONSE) {
            throw new ApiError(502, 'Az identitásszolgáltató hibás választ adott.');
        }
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiError(502, 'Az identitásszolgáltató válasza nem érvényes JSON.');
        }
        if (!is_array($decoded)) {
            throw new ApiError(502, 'Az identitásszolgáltató válasza hibás.');
        }
        return $decoded;
    }

    private function fetchJson(string $url, ?array $form = null): array
    {
        return self::requestJson($url, $form);
    }

    private static function issuer(array $settings): string
    {
        return $settings['provider_type'] === 'entra' ? self::entraIssuer((string)$settings['tenant_id']) : self::httpsUrl((string)$settings['issuer_url'], 'Kibocsátó URL');
    }
    private static function entraIssuer(string $tenant): string
    {
        return 'https://login.microsoftonline.com/'.strtolower($tenant).'/v2.0';
    }
    private static function httpsUrl(string $url, string $label): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!$parts || preg_match('/[\x00-\x20\x7F]/', $url) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || strlen($url) > 1000) {
            throw new ApiError(422, "$label: érvényes HTTPS URL szükséges.");
        }
        return $url;
    }
    private static function domains(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) {
            throw new ApiError(422, 'Az engedélyezett e-mail-tartományok listája hibás.', ['allowed_email_domains' => 'Vesszővel vagy soronként add meg.']);
        }
        $out = [];
        foreach ($value as $domain) {
            $domain = strtolower(trim((string)$domain));
            if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain)) {
                throw new ApiError(422, 'Az egyik e-mail-tartomány érvénytelen.', ['allowed_email_domains' => $domain]);
            }
            $out[] = $domain;
        }
        return array_values(array_unique($out));
    }
    private static function bool(mixed $value): bool
    {
        return in_array($value, [true,1,'1'], true);
    }
    private static function random(int $bytes): string
    {
        return self::b64(random_bytes($bytes));
    }
    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
    private static function unb64(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new \UnexpectedValueException('base64');
        }
        return $decoded;
    }
}
