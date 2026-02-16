<?php
/**
 * SelectPackage - Use Case
 *
 * RESPONSABILIDADE:
 * - Permitir seleção de pacote de serviço (basic, standard, premium)
 * - Validar se Briefing está em estado válido para seleção
 * - Registrar seleção no ledger (event sourcing)
 * - Recalcular métricas com multiplier do pacote
 *
 * PRINCÍPIOS:
 * - Use Case pattern (Application Layer)
 * - Single Responsibility
 * - Fail-safe (retorna Result)
 *
 * REGRAS DE NEGÓCIO:
 * - Só pode selecionar pacote se Briefing NÃO estiver locked
 * - Package pode ser alterado até lock
 * - Recalcular profissionais necessários após seleção
 *
 * @package LimpVix\Application\UseCases\Briefing
 * @since 0.4.0
 */

namespace LimpVix\Application\UseCases\Briefing;

use LimpVix\Domain\Briefing\Briefing;
use LimpVix\Domain\Briefing\Package;
use LimpVix\Domain\Briefing\PackageType;
use LimpVix\Infrastructure\Persistence\WpBriefingRepository;

defined('ABSPATH') || exit;

class SelectPackage
{
    /**
     * @var WpBriefingRepository
     */
    private $briefingRepository;

    /**
     * Construtor
     *
     * @param WpBriefingRepository $briefingRepository
     */
    public function __construct(WpBriefingRepository $briefingRepository)
    {
        $this->briefingRepository = $briefingRepository;
    }

    /**
     * Executar seleção de pacote
     *
     * @param string $briefingUuid UUID do Briefing
     * @param string $packageType Tipo de pacote (basic, standard, premium)
     * @return array ['success' => bool, 'message' => string, 'data' => array|null]
     */
    public function execute(string $briefingUuid, string $packageType): array
    {
        try {
            // 1. Buscar Briefing
            $briefing = $this->briefingRepository->findByUuid($briefingUuid);

            if (!$briefing) {
                return $this->fail("Briefing não encontrado: {$briefingUuid}");
            }

            // 2. Verificar se está locked
            if ($briefing->isLocked()) {
                return $this->fail("Briefing locked não pode alterar pacote");
            }

            // 3. Criar Package baseado no tipo
            $package = $this->createPackageFromType($packageType);

            if (!$package) {
                return $this->fail("Tipo de pacote inválido: {$packageType}");
            }

            // 4. Selecionar pacote no Briefing
            $briefing->selectPackage($package);

            // 5. Recalcular métricas (opcional - pode ser feito depois)
            // Se metrics já existe, manter (cálculo detalhado está em BriefingMetricsCalculator)

            // 6. Persistir Briefing
            $this->briefingRepository->save($briefing);

            // 7. Registrar evento no ledger
            $this->briefingRepository->appendEvent([
                'briefing_uuid' => $briefingUuid,
                'event_type' => 'package_selected',
                'from_status' => null,
                'to_status' => $briefing->getStatus()->getValue(),
                'actor' => 'customer',
                'event_data' => json_encode([
                    'package_type' => $packageType,
                    'percentage_increase' => $package->getPercentageIncrease(),
                    'min_professionals' => $package->getMinProfessionals(),
                    'max_professionals' => $package->getMaxProfessionals(),
                    'timestamp' => current_time('mysql')
                ])
            ]);

            // 8. Retornar sucesso
            return $this->success([
                'briefing_uuid' => $briefingUuid,
                'package' => $package->toArray(),
                'message' => "Pacote '{$package->getType()->getDisplayName()}' selecionado com sucesso"
            ]);

        } catch (\Exception $e) {
            error_log("SelectPackage::execute error: " . $e->getMessage());
            return $this->fail("Erro ao selecionar pacote: " . $e->getMessage());
        }
    }

    /**
     * Criar Package baseado no tipo
     *
     * @param string $packageType
     * @return Package|null
     */
    private function createPackageFromType(string $packageType): ?Package
    {
        try {
            // Verificar se tipo é válido
            if (!in_array($packageType, PackageType::all(), true)) {
                return null;
            }

            // Buscar configuração do banco (se existir)
            $packageConfig = $this->getPackageConfigFromDatabase($packageType);

            if ($packageConfig) {
                return Package::fromConfig($packageConfig);
            }

            // Fallback: usar factory methods padrão
            switch ($packageType) {
                case 'basic':
                    return Package::basic();
                case 'standard':
                    return Package::standard();
                case 'premium':
                    return Package::premium();
                default:
                    return null;
            }

        } catch (\Exception $e) {
            error_log("SelectPackage::createPackageFromType error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Buscar configuração de pacote do banco de dados
     *
     * @param string $packageType
     * @return array|null
     */
    private function getPackageConfigFromDatabase(string $packageType): ?array
    {
        global $wpdb;

        $config = $wpdb->get_row($wpdb->prepare(
            "SELECT
                package_type AS type,
                percentage_increase,
                min_professionals,
                max_professionals,
                required_skills,
                description
            FROM {$wpdb->prefix}limpvix_package_configs
            WHERE package_type = %s
            AND is_active = 1",
            $packageType
        ), ARRAY_A);

        if (!$config) {
            return null;
        }

        // Decodificar JSON skills
        $config['required_skills'] = json_decode($config['required_skills'], true) ?: [];

        return $config;
    }

    /**
     * Retornar sucesso
     *
     * @param array $data
     * @return array
     */
    private function success(array $data): array
    {
        return [
            'success' => true,
            'message' => 'OK',
            'data' => $data
        ];
    }

    /**
     * Retornar falha
     *
     * @param string $message
     * @return array
     */
    private function fail(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null
        ];
    }
}
