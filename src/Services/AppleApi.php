<?php
namespace TfSigner\Services;

use TfSigner\Core\Config;
use TfSigner\Core\Logger;

class AppleApi
{
    private string $baseUrl;
    private string $issuerId;
    private string $keyId;
    private string $privateKey;
    private ?string $cachedToken = null;
    private int $tokenExpiry = 0;

    public function __construct(string $issuerId, string $keyId, string $privateKeyPath)
    {
        $this->baseUrl = Config::get('apple.api_base', 'https://api.appstoreconnect.apple.com/v1');
        $this->issuerId = $issuerId;
        $this->keyId = $keyId;
        $this->privateKey = file_get_contents($privateKeyPath);
    }

    /**
     * Generate JWT token for App Store Connect API
     */
    public function getToken(): string
    {
        if ($this->cachedToken && time() < $this->tokenExpiry) {
            return $this->cachedToken;
        }

        $header = self::base64urlEncode(json_encode([
            'alg' => 'ES256',
            'kid' => $this->keyId,
            'typ' => 'JWT',
        ]));

        $payload = self::base64urlEncode(json_encode([
            'iss' => $this->issuerId,
            'iat' => time(),
            'exp' => time() + Config::get('apple.token_expiry', 1200),
            'aud' => 'appstoreconnect-v1',
        ]));

        $signature = '';
        openssl_sign("{$header}.{$payload}", $signature, $this->privateKey, 'sha256');
        $signature = self::base64urlEncode(self::der2dsig($signature));

        $this->cachedToken = "{$header}.{$payload}.{$signature}";
        $this->tokenExpiry = time() + Config::get('apple.token_expiry', 1200) - 60;

        return $this->cachedToken;
    }

    /**
     * List apps
     */
    public function listApps(): array
    {
        return $this->request('GET', '/apps', [
            'limit' => 50,
            'fields[apps]' => 'bundleId,name,sku,primaryLocale',
        ]);
    }

    /**
     * Get app by bundle ID
     */
    public function getAppByBundleId(string $bundleId): ?array
    {
        $result = $this->request('GET', '/apps', [
            'filter[bundleId]' => $bundleId,
            'limit' => 1,
        ]);

        return $result['data'][0] ?? null;
    }

    /**
     * List bundles (bundle IDs)
     */
    public function listBundleIds(): array
    {
        return $this->request('GET', '/bundleIds', ['limit' => 50]);
    }

    /**
     * Create a bundle ID
     */
    public function createBundleId(string $identifier, string $name, string $platform = 'IOS'): array
    {
        return $this->request('POST', '/bundleIds', [], [
            'type' => 'bundleIds',
            'attributes' => [
                'identifier' => $identifier,
                'name' => $name,
                'platform' => $platform,
                'seedId' => $this->issuerId,
            ],
        ]);
    }

    /**
     * List certificates
     */
    public function listCertificates(string $type = 'IOS_DISTRIBUTION'): array
    {
        return $this->request('GET', '/certificates', [
            'filter[certificateType]' => $type,
            'limit' => 50,
        ]);
    }

    /**
     * Create a certificate signing request (CSR) and submit to Apple
     */
    public function createCertificate(string $csrContent, string $type = 'IOS_DISTRIBUTION'): array
    {
        return $this->request('POST', '/certificates', [], [
            'type' => 'certificates',
            'attributes' => [
                'certificateType' => $type,
                'csrContent' => $csrContent,
            ],
        ]);
    }

    /**
     * Revoke a certificate
     */
    public function revokeCertificate(string $certId): bool
    {
        $this->request('DELETE', "/certificates/{$certId}");
        return true;
    }

    /**
     * List provisioning profiles
     */
    public function listProfiles(): array
    {
        return $this->request('GET', '/profiles', [
            'limit' => 50,
            'include' => 'bundleId,certificates',
        ]);
    }

    /**
     * Create a provisioning profile
     */
    public function createProfile(string $name, string $bundleId, string $certId, string $type = 'IOS_APP_STORE'): array
    {
        return $this->request('POST', '/profiles', [], [
            'type' => 'profiles',
            'attributes' => [
                'name' => $name,
                'profileType' => $type,
            ],
            'relationships' => [
                'bundleId' => [
                    'data' => ['type' => 'bundleIds', 'id' => $bundleId],
                ],
                'certificates' => [
                    'data' => [
                        ['type' => 'certificates', 'id' => $certId],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Download provisioning profile data
     */
    public function downloadProfile(string $profileId): ?string
    {
        $result = $this->request('GET', "/profiles/{$profileId}");
        $content = $result['data']['attributes']['profileContent'] ?? null;
        return $content ? base64_decode($content) : null;
    }

    /**
     * Upload to App Store (use altool / xcrun for actual upload)
     */
    public function validateIpa(string $ipaPath, string $appleId, string $appPassword, string $type = 'ios'): array
    {
        $cmd = sprintf(
            'xcrun altool --validate-app -f %s -t %s -u %s -p %s --output-format json 2>&1',
            escapeshellarg($ipaPath),
            escapeshellarg($type),
            escapeshellarg($appleId),
            escapeshellarg($appPassword)
        );

        exec($cmd, $output, $code);
        $result = implode("\n", $output);
        
        Logger::info("IPA validation result", ['code' => $code, 'output' => $result]);

        $json = json_decode($result, true);
        return [
            'success' => $code === 0,
            'code' => $code,
            'output' => $result,
            'data' => $json,
        ];
    }

    /**
     * Upload IPA to App Store Connect
     */
    public function uploadIpa(string $ipaPath, string $appleId, string $appPassword, string $type = 'ios'): array
    {
        $cmd = sprintf(
            'xcrun altool --upload-app -f %s -t %s -u %s -p %s --output-format json 2>&1',
            escapeshellarg($ipaPath),
            escapeshellarg($type),
            escapeshellarg($appleId),
            escapeshellarg($appPassword)
        );

        exec($cmd, $output, $code);
        $result = implode("\n", $output);

        Logger::info("IPA upload result", ['code' => $code, 'output' => $result]);

        $json = json_decode($result, true);
        return [
            'success' => $code === 0,
            'code' => $code,
            'output' => $result,
            'data' => $json,
        ];
    }

    /**
     * Check build processing status
     */
    public function getBuildStatus(string $appId, string $version = ''): array
    {
        $params = [
            'filter[app]' => $appId,
            'sort' => '-version',
            'limit' => 5,
            'fields[builds]' => 'version,uploadedDate,processingState,minOsVersion',
        ];

        return $this->request('GET', '/builds', $params);
    }

    // --- HTTP helpers ---

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->getToken(),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['data' => $body]));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Logger::error("Apple API request failed", ['error' => $error, 'url' => $url]);
            throw new \RuntimeException("Apple API error: {$error}");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            Logger::error("Apple API error response", ['code' => $httpCode, 'body' => $decoded]);
            throw new \RuntimeException(
                "Apple API error ({$httpCode}): " . 
                ($decoded['errors'][0]['detail'] ?? $response)
            );
        }

        return $decoded ?: [];
    }

    // --- Crypto helpers ---

    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Convert DER ECDSA signature to raw r||s format
     */
    private static function der2dsig(string $der): string
    {
        // DER ECDSA signature parser (SECP256R1 = 32-byte r, s)
        // SEQUENCE (0x30) [length] INTEGER (0x02) [len] r INTEGER (0x02) [len] s
        if (strlen($der) < 8) return $der;

        $pos = 0;
        if (ord($der[$pos]) !== 0x30) return $der;
        $pos++;
        $pos += self::skipDerLength($der, $pos);

        if (!isset($der[$pos]) || ord($der[$pos]) !== 0x02) return $der;
        $pos++;
        $rLen = self::readDerLength($der, $pos);
        $r = substr($der, $pos, $rLen);
        // Normalize: skip leading 0x00 padding byte, pad to 32 bytes
        if (strlen($r) === 33 && ord($r[0]) === 0) {
            $r = substr($r, 1);
        } elseif (strlen($r) < 32) {
            $r = str_repeat("\x00", 32 - strlen($r)) . $r;
        }
        $pos += $rLen;

        if (!isset($der[$pos]) || ord($der[$pos]) !== 0x02) return $der;
        $pos++;
        $sLen = self::readDerLength($der, $pos);
        $s = substr($der, $pos, $sLen);
        if (strlen($s) === 33 && ord($s[0]) === 0) {
            $s = substr($s, 1);
        } elseif (strlen($s) < 32) {
            $s = str_repeat("\x00", 32 - strlen($s)) . $s;
        }

        return $r . $s;
    }

    private static function readDerLength(string $data, int &$pos): int
    {
        $byte = ord($data[$pos]);
        $pos++;
        if ($byte < 128) return $byte;
        $numBytes = $byte & 0x7F;
        $len = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $len = ($len << 8) | ord($data[$pos]);
            $pos++;
        }
        return $len;
    }

    private static function skipDerLength(string $data, int &$pos): int
    {
        $byte = ord($data[$pos]);
        $pos++;
        if ($byte < 128) return 1;
        $numBytes = $byte & 0x7F;
        $pos += $numBytes;
        return 1 + $numBytes;
    }
}
