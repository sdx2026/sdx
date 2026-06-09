<?php
namespace TfSigner\Services;

use TfSigner\Core\Database;

/**
 * GitHub Actions dispatch — single source of truth.
 * Used by TaskService (worker) and trigger_github.php (CLI).
 */
class GitHubService
{
    private string $repo;
    private string $workflowFile;
    private string $token;

    public function __construct(string $repo = 'sdx2026/sdx', string $workflowFile = 'sign.yml')
    {
        $this->repo = $repo;
        $this->workflowFile = $workflowFile;
        $this->token = $this->loadToken();
    }

    // ── Token ──────────────────────────────────────────────

    private function loadToken(): string
    {
        $pdo = Database::connection();
        $row = $pdo->query("SELECT value FROM settings WHERE key='github_token'")->fetch();
        return $row['value'] ?? '';
    }

    public function hasToken(): bool
    {
        return !empty($this->token);
    }

    // ── Branch detection ──────────────────────────────────

    public function detectDefaultBranch(): string
    {
        $ch = curl_init("https://api.github.com/repos/{$this->repo}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/vnd.github+json',
                'User-Agent: TF-Signer/1.0',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $data = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $data['default_branch'] ?? 'master';
    }

    // ── Payload builder (THE single source of truth) ──────

    public function buildPayload(array $params): array
    {
        $branch = $params['ref'] ?? $this->detectDefaultBranch();

        $inputs = [
            'task_id'    => (string)($params['task_id'] ?? '0'),
            'ipa_url'    => $params['ipa_url'] ?? '',
            'cert_url'   => $params['cert_url'] ?? '',
            'key_url'    => $params['key_url'] ?? '',
            'profile_url'=> $params['profile_url'] ?? '',
            'bundle_id'  => $params['bundle_id'] ?? '',
            'apple_id'   => $params['apple_id'] ?? '',
            'apple_password' => $params['app_password'] ?? '',
        ];

        // Optional overrides
        if (!empty($params['override_version'])) {
            $inputs['override_version'] = $params['override_version'];
        }
        if (!empty($params['override_build'])) {
            $inputs['override_build'] = $params['override_build'];
        }

        return [
            'ref'    => $branch,
            'inputs' => $inputs,
        ];
    }

    // ── Dispatch ──────────────────────────────────────────

    public function dispatch(array $payload): array
    {
        if (!$this->hasToken()) {
            throw new \RuntimeException('[E9004] GitHub Token not configured. Add it in Settings → GitHub Token.');
        }

        $url = "https://api.github.com/repos/{$this->repo}/actions/workflows/{$this->workflowFile}/dispatches";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'Content-Type: application/json',
                'User-Agent: TF-Signer/1.0',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 204) {
            return ['success' => true, 'message' => 'GitHub Actions workflow triggered'];
        }

        throw new \RuntimeException('[E9005] GitHub trigger failed (HTTP ' . $httpCode . '): ' . $response);
    }
}
