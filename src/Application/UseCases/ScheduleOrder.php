<?php
/**
 * ScheduleOrder - Use Case para Agendar Ordem de Serviço
 *
 * RESPONSABILIDADE:
 * - Orquestrar criação de OS no LimpVix
 * - Validar regras de negócio
 * - Criar agendamento via LimpVix Scheduling
 * - Persistir OS
 * - Disparar eventos
 *
 * PRINCÍPIOS:
 * - Um use case = uma ação de negócio
 * - Stateless (não guarda estado entre execuções)
 * - Transacional (tudo ou nada)
 * - Validação antes de persistência
 *
 * FLUXO:
 * 1. Validar dados de entrada
 * 2. Aplicar políticas de negócio
 * 3. Criar entidade Order
 * 4. Criar agendamento LimpVix
 * 5. Vincular appointment à OS
 * 6. Persistir OS
 * 7. Disparar evento OrderScheduled
 *
 * @package LimpVix\Application\UseCases
 */

namespace LimpVix\Application\UseCases;

use LimpVix\Domain\Order\Order;
use LimpVix\Domain\Order\OrderStatus;
use LimpVix\Domain\Order\OrderPolicy;
use LimpVix\Domain\Order\OrderRepositoryInterface;

defined('ABSPATH') || exit;

class ScheduleOrder
{
    private ?OrderRepositoryInterface $orderRepository;

    public function __construct(?OrderRepositoryInterface $orderRepository = null)
    {
        $this->orderRepository = $orderRepository;
    }

    /**
     * Executa o use case
     *
     * @param array $data Dados do agendamento
     * @return Order Ordem de Serviço criada
     * @throws \Exception Se validação falhar
     */
    public function execute(array $data): Order
    {
        // 1. VALIDAR dados de entrada
        $this->validateInput($data);

        // 2. APLICAR políticas de negócio
        $this->applyPolicies($data);

        // 3. CRIAR entidade Order (ainda em memória)
        $order = $this->createOrder($data);

        // 4. PERSISTIR OS via repository
        if ($this->orderRepository) {
            $this->orderRepository->save($order);
        }

        // 5. DISPARAR evento de domínio via WordPress action
        do_action('limpvix_order_scheduled', [
            'order_id'    => $order->getId(),
            'order_uuid'  => method_exists($order, 'getUuid') ? $order->getUuid() : '',
            'customer_id' => $order->getCustomerId(),
            'service_id'  => $order->getServiceId(),
            'scheduled_at' => $order->getScheduledAt()->format('Y-m-d H:i:s'),
        ]);

        // 6. LOG de auditoria
        $this->logAudit('ORDER_SCHEDULED', [
            'order_id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
            'service_id' => $order->getServiceId(),
            'scheduled_at' => $order->getScheduledAt()->format('Y-m-d H:i:s')
        ]);

        return $order;
    }

    /**
     * Valida dados de entrada
     *
     * @param array $data
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateInput(array $data): void
    {
        $required = ['customer_id', 'service_id', 'scheduled_at', 'price'];

        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException("Campo obrigatório ausente: {$field}");
            }
        }

        // Validar tipos
        if (!is_numeric($data['customer_id'])) {
            throw new \InvalidArgumentException('customer_id deve ser numérico');
        }

        if (!is_numeric($data['service_id'])) {
            throw new \InvalidArgumentException('service_id deve ser numérico');
        }

        if (!is_numeric($data['price']) || $data['price'] < 0) {
            throw new \InvalidArgumentException('price deve ser numérico e positivo');
        }

        // Validar data
        if (!($data['scheduled_at'] instanceof \DateTime)) {
            throw new \InvalidArgumentException('scheduled_at deve ser DateTime');
        }
    }

    /**
     * Aplica políticas de negócio
     *
     * @param array $data
     * @return void
     * @throws \DomainException
     */
    private function applyPolicies(array $data): void
    {
        $scheduledAt = $data['scheduled_at'];

        // Validar SLA de antecedência
        if (!OrderPolicy::canSchedule($scheduledAt)) {
            throw new \DomainException(
                OrderPolicy::getSchedulingError($scheduledAt)
            );
        }

        // TODO: Outras validações
        // - Cliente não está bloqueado
        // - Serviço está disponível
        // - Horário está disponível
        // - Limite de agendamentos simultâneos
    }

    /**
     * Cria entidade Order
     *
     * @param array $data
     * @return Order
     */
    private function createOrder(array $data): Order
    {
        // Gerar UUID para OS
        $orderId = $this->generateUuid();

        // Criar ordem
        $order = Order::create(
            $orderId,
            (int) $data['customer_id'],
            (int) $data['service_id'],
            (float) $data['price'],
            $data['scheduled_at']
        );

        // Validar e transicionar para VALIDATED
        $order->validate();

        // Adicionar profissional se especificado
        if (isset($data['professional_id'])) {
            $order->setProfessionalId((int) $data['professional_id']);
        }

        // Adicionar metadados
        if (isset($data['notes'])) {
            $order->setMetadata('notes', $data['notes']);
        }

        return $order;
    }

    /**
     * Gera UUID v4
     *
     * @return string
     */
    private function generateUuid(): string
    {
        // Usar wp_generate_uuid4() se disponível (WP 5.4+)
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        // Fallback: implementação simples
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Log de auditoria (placeholder)
     *
     * @param string $event
     * @param array $data
     * @return void
     */
    private function logAudit(string $event, array $data): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix UseCase] %s: %s',
                $event,
                json_encode($data, JSON_UNESCAPED_UNICODE)
            ));
        }

        // TODO: Persistir em tabela de auditoria
    }
}
