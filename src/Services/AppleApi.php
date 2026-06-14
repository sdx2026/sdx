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
     * Get bundle ID resource by identifier
     */
    public function getBundleIdByIdentifier(string $identifier): ?array
    {
        $result = $this->request('GET', '/bundleIds', [
            'filter[identifier]' => $identifier,
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
                'seedId' => '',
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


    // ===== TestFlight & Beta Review Auto-Submission =====

    /**
     * Get build by app ID and version (waiting for processing to complete)
     */
    public function getBuildByVersion(string $appId, string $version): ?array
    {
        $result = $this->request('GET', '/builds', [
            'filter[app]' => $appId,
            'filter[version]' => $version,
            'limit' => 1,
        ]);
        return $result['data'][0] ?? null;
    }

    /**
     * Wait for build processing to complete, return build ID
     */
    public function waitForBuild(string $appId, string $version = '', int $maxWaitSec = 3600): ?array
    {
        $start = time();
        $interval = 30;
        
        $noBuildRetries = 0;
        while (time() - $start < $maxWaitSec) {
            $build = null;
            if (!empty($version)) {
                $build = $this->getBuildByVersion($appId, $version);
            }
            // Fallback: if version lookup failed (or no version specified), grab latest build
            if (!$build) {
                $result = $this->getBuildStatus($appId);
                $build = $result['data'][0] ?? null;
            }
            // Fail fast if no builds exist at all (retry a few times to allow for processing delay)
            if (!$build) {
                $noBuildRetries++;
                if ($noBuildRetries >= 3) {
                    throw new \RuntimeException("No builds found for app {$appId}. Upload may have failed or build is still processing.");
                }
                Logger::info("No builds found yet, retrying...", ['attempt' => $noBuildRetries, 'app_id' => $appId]);
                sleep($interval);
                $interval = min($interval + 10, 60);
                continue;
            }
            $noBuildRetries = 0;
            $state = $build['attributes']['processingState'] ?? 'PROCESSING';
            if ($state === 'VALID') {
                Logger::info("Build processing complete", ['build_id' => $build['id'], 'version' => $build['attributes']['version'] ?? '?']);
                return $build;
            }
            if ($state === 'INVALID') {
                throw new \RuntimeException("Build processing failed (INVALID state)");
            }
            Logger::info("Build still processing", ['state' => $state, 'version' => $build['attributes']['version'] ?? '?']);
            sleep($interval);
            $interval = min($interval + 10, 60); // Progressive backoff
        }
        
        throw new \RuntimeException("Build processing timed out after {$maxWaitSec}s");
    }

    /**
     * Get App Store version ID for an app
     */
    public function getAppStoreVersionId(string $appId): ?string
    {
        $result = $this->request('GET', '/apps/' . $appId . '/appStoreVersions', [
            'limit' => 1,
        ]);
        return $result['data'][0]['id'] ?? null;
    }

    /**
     * Submit build for Beta App Review
     * This triggers Apple's TestFlight review process
     */
    public function submitForBetaReview(string $buildId): array
    {
        return $this->request('POST', '/betaAppReviewSubmissions', [], [
            'type' => 'betaAppReviewSubmissions',
            'relationships' => [
                'build' => [
                    'data' => ['type' => 'builds', 'id' => $buildId],
                ],
            ],
        ]);
    }

    /**
     * List beta groups for an app
     */
    public function listBetaGroups(string $appId): array
    {
        return $this->request('GET', '/betaGroups', [
            'filter[app]' => $appId,
            'limit' => 20,
            'fields[betaGroups]' => 'name,publicLinkEnabled,publicLink,publicLinkLimit,publicLinkLimitEnabled',
        ]);
    }

    /**
     * Create a beta group
     */
    public function createBetaGroup(string $appId, string $name): array
    {
        return $this->request('POST', '/betaGroups', [], [
            'type' => 'betaGroups',
            'attributes' => [
                'name' => $name,
            ],
            'relationships' => [
                'app' => [
                    'data' => ['type' => 'apps', 'id' => $appId],
                ],
            ],
        ]);
    }

    /**
     * Add build to a beta group
     */
    public function addBuildToBetaGroup(string $buildId, string $groupId): void
    {
        // Apple relationship endpoints expect data as an array
        $url = $this->baseUrl . "/betaGroups/{$groupId}/relationships/builds";
        $ch = curl_init($url);
        $body = json_encode(['data' => [['type' => 'builds', 'id' => $buildId]]]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->getToken(),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) throw new \RuntimeException("Apple API error: {$error}");
        if ($httpCode >= 400) {
            // 409 = build already in group, not an error
            if ($httpCode === 409) {
                \TfSigner\Core\Logger::info("Build already in beta group, skipping", ['build' => $buildId, 'group' => $groupId]);
                return;
            }
            $decoded = json_decode($response, true);
            throw new \RuntimeException("Apple API error ({$httpCode}): " . ($decoded['errors'][0]['detail'] ?? $response));
        }
        \TfSigner\Core\Logger::info("Build added to beta group", ['build' => $buildId, 'group' => $groupId]);
    }

    /**
     * Enable public TestFlight link for a beta group
     */
    public function enablePublicLink(string $groupId, int $limit = 10000): array
    {
        return $this->request('PATCH', "/betaGroups/{$groupId}", [], [
            'type' => 'betaGroups',
            'id' => $groupId,
            'attributes' => [
                'publicLinkEnabled' => true,
                'publicLinkLimitEnabled' => true,
                'publicLinkLimit' => $limit,
            ],
        ]);
    }

    /**
     * Get the public TestFlight link for a beta group
     */
    public function getPublicLink(string $groupId): ?string
    {
        $result = $this->request('GET', "/betaGroups/{$groupId}", [
            'fields[betaGroups]' => 'publicLink,publicLinkEnabled',
        ]);
        return $result['data']['attributes']['publicLink'] ?? null;
    }

    /**
     * Invite a beta tester by email
     */
    public function inviteBetaTester(string $email, string $firstName = '', string $lastName = ''): array
    {
        return $this->request('POST', '/betaTesters', [], [
            'type' => 'betaTesters',
            'attributes' => [
                'email' => $email,
                'firstName' => $firstName ?: 'Test',
                'lastName' => $lastName ?: 'User',
            ],
        ]);
    }

    /**
     * Full auto-submit flow: wait for build → submit review → enable public link
     * Returns the TestFlight public URL
     */
    public function autoSubmitToTestFlight(string $appId, string $version, string $betaGroupName = 'Auto TF Group'): array
    {
        Logger::info("Auto-submit to TestFlight started", ['app_id' => $appId, 'version' => $version ?: '(latest)']);

        // Step 1: Wait for build to finish processing
        Logger::info("Step 1: Waiting for build processing...");
        $build = $this->waitForBuild($appId, $version);
        $buildId = $build['id'];
        Logger::info("Build ready", ['build_id' => $buildId, 'version' => $build['attributes']['version'] ?? '?']);

        // Step 2: Submit for Beta App Review (with QC state retry)
        Logger::info("Step 2: Submitting for Beta App Review...");
        $reviewSubmitted = false;
        $reviewRetries = 0;
        $maxReviewRetries = 10;
        while (!$reviewSubmitted && $reviewRetries < $maxReviewRetries) {
            try {
                $review = $this->submitForBetaReview($buildId);
                Logger::info("Beta review submitted", ["submission_id" => $review["data"]["id"] ?? "?"]);
                $reviewSubmitted = true;
            } catch (\RuntimeException $e) {
                if (strpos($e->getMessage(), "409") !== false) {
                    Logger::info("Beta review already submitted");
                    $reviewSubmitted = true;
                } elseif (strpos($e->getMessage(), "422") !== false && strpos($e->getMessage(), "not in a valid processing state") !== false) {
                    $reviewRetries++;
                    if ($reviewRetries >= $maxReviewRetries) throw $e;
                    Logger::info("QC not ready, retrying in 60s", ["attempt" => $reviewRetries]);
                    sleep(60);
                } else {
                    throw $e;
                }
            }
        }

        // Step 3: Get or create beta group
        Logger::info("Step 3: Setting up beta group...");
        $groups = $this->listBetaGroups($appId);
        $groupId = $groups['data'][0]['id'] ?? null;

        if (!$groupId) {
            $group = $this->createBetaGroup($appId, $betaGroupName);
            $groupId = $group['data']['id'];
            Logger::info("Created beta group", ['group_id' => $groupId]);
        }

        // Step 4: Add build to beta group
        Logger::info("Step 4: Adding build to beta group...");
        $this->addBuildToBetaGroup($buildId, $groupId);

        // Step 5: Enable public link
        Logger::info("Step 5: Enabling public link...");
        $this->enablePublicLink($groupId);

        // Step 6: Get the public link
        $publicLink = $this->getPublicLink($groupId);
        Logger::info("TestFlight public link generated", ['link' => $publicLink]);

        return [
            'success' => true,
            'build_id' => $buildId,
            'group_id' => $groupId,
            'public_link' => $publicLink,
            'status' => 'submitted_for_review',
            'message' => '已提交Beta审核，通常24-48小时内通过。审核通过后链接即可使用。',
        ];
    }


    /**
     * Verify an Apple Developer account is still valid by trying to list apps
     * Returns true if account works, false otherwise
     */
    public function verifyAccount(): array
    {
        try {
            $result = $this->request('GET', '/apps', ['limit' => 1]);
            $appCount = count($result['data'] ?? []);
            return ['valid' => true, 'app_count' => $appCount, 'message' => "Account valid, {$appCount} apps found"];
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            // Check for specific auth failures
            if (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
                return ['valid' => false, 'reason' => 'auth_expired', 'message' => 'API Key 已失效，请重新生成'];
            }
            if (stripos($msg, '403') !== false || stripos($msg, 'forbidden') !== false) {
                return ['valid' => false, 'reason' => 'account_blocked', 'message' => '开发者账号可能被封禁或权限不足'];
            }
            return ['valid' => false, 'reason' => 'unknown', 'message' => $msg];
        }
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

        if ($method === 'POST' || $method === 'PATCH') {
            if ($method === 'PATCH') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            } else {
                curl_setopt($ch, CURLOPT_POST, true);
            }
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
     * Convert DER ECDSA signature to raw r||s format (64 bytes)
     */
    private static function der2dsig(string $der): string
    {
        if (strlen($der) < 8) return $der;
        if (ord($der[0]) !== 0x30) return $der;

        // Parse SEQUENCE
        $pos = 2;
        $seqLen = ord($der[1]);
        if ($seqLen > 127) {
            $lenBytes = $seqLen & 0x7F;
            $seqLen = 0;
            for ($i = 0; $i < $lenBytes; $i++) {
                $seqLen = ($seqLen << 8) | ord($der[$pos++]);
            }
        }
        if ($pos + $seqLen > strlen($der)) return $der;

        // Parse INTEGER r
        if (ord($der[$pos]) !== 0x02) return $der;
        $pos++;
        $rLen = ord($der[$pos++]);
        $r = substr($der, $pos, $rLen);
        $pos += $rLen;

        // Parse INTEGER s
        if (ord($der[$pos]) !== 0x02) return $der;
        $pos++;
        $sLen = ord($der[$pos++]);
        $s = substr($der, $pos, $sLen);

        // Normalize r to 32 bytes
        if (strlen($r) > 32 && ord($r[0]) === 0) $r = substr($r, 1);
        while (strlen($r) < 32) $r = "\x00" . $r;

        // Normalize s to 32 bytes
        if (strlen($s) > 32 && ord($s[0]) === 0) $s = substr($s, 1);
        while (strlen($s) < 32) $s = "\x00" . $s;

        return $r . $s;
    }

}
