<?php
/**
 * RegisterProfessional Use Case
 *
 * Registra novo profissional autônomo na plataforma marketplace.
 *
 * RESPONSABILIDADES:
 * - Validar CPF único
 * - Criar usuário WordPress (role: limpvix_professional)
 * - Geocode endereço → lat/lng
 * - Criar Professional via factory
 * - Salvar no repository
 * - Disparar evento ProfessionalRegistered
 * - Enviar email de boas-vindas
 *
 * VALIDAÇÕES:
 * - CPF não pode estar cadastrado
 * - Email não pode estar cadastrado
 * - Endereço deve ser válido para geocoding
 * - Pelo menos 1 skill obrigatória
 * - Disponibilidade semanal válida
 *
 * @package LimpVix\Application\UseCases\Professional
 * @since 0.2.0
 */

namespace LimpVix\Application\UseCase\Professional;

use LimpVix\Domain\Professional\Professional;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;
use LimpVix\Domain\Professional\ValueObjects\ServiceRegion;
use LimpVix\Domain\Professional\ValueObjects\ProfessionalSkills;
use LimpVix\Domain\Professional\ValueObjects\WeeklyAvailability;

defined('ABSPATH') || exit;

class RegisterProfessional
{
    private ProfessionalRepositoryInterface $repository;

    public function __construct(ProfessionalRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Executa registro de profissional
     *
     * @param array $data Dados do profissional
     * @return array|WP_Error Professional criado ou erro
     */
    public function execute(array $data)
    {
        // 1. Validar dados obrigatórios
        $validation = $this->validateInput($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // 2. Validar CPF único
        $cpfClean = preg_replace('/\D/', '', $data['cpf']);
        $existingByCpf = $this->repository->findByCpf($cpfClean);
        if ($existingByCpf) {
            return new \WP_Error(
                'cpf_already_registered',
                'CPF já cadastrado na plataforma',
                ['status' => 400, 'cpf' => $cpfClean]
            );
        }

        // 3. Validar email único (WordPress)
        if (email_exists($data['email'])) {
            return new \WP_Error(
                'email_already_exists',
                'Email já cadastrado no sistema',
                ['status' => 400, 'email' => $data['email']]
            );
        }

        // 4. Geocode endereço → lat/lng
        $geocoded = $this->geocodeAddress($data);
        if (is_wp_error($geocoded)) {
            return $geocoded;
        }

        // 5-8. REFATORADO (SPRINT 7): Atomic WordPress User + Professional creation
        // Usa TransactionManager para garantir atomicidade:
        // - Se WordPress user criado MAS Professional save falhar → ROLLBACK automático
        // - Se qualquer step falhar → nenhum dado é persistido
        // - Elimina necessidade de wp_delete_user() manual

        $transactionManager = $GLOBALS['limpvix_transaction_manager'] ?? null;

        if (!$transactionManager) {
            return new \WP_Error(
                'transaction_manager_not_available',
                'TransactionManager não está disponível. Sistema em estado inconsistente.',
                ['status' => 500]
            );
        }

        // Transaction-protected execution
        $transactionManager->beginTransaction();

        try {
            // 5. Criar usuário WordPress (dentro da transação)
            $userId = $this->createWordPressUser($data);
            if (is_wp_error($userId)) {
                throw new \RuntimeException($userId->get_error_message());
            }

            // 6. Criar Value Objects
            $serviceRegion = ServiceRegion::fromArray([
                'center_latitude' => $geocoded['latitude'],
                'center_longitude' => $geocoded['longitude'],
                'radius_km' => $data['service_radius_km'] ?? 20, // Default 20km
            ]);

            $skills = ProfessionalSkills::fromJson(
                json_encode($data['skills']),
                isset($data['certifications']) ? json_encode($data['certifications']) : null,
                isset($data['physical_limitations']) ? json_encode($data['physical_limitations']) : null
            );

            $availability = isset($data['weekly_availability'])
                ? WeeklyAvailability::fromJson(json_encode($data['weekly_availability']))
                : WeeklyAvailability::defaultSchedule(); // Seg-Sex 08:00-18:00

            // 7. Criar Professional via factory
            $professional = Professional::create(
                $userId,
                $data['full_name'],
                $cpfClean,
                $data['phone'],
                $data['email'],
                $serviceRegion,
                $skills,
                $availability
            );

            // 8. Salvar no repository
            $this->repository->save($professional);

            // COMMIT: Tudo sucedeu
            $transactionManager->commit();

        } catch (\Exception $e) {
            // ROLLBACK: Qualquer falha reverte TUDO (WordPress user + Professional)
            $transactionManager->rollback();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[RegisterProfessional] Transaction rolled back: %s',
                    $e->getMessage()
                ));
            }

            return new \WP_Error(
                'professional_registration_failed',
                'Erro ao registrar profissional: ' . $e->getMessage(),
                ['status' => 500]
            );
        }

        // 9. Disparar evento ProfessionalRegistered
        do_action('limpvix_professional_registered', [
            'professional_id' => $professional->getId(),
            'user_id' => $userId,
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'cpf' => $cpfClean,
            'service_region' => $serviceRegion->toArray(),
            'skills' => $skills->getSkills(),
        ]);

        // 10. Enviar email de boas-vindas
        $this->sendWelcomeEmail($professional, $data);

        // 11. Log de sucesso
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix] ✅ Profissional registrado: %s (CPF: %s, User ID: %d)',
                $data['full_name'],
                $cpfClean,
                $userId
            ));
        }

        return [
            'success' => true,
            'professional_id' => $professional->getId(),
            'user_id' => $userId,
            'message' => 'Profissional registrado com sucesso',
            'professional' => $this->formatProfessionalResponse($professional),
        ];
    }

    /**
     * Valida dados de entrada
     *
     * @param array $data
     * @return true|WP_Error
     */
    private function validateInput(array $data)
    {
        $required = ['full_name', 'cpf', 'phone', 'email', 'address', 'skills'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new \WP_Error(
                    'missing_required_field',
                    "Campo obrigatório ausente: $field",
                    ['status' => 400, 'field' => $field]
                );
            }
        }

        // Validar formato CPF
        $cpfClean = preg_replace('/\D/', '', $data['cpf']);
        if (strlen($cpfClean) !== 11) {
            return new \WP_Error(
                'invalid_cpf_format',
                'CPF deve ter 11 dígitos',
                ['status' => 400]
            );
        }

        // Validar email
        if (!is_email($data['email'])) {
            return new \WP_Error(
                'invalid_email',
                'Email inválido',
                ['status' => 400]
            );
        }

        // Validar skills (pelo menos 1)
        if (empty($data['skills']) || !is_array($data['skills']) || count($data['skills']) === 0) {
            return new \WP_Error(
                'no_skills',
                'Profissional deve ter pelo menos 1 skill',
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Geocode endereço para lat/lng
     *
     * @param array $data
     * @return array|WP_Error ['latitude' => float, 'longitude' => float]
     */
    private function geocodeAddress(array $data)
    {
        // Montar endereço completo
        $address = $this->buildFullAddress($data['address']);

        // Tentar geocoding via Google Maps API (se configurado)
        $googleApiKey = get_option('limpvix_google_maps_api_key');

        if ($googleApiKey) {
            $geocoded = $this->geocodeViaGoogleMaps($address, $googleApiKey);
            if (!is_wp_error($geocoded)) {
                return $geocoded;
            }

            // Log erro mas continua com fallback
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[LimpVix] Google Maps geocoding falhou: ' . $geocoded->get_error_message());
            }
        }

        // Fallback: usar coordenadas do endereço se fornecidas
        if (!empty($data['address']['latitude']) && !empty($data['address']['longitude'])) {
            return [
                'latitude' => (float) $data['address']['latitude'],
                'longitude' => (float) $data['address']['longitude'],
            ];
        }

        // Fallback: coordenadas de Vitória-ES (centro)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix] ⚠️ Geocoding falhou, usando coordenadas padrão de Vitória-ES');
        }

        return [
            'latitude' => -20.3155,
            'longitude' => -40.3128,
        ];
    }

    /**
     * Geocode via Google Maps API
     *
     * @param string $address
     * @param string $apiKey
     * @return array|WP_Error
     */
    private function geocodeViaGoogleMaps(string $address, string $apiKey)
    {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $address,
            'key' => $apiKey,
            'region' => 'br',
        ]);

        $response = wp_remote_get($url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if ($result['status'] !== 'OK' || empty($result['results'][0])) {
            return new \WP_Error(
                'geocoding_failed',
                'Não foi possível geocodificar o endereço: ' . ($result['status'] ?? 'unknown'),
                ['status' => 400]
            );
        }

        $location = $result['results'][0]['geometry']['location'];

        return [
            'latitude' => $location['lat'],
            'longitude' => $location['lng'],
        ];
    }

    /**
     * Monta endereço completo para geocoding
     *
     * @param array|string $address
     * @return string
     */
    private function buildFullAddress($address): string
    {
        if (is_string($address)) {
            return $address;
        }

        $parts = [];
        if (!empty($address['street'])) $parts[] = $address['street'];
        if (!empty($address['number'])) $parts[] = $address['number'];
        if (!empty($address['neighborhood'])) $parts[] = $address['neighborhood'];
        if (!empty($address['city'])) $parts[] = $address['city'];
        if (!empty($address['state'])) $parts[] = $address['state'];
        if (!empty($address['zipcode'])) $parts[] = $address['zipcode'];

        return implode(', ', $parts);
    }

    /**
     * Cria usuário WordPress
     *
     * @param array $data
     * @return int|WP_Error User ID ou erro
     */
    private function createWordPressUser(array $data)
    {
        // Gerar username único baseado em CPF
        $cpfClean = preg_replace('/\D/', '', $data['cpf']);
        $username = 'prof_' . $cpfClean;

        // Gerar senha aleatória (será enviada por email)
        $password = wp_generate_password(12, true, true);

        $userId = wp_create_user(
            $username,
            $password,
            $data['email']
        );

        if (is_wp_error($userId)) {
            return $userId;
        }

        // Atualizar dados do usuário
        wp_update_user([
            'ID' => $userId,
            'display_name' => $data['full_name'],
            'first_name' => $this->extractFirstName($data['full_name']),
            'last_name' => $this->extractLastName($data['full_name']),
            'role' => 'limpvix_professional',
        ]);

        // Salvar meta dados
        update_user_meta($userId, 'limpvix_cpf', $cpfClean);
        update_user_meta($userId, 'limpvix_phone', $data['phone']);
        update_user_meta($userId, 'limpvix_professional_initial_password', $password);

        return $userId;
    }

    /**
     * Extrai primeiro nome
     *
     * @param string $fullName
     * @return string
     */
    private function extractFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }

    /**
     * Extrai sobrenome
     *
     * @param string $fullName
     * @return string
     */
    private function extractLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        array_shift($parts);
        return implode(' ', $parts);
    }

    /**
     * Envia email de boas-vindas
     *
     * @param Professional $professional
     * @param array $data
     * @return void
     */
    private function sendWelcomeEmail(Professional $professional, array $data): void
    {
        $userId = $professional->getUserId();
        $password = get_user_meta($userId, 'limpvix_professional_initial_password', true);

        $subject = '🎉 Bem-vindo à LimpVix - Sua conta foi criada!';

        $message = "Olá {$data['full_name']},\n\n";
        $message .= "Seja bem-vindo à plataforma LimpVix!\n\n";
        $message .= "Sua conta de profissional foi criada com sucesso.\n\n";
        $message .= "📋 Dados de acesso:\n";
        $message .= "Usuário: prof_" . preg_replace('/\D/', '', $data['cpf']) . "\n";
        $message .= "Senha temporária: $password\n";
        $message .= "Link de acesso: " . wp_login_url() . "\n\n";
        $message .= "⚠️ IMPORTANTE: Altere sua senha no primeiro acesso.\n\n";
        $message .= "📍 Região de atuação:\n";
        $message .= "Raio: " . ($data['service_radius_km'] ?? 20) . " km\n\n";
        $message .= "🔧 Skills cadastradas:\n";
        foreach ($data['skills'] as $skill) {
            $message .= "• $skill\n";
        }
        $message .= "\n";
        $message .= "📱 Próximos passos:\n";
        $message .= "1. Faça login na plataforma\n";
        $message .= "2. Complete seu perfil\n";
        $message .= "3. Aguarde a verificação dos seus documentos\n";
        $message .= "4. Comece a receber ofertas de serviços!\n\n";
        $message .= "Qualquer dúvida, entre em contato com nosso suporte.\n\n";
        $message .= "Equipe LimpVix";

        wp_mail($data['email'], $subject, $message);

        // Também notificar admin
        $adminEmail = get_option('admin_email');
        $adminSubject = "🆕 Novo profissional cadastrado: {$data['full_name']}";
        $adminMessage = "Novo profissional registrado na plataforma:\n\n";
        $adminMessage .= "Nome: {$data['full_name']}\n";
        $adminMessage .= "CPF: " . preg_replace('/\D/', '', $data['cpf']) . "\n";
        $adminMessage .= "Email: {$data['email']}\n";
        $adminMessage .= "Telefone: {$data['phone']}\n";
        $adminMessage .= "Skills: " . implode(', ', $data['skills']) . "\n";
        $adminMessage .= "\nAcesse o painel administrativo para verificar e aprovar o profissional.";

        wp_mail($adminEmail, $adminSubject, $adminMessage);

        // Disparar evento de notificação
        do_action('limpvix_admin_notification', [
            'type' => 'professional_registered',
            'title' => 'Novo Profissional Cadastrado',
            'message' => "{$data['full_name']} se cadastrou e aguarda aprovação",
            'professional_id' => $professional->getId(),
            'user_id' => $userId,
        ]);
    }

    /**
     * Formata resposta do profissional
     *
     * @param Professional $professional
     * @return array
     */
    private function formatProfessionalResponse(Professional $professional): array
    {
        return [
            'id' => $professional->getId(),
            'user_id' => $professional->getUserId(),
            'full_name' => $professional->getFullName(),
            'email' => $professional->getEmail(),
            'phone' => $professional->getPhone(),
            'cpf' => $professional->getCpf(),
            'score' => $professional->getScore(),
            'is_active' => $professional->isActive(),
            'is_verified' => $professional->isVerified(),
            'service_region' => $professional->getServiceRegion()->toArray(),
            'skills' => $professional->getSkills()->getSkills(),
        ];
    }
}
