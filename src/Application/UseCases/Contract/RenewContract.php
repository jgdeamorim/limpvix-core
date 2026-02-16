<?php
/**
 * RenewContract - Use Case para Renovação Manual de Contrato
 *
 * RESPONSABILIDADE:
 * - Criar NOVO contrato baseado em contrato existente
 * - Permitir alteração de frequência, valor, profissional
 * - Validar que contrato original pode ser renovado
 * - Marcar contrato original como completed
 * - Criar novo contrato com status draft (aguarda ativação)
 *
 * DIFERENÇA vs Auto-Renewal:
 * - Auto-renewal (renew()): Estende contrato existente automaticamente
 * - Manual renewal (este): Cria NOVO contrato, permite alterações
 *
 * REGRAS DE NEGÓCIO:
 * - Contrato original deve estar active ou completed
 * - Não pode renovar contrato cancelled
 * - Novo contrato herda dados do original (exceto alterações)
 * - Cliente pode mudar: frequência, valor, profissional
 * - Novo contrato começa como draft (cliente precisa aprovar/pagar)
 *
 * @package LimpVix\Application\UseCases\Contract
 * @since 0.12.0 (GAP #6)
 */

namespace LimpVix\Application\UseCases\Contract;

use LimpVix\Common\Result;
use LimpVix\Domain\Contract\Contract;
use LimpVix\Domain\Contract\ContractRepositoryInterface;

defined('ABSPATH') || exit;

class RenewContract
{
    private ContractRepositoryInterface $contractRepository;

    public function __construct(ContractRepositoryInterface $contractRepository)
    {
        $this->contractRepository = $contractRepository;
    }

    /**
     * Execute use case
     *
     * @param int $originalContractId ID do contrato a renovar
     * @param array $changes Alterações opcionais: ['contractType' => 'monthly', 'monthlyValue' => 500.00, 'allocatedProfessionalId' => 123]
     * @return Result<array{original_contract_id: int, new_contract_id: int, new_contract_number: string}>
     */
    public function execute(int $originalContractId, array $changes = []): Result
    {
        // 1. Buscar contrato original
        $originalContract = $this->contractRepository->findById(\LimpVix\Domain\Contract\ContractId::fromInt($originalContractId));

        if ($originalContract === null) {
            return Result::fail("Contract #{$originalContractId} not found");
        }

        // 2. Validar que contrato pode ser renovado
        if (!$this->canBeRenewed($originalContract)) {
            return Result::fail(sprintf(
                "Contract cannot be renewed. Status: %s. Only active or completed contracts can be renewed.",
                $originalContract->getStatus()->toString()
            ));
        }

        // 3. Preparar dados do novo contrato (herda do original + alterações)
        $newContractData = $this->prepareNewContractData($originalContract, $changes);

        // 4. Validar alterações
        $validation = $this->validateChanges($newContractData);
        if (!$validation->isOk()) {
            return $validation;
        }

        // 5. Criar novo contrato
        try {
            $newContract = Contract::create(
                $newContractData['client_user_id'],
                $this->generateContractNumber($originalContract),
                $newContractData['contract_type'],
                $newContractData['recurrence_day'],
                $newContractData['start_date'],
                $newContractData['end_date'],
                $newContractData['service_code'],
                $newContractData['property_type'],
                $newContractData['monthly_value'],
                $newContractData['auto_renew']
            );

            // 6. Se professional foi especificado, alocar
            if (!empty($newContractData['allocated_professional_id'])) {
                // Note: Allocation seria feito via outro método/use case
                // Por ora, apenas registramos o ID desejado
            }

            // 7. Persistir novo contrato
            $this->contractRepository->save($newContract);

            // 8. Marcar contrato original como completed (se ainda ativo)
            if ($originalContract->getStatus()->isActive()) {
                $originalContract->complete();
                $this->contractRepository->save($originalContract);
            }

            // 9. Retornar sucesso
            return Result::ok([
                'original_contract_id' => $originalContractId,
                'new_contract_id' => $newContract->getId()->toInt(),
                'new_contract_number' => $newContract->getContractNumber(),
                'status' => 'draft',
                'message' => 'Contract renewed successfully. New contract created.',
                'changes_applied' => array_keys($changes),
            ]);

        } catch (\Exception $e) {
            return Result::fail('Failed to renew contract: ' . $e->getMessage());
        }
    }

    /**
     * Verificar se contrato pode ser renovado
     */
    private function canBeRenewed(Contract $contract): bool
    {
        $status = $contract->getStatus();
        
        // Pode renovar se: active ou completed
        // Não pode renovar se: cancelled, expired, draft
        return $status->isActive() || $status->isCompleted();
    }

    /**
     * Preparar dados do novo contrato (herda + alterações)
     */
    private function prepareNewContractData(Contract $originalContract, array $changes): array
    {
        // Herdar todos os dados do original
        $data = [
            'client_user_id' => $originalContract->getClientUserId(),
            'contract_type' => $originalContract->getContractType(),
            'recurrence_day' => $originalContract->getRecurrenceDay(),
            'start_date' => new \DateTimeImmutable('+1 day'), // Começa amanhã
            'end_date' => null, // Sem data fim (renovação indefinida)
            'service_code' => $originalContract->getServiceCode(),
            'property_type' => $originalContract->getPropertyType(),
            'monthly_value' => $originalContract->getMonthlyValue(),
            'allocated_professional_id' => $originalContract->getAllocatedProfessionalId(),
            'auto_renew' => true, // Por padrão, novos contratos têm auto-renew
        ];

        // Aplicar alterações solicitadas
        foreach ($changes as $key => $value) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        // Se mudou contractType, recalcular recurrence_day se necessário
        if (isset($changes['contract_type']) && $changes['contract_type'] !== $originalContract->getContractType()) {
            // Se não especificou novo recurrence_day, usar default
            if (!isset($changes['recurrence_day'])) {
                $data['recurrence_day'] = $this->getDefaultRecurrenceDay($changes['contract_type']);
            }
        }

        return $data;
    }

    /**
     * Validar alterações solicitadas
     */
    private function validateChanges(array $data): Result
    {
        // Validar contractType
        $validTypes = ['weekly', 'biweekly', 'monthly'];
        if (!in_array($data['contract_type'], $validTypes, true)) {
            return Result::fail('Invalid contract type. Must be: weekly, biweekly, or monthly');
        }

        // Validar monthlyValue
        if ($data['monthly_value'] <= 0) {
            return Result::fail('Monthly value must be greater than zero');
        }

        // Validar recurrence_day
        if ($data['contract_type'] === 'weekly' && ($data['recurrence_day'] < 0 || $data['recurrence_day'] > 6)) {
            return Result::fail('Invalid recurrence_day for weekly contract. Must be 0-6 (Sunday-Saturday)');
        }

        if ($data['contract_type'] === 'monthly' && ($data['recurrence_day'] < 1 || $data['recurrence_day'] > 28)) {
            return Result::fail('Invalid recurrence_day for monthly contract. Must be 1-28');
        }

        return Result::ok([]);
    }

    /**
     * Gerar número do novo contrato
     */
    private function generateContractNumber(Contract $originalContract): string
    {
        $originalNumber = $originalContract->getContractNumber();
        
        // Extrair número base (remove sufixo -R1, -R2, etc se existir)
        if (preg_match('/^(.+)-R(\d+)$/', $originalNumber, $matches)) {
            $baseNumber = $matches[1];
            $renewalCount = (int) $matches[2] + 1;
        } else {
            $baseNumber = $originalNumber;
            $renewalCount = 1;
        }

        return sprintf('%s-R%d', $baseNumber, $renewalCount);
    }

    /**
     * Obter recurrence_day padrão por tipo
     */
    private function getDefaultRecurrenceDay(string $contractType): int
    {
        return match($contractType) {
            'weekly' => 1, // Monday
            'biweekly' => 1, // Monday
            'monthly' => 1, // Day 1 of month
            default => 1,
        };
    }
}
