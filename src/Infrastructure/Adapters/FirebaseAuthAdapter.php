<?php
/**
 * FirebaseAuthAdapter - Adaptador para Firebase Authentication
 *
 * RESPONSABILIDADE:
 * - Verificar Firebase ID Token (JWT) enviado pelo frontend
 * - Validar autenticidade do token com chaves públicas do Firebase
 * - Extrair claims (uid, phone_number, email)
 * - Interface segura para VerifyBriefingPhone use case
 *
 * FLUXO:
 * 1. Frontend autentica via Firebase Auth (Phone SMS OTP)
 * 2. Firebase retorna ID Token (JWT)
 * 3. Frontend envia token para backend
 * 4. Este adapter valida token e retorna dados verificados
 *
 * BIBLIOTECAS:
 * - firebase/php-jwt (validação JWT)
 * - google/auth (buscar chaves públicas)
 *
 * @package LimpVix\Infrastructure\Adapters
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Adapters;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Google\Auth\AccessToken;

defined('ABSPATH') || exit;

class FirebaseAuthAdapter
{
    /**
     * @var string Firebase Project ID
     */
    private $projectId;

    /**
     * @var string URL das chaves públicas do Firebase
     */
    private const FIREBASE_KEYS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    /**
     * @var int Cache de chaves públicas (segundos)
     */
    private const KEYS_CACHE_TTL = 3600;

    /**
     * Construtor
     *
     * @param string|null $projectId Firebase Project ID (se null, busca de wp-config.php)
     */
    public function __construct(?string $projectId = null)
    {
        if ($projectId === null) {
            if (!defined('LIMPVIX_FIREBASE_PROJECT_ID')) {
                throw new \RuntimeException(
                    'LIMPVIX_FIREBASE_PROJECT_ID não definido no wp-config.php. ' .
                    'Adicione: define(\'LIMPVIX_FIREBASE_PROJECT_ID\', \'seu-projeto-id\');'
                );
            }

            $projectId = LIMPVIX_FIREBASE_PROJECT_ID;
        }

        $this->projectId = $projectId;
    }

    /**
     * Verificar ID Token do Firebase
     *
     * Valida JWT assinado pelo Firebase e retorna claims verificados.
     *
     * @param string $idToken JWT retornado pelo Firebase Authentication
     * @return array{uid: string, phone_number: string, email: ?string, auth_time: int}
     * @throws \InvalidArgumentException Se token for inválido
     * @throws \RuntimeException Se falhar ao buscar chaves públicas
     */
    public function verifyIdToken(string $idToken): array
    {
        // 1. Validar formato básico
        if (empty($idToken) || !is_string($idToken)) {
            throw new \InvalidArgumentException('ID Token inválido ou vazio');
        }

        try {
            // 2. Buscar chaves públicas do Firebase
            $publicKeys = $this->getFirebasePublicKeys();

            // 3. Decodificar JWT (validação automática de assinatura)
            $decodedToken = $this->decodeToken($idToken, $publicKeys);

            // 4. Validar claims obrigatórios
            $this->validateClaims($decodedToken);

            // 5. Extrair dados verificados
            return [
                'uid' => $decodedToken->sub,
                'phone_number' => $decodedToken->phone_number ?? null,
                'email' => $decodedToken->email ?? null,
                'auth_time' => $decodedToken->auth_time,
                'firebase_project_id' => $decodedToken->aud
            ];

        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new \InvalidArgumentException('Token expirado: ' . $e->getMessage());
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            throw new \InvalidArgumentException('Assinatura inválida: ' . $e->getMessage());
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[LimpVix] Erro ao verificar Firebase token: ' . $e->getMessage());
            }

            throw new \InvalidArgumentException('Falha ao verificar token: ' . $e->getMessage());
        }
    }

    /**
     * Buscar chaves públicas do Firebase
     *
     * Chaves são cacheadas via WordPress Transients API.
     *
     * @return array<string, string> Mapa kid => chave pública PEM
     * @throws \RuntimeException Se falhar ao buscar chaves
     */
    private function getFirebasePublicKeys(): array
    {
        // Tentar cache primeiro
        $cacheKey = 'limpvix_firebase_public_keys';
        $cachedKeys = get_transient($cacheKey);

        if ($cachedKeys !== false && is_array($cachedKeys)) {
            return $cachedKeys;
        }

        // Buscar do endpoint do Firebase
        $response = wp_remote_get(self::FIREBASE_KEYS_URL, [
            'timeout' => 10,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                'Falha ao buscar chaves públicas do Firebase: ' . $response->get_error_message()
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            throw new \RuntimeException(
                'Firebase retornou status ' . $statusCode . ' ao buscar chaves públicas'
            );
        }

        $body = wp_remote_retrieve_body($response);
        $keys = json_decode($body, true);

        if (!is_array($keys) || empty($keys)) {
            throw new \RuntimeException('Resposta inválida do Firebase ao buscar chaves públicas');
        }

        // Cachear chaves (TTL: 1 hora)
        set_transient($cacheKey, $keys, self::KEYS_CACHE_TTL);

        return $keys;
    }

    /**
     * Decodificar JWT com validação de assinatura
     *
     * @param string $idToken JWT
     * @param array<string, string> $publicKeys Chaves públicas
     * @return object Payload decodificado
     * @throws \Exception Se decodificação falhar
     */
    private function decodeToken(string $idToken, array $publicKeys): object
    {
        // JWT::decode valida automaticamente:
        // - Assinatura (usando chaves públicas)
        // - Expiração (exp claim)
        // - Not before (nbf claim, se presente)

        // Converter chaves para formato Key[]
        $keys = [];
        foreach ($publicKeys as $kid => $pem) {
            $keys[$kid] = new Key($pem, 'RS256');
        }

        return JWT::decode($idToken, $keys);
    }

    /**
     * Validar claims obrigatórios do Firebase
     *
     * Claims esperados:
     * - aud (audience): deve ser o Project ID
     * - iss (issuer): deve ser https://securetoken.google.com/{projectId}
     * - sub (subject): Firebase UID
     * - auth_time: timestamp de autenticação
     *
     * @param object $claims Payload decodificado
     * @throws \InvalidArgumentException Se claims forem inválidos
     * @return void
     */
    private function validateClaims(object $claims): void
    {
        // Validar audience (deve ser o Project ID)
        if (!isset($claims->aud) || $claims->aud !== $this->projectId) {
            throw new \InvalidArgumentException(
                'Claim "aud" inválido. Esperado: ' . $this->projectId . ', recebido: ' . ($claims->aud ?? 'null')
            );
        }

        // Validar issuer
        $expectedIssuer = 'https://securetoken.google.com/' . $this->projectId;
        if (!isset($claims->iss) || $claims->iss !== $expectedIssuer) {
            throw new \InvalidArgumentException(
                'Claim "iss" inválido. Esperado: ' . $expectedIssuer
            );
        }

        // Validar subject (Firebase UID)
        if (!isset($claims->sub) || empty($claims->sub)) {
            throw new \InvalidArgumentException('Claim "sub" (Firebase UID) ausente ou vazio');
        }

        // Validar auth_time
        if (!isset($claims->auth_time) || !is_numeric($claims->auth_time)) {
            throw new \InvalidArgumentException('Claim "auth_time" ausente ou inválido');
        }

        // Validar phone_number (obrigatório para nosso caso de uso)
        if (!isset($claims->phone_number) || empty($claims->phone_number)) {
            throw new \InvalidArgumentException(
                'Claim "phone_number" ausente. Token deve ser de autenticação por telefone.'
            );
        }
    }

    /**
     * Limpar cache de chaves públicas (útil para debug)
     *
     * @return bool
     */
    public static function clearKeysCache(): bool
    {
        return delete_transient('limpvix_firebase_public_keys');
    }

    /**
     * Verificar se Firebase está configurado corretamente
     *
     * @return array{configured: bool, project_id: ?string, errors: array}
     */
    public static function checkConfiguration(): array
    {
        $errors = [];

        // Verificar constante do Project ID
        if (!defined('LIMPVIX_FIREBASE_PROJECT_ID')) {
            $errors[] = 'LIMPVIX_FIREBASE_PROJECT_ID não definido no wp-config.php';
        }

        $projectId = defined('LIMPVIX_FIREBASE_PROJECT_ID') ? LIMPVIX_FIREBASE_PROJECT_ID : null;

        // Verificar bibliotecas PHP
        if (!class_exists('Firebase\\JWT\\JWT')) {
            $errors[] = 'Biblioteca firebase/php-jwt não encontrada. Execute: composer require firebase/php-jwt';
        }

        if (!class_exists('Google\\Auth\\AccessToken')) {
            $errors[] = 'Biblioteca google/auth não encontrada. Execute: composer require google/auth';
        }

        return [
            'configured' => empty($errors),
            'project_id' => $projectId,
            'errors' => $errors
        ];
    }
}
