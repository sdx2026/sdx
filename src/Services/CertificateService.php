<?php
namespace TfSigner\Services;

use TfSigner\Core\Config;
use TfSigner\Core\Database;
use TfSigner\Core\Logger;

class CertificateService
{
    /**
     * Generate a new certificate (self-signed) and key pair
     */
    public function generate(array $params): array
    {
        $name = $params['name'] ?? 'TF Signer Cert';
        $teamId = $params['team_id'] ?? '';
        $password = $params['password'] ?? '';
        $type = $params['type'] ?? 'distribution';
        $commonName = $params['common_name'] ?? "{$teamId} - {$name}";
        $email = $params['email'] ?? '';
        $validDays = (int)($params['valid_days'] ?? 365);

        $certsDir = Config::get('storage.certs');
        $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '_' . time();

        // Generate private key
        $keyPath = "{$certsDir}/{$baseName}.key";
        $keyConfig = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $privateKey = openssl_pkey_new($keyConfig);
        openssl_pkey_export($privateKey, $keyOutput, $password, $keyConfig);
        file_put_contents($keyPath, $keyOutput);
        chmod($keyPath, 0600);

        // Create CSR
        $dn = [
            'commonName' => $commonName,
            'organizationName' => $teamId ?: 'TF Signer',
        ];
        if ($email) $dn['emailAddress'] = $email;

        $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $csrOutput);

        // Self-sign certificate
        $cert = openssl_csr_sign($csr, null, $privateKey, $validDays, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certOutput);

        $certPath = "{$certsDir}/{$baseName}.pem";
        file_put_contents($certPath, $certOutput);

        // Get certificate details
        $certInfo = openssl_x509_parse($cert);
        $serial = $certInfo['serialNumber'] ?? '';
        $expiresAt = date('Y-m-d H:i:s', $certInfo['validTo_time_t'] ?? time());

        // Store in DB
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO certificates (name, type, cert_path, key_path, password, serial, team_id, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $type, $certPath, $keyPath, $password, $serial, $teamId, $expiresAt]);

        $certId = $pdo->lastInsertId();

        Logger::info("Certificate generated", ['id' => $certId, 'name' => $name, 'serial' => $serial]);

        return [
            'id' => $certId,
            'name' => $name,
            'type' => $type,
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'csr' => $csrOutput,
            'cert' => $certOutput,
            'serial' => $serial,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Generate CSR only (for submitting to Apple)
     */
    public function generateCsr(array $params): array
    {
        $result = $this->generate($params);
        return [
            'csr' => $result['csr'],
            'key_path' => $result['key_path'],
            'cert_id' => $result['id'],
        ];
    }

    /**
     * Import an existing certificate
     */
    public function import(array $params): array
    {
        $name = $params['name'] ?? 'Imported Cert';
        $type = $params['type'] ?? 'distribution';
        $teamId = $params['team_id'] ?? '';
        $password = $params['password'] ?? '';
        
        $certsDir = Config::get('storage.certs');
        $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '_' . time();

        // Handle P12 import
        if (!empty($params['p12_data'])) {
            $p12Data = base64_decode($params['p12_data']);
            $p12Path = "{$certsDir}/{$baseName}.p12";
            file_put_contents($p12Path, $p12Data);

            $certs = [];
            openssl_pkcs12_read($p12Data, $certs, $password);

            $keyPath = "{$certsDir}/{$baseName}.key";
            file_put_contents($keyPath, $certs['pkey']);
            chmod($keyPath, 0600);

            $certPath = "{$certsDir}/{$baseName}.pem";
            file_put_contents($certPath, $certs['cert']);
        }
        // Handle uploaded PEM files
        elseif (!empty($params['cert_data']) && !empty($params['key_data'])) {
            $certPath = "{$certsDir}/{$baseName}.pem";
            file_put_contents($certPath, $params['cert_data']);

            $keyPath = "{$certsDir}/{$baseName}.key";
            file_put_contents($keyPath, $params['key_data']);
            chmod($keyPath, 0600);
        } else {
            throw new \InvalidArgumentException("Must provide p12_data or (cert_data + key_data)");
        }

        // Parse certificate
        $certContent = file_get_contents($certPath);
        $certInfo = openssl_x509_parse($certContent);
        $serial = $certInfo['serialNumber'] ?? '';
        $expiresAt = date('Y-m-d H:i:s', $certInfo['validTo_time_t'] ?? time());

        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO certificates (name, type, cert_path, key_path, password, serial, team_id, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $type, $certPath, $keyPath, $password, $serial, $teamId, $expiresAt]);

        $certId = $pdo->lastInsertId();
        Logger::info("Certificate imported", ['id' => $certId, 'name' => $name]);

        return [
            'id' => $certId,
            'name' => $name,
            'serial' => $serial,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Get all certificates
     */
    public function listAll(): array
    {
        $pdo = Database::connection();
        return $pdo->query("SELECT * FROM certificates ORDER BY created_at DESC")->fetchAll();
    }

    /**
     * Get active certificates
     */
    public function listActive(): array
    {
        $pdo = Database::connection();
        return $pdo->query("SELECT * FROM certificates WHERE is_active = 1 ORDER BY created_at DESC")->fetchAll();
    }

    /**
     * Get single certificate
     */
    public function get(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM certificates WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Delete certificate
     */
    public function delete(int $id): bool
    {
        $cert = $this->get($id);
        if (!$cert) return false;

        // Delete files
        @unlink($cert['cert_path']);
        @unlink($cert['key_path']);

        $pdo = Database::connection();
        $stmt = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
        $stmt->execute([$id]);

        Logger::info("Certificate deleted", ['id' => $id]);
        return true;
    }
}
