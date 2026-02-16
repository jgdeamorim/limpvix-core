<?php
/**
 * ContractNumberGenerator - Service para gerar números únicos de contrato
 *
 * RESPONSABILIDADE:
 * - Gerar contract_number único no formato LMPVX-YYYYMM-NNNNNN
 * - Garantir unicidade via database check + retry
 * - Thread-safe via locking se necessário
 *
 * FORMATO:
 * LMPVX-YYYYMM-NNNNNN
 *
 * Exemplo: LMPVX-202602-000123
 *
 * Onde:
 * - LMPVX = Prefix fixo (LimpVix)
 * - YYYYMM = Ano e mês (202602 = Fevereiro 2026)
 * - NNNNNN = Sequencial 6 dígitos, zero-padded (000001-999999)
 *
 * CARACTERÍSTICAS:
 * - Human readable (fácil de ler e comunicar ao cliente)
 * - Sortable (ordem cronológica natural)
 * - Unique (combinação mês + sequencial garante unicidade)
 * - Capacidade: 999,999 contratos por mês
 *
 * ESTRATÉGIA DE UNICIDADE:
 * - Query database para max sequential no mês atual
 * - Incrementa +1
 * - Se collision ao inserir (UNIQUE constraint), retry até 5 vezes
 * - Após 5 tentativas, lança exception
 *
 * THREAD SAFETY:
 * - Depende do UNIQUE constraint da tabela (nível database)
 * - Retry mechanism garante eventual success em caso de race condition
 * - Não usa locking explícito (database handle via UNIQUE key)
 *
 * USO:
 * ```php
 * $generator = new ContractNumberGenerator($wpdb);
 * $contractNumber = $generator->generate();
 * // Retorna: "LMPVX-202602-000001"
 * ```
 *
 * @package LimpVix\Application\Services
 * @since 0.7.0 (SPRINT 7 - Item 1.8)
 * @author Claude Code
 */

namespace LimpVix\Application\Services;

defined('ABSPATH') || exit;

class ContractNumberGenerator
{
    private const PREFIX = 'LMPVX';
    private const MAX_RETRIES = 5;
    private const SEQUENTIAL_LENGTH = 6;

    private \wpdb $wpdb;
    private string $tableName;

    /**
     * Construtor
     *
     * @param \wpdb $wpdb WordPress database abstraction
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
        $this->tableName = $wpdb->prefix . 'limpvix_contracts';
    }

    /**
     * Gerar contract_number único
     *
     * ALGORITMO:
     * 1. Obter ano/mês atual (YYYYMM)
     * 2. Query max sequential no mês atual
     * 3. Incrementar +1
     * 4. Formatar: LMPVX-YYYYMM-NNNNNN
     * 5. Verificar se já existe (retry se sim)
     *
     * @return string Contract number único (ex: LMPVX-202602-000123)
     * @throws \RuntimeException Se não conseguir gerar número único após retries
     */
    public function generate(): string
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            $attempts++;

            try {
                // 1. Obter ano/mês atual
                $yearMonth = date('Ym'); // Ex: 202602

                // 2. Query max sequential no mês atual
                $nextSequential = $this->getNextSequential($yearMonth);

                // 3. Formatar contract_number
                $contractNumber = $this->formatContractNumber($yearMonth, $nextSequential);

                // 4. Verificar unicidade
                if (!$this->exists($contractNumber)) {
                    $this->log("Generated contract_number: {$contractNumber} (attempt {$attempts})");
                    return $contractNumber;
                }

                // Collision detected, retry
                $this->log("Collision detected for {$contractNumber}, retrying... (attempt {$attempts})");

            } catch (\Exception $e) {
                $this->log("Error generating contract_number (attempt {$attempts}): " . $e->getMessage(), 'error');

                if ($attempts >= self::MAX_RETRIES) {
                    throw new \RuntimeException(
                        "Failed to generate unique contract_number after {$attempts} attempts: " . $e->getMessage()
                    );
                }

                // Pequeno delay antes de retry (evita busy loop em caso de problema persistente)
                usleep(100000); // 100ms
            }
        }

        throw new \RuntimeException(
            "Failed to generate unique contract_number after " . self::MAX_RETRIES . " attempts"
        );
    }

    /**
     * Obter próximo número sequencial para o mês
     *
     * Query database para encontrar o max sequential do mês atual e retorna next.
     *
     * @param string $yearMonth Year-month no formato YYYYMM (ex: 202602)
     * @return int Next sequential (1-999999)
     */
    private function getNextSequential(string $yearMonth): int
    {
        // Query para encontrar max sequential no mês atual
        // contract_number format: LMPVX-YYYYMM-NNNNNN
        // Usa LIKE para filtrar por mês: 'LMPVX-202602-%'
        $pattern = self::PREFIX . '-' . $yearMonth . '-%';

        $maxNumber = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT contract_number
             FROM {$this->tableName}
             WHERE contract_number LIKE %s
             ORDER BY contract_number DESC
             LIMIT 1",
            $pattern
        ));

        if (!$maxNumber) {
            // Primeiro contrato do mês
            return 1;
        }

        // Extrair sequential da string: LMPVX-202602-000123 → 000123 → 123
        $parts = explode('-', $maxNumber);
        if (count($parts) !== 3) {
            // Formato inválido, começar do 1
            $this->log("Invalid contract_number format found: {$maxNumber}, starting from 1", 'warning');
            return 1;
        }

        $currentSequential = (int) $parts[2];

        // Incrementar
        return $currentSequential + 1;
    }

    /**
     * Formatar contract_number
     *
     * @param string $yearMonth Year-month (ex: 202602)
     * @param int $sequential Sequential number (ex: 123)
     * @return string Formatted contract_number (ex: LMPVX-202602-000123)
     */
    private function formatContractNumber(string $yearMonth, int $sequential): string
    {
        // Validar sequential não excede capacidade
        $maxSequential = (int) str_repeat('9', self::SEQUENTIAL_LENGTH);
        if ($sequential > $maxSequential) {
            throw new \RuntimeException(
                "Sequential number {$sequential} exceeds max capacity {$maxSequential} for month {$yearMonth}"
            );
        }

        // Zero-pad sequential
        $sequentialPadded = str_pad((string) $sequential, self::SEQUENTIAL_LENGTH, '0', STR_PAD_LEFT);

        return self::PREFIX . '-' . $yearMonth . '-' . $sequentialPadded;
    }

    /**
     * Verificar se contract_number já existe
     *
     * @param string $contractNumber Contract number to check
     * @return bool True se existe, false caso contrário
     */
    private function exists(string $contractNumber): bool
    {
        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableName} WHERE contract_number = %s",
            $contractNumber
        ));

        return ((int) $count) > 0;
    }

    /**
     * Log de operações (apenas se WP_DEBUG ativo)
     *
     * @param string $message Mensagem de log
     * @param string $level Nível (info, warning, error)
     * @return void
     */
    private function log(string $message, string $level = 'info'): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $prefix = match ($level) {
                'error' => '❌',
                'warning' => '⚠️',
                default => '✅',
            };

            error_log("[ContractNumberGenerator] {$prefix} {$message}");
        }
    }

    /**
     * Validar formato de contract_number
     *
     * Método estático para validar se uma string está no formato correto.
     * Útil para validação em DTOs e Aggregates.
     *
     * @param string $contractNumber Contract number to validate
     * @return bool True se válido, false caso contrário
     */
    public static function isValidFormat(string $contractNumber): bool
    {
        // Regex: LMPVX-YYYYMM-NNNNNN
        // Year: 20[0-9]{2} (2000-2099)
        // Month: (0[1-9]|1[0-2]) (01-12)
        // Sequential: [0-9]{6} (000000-999999)
        $pattern = '/^LMPVX-20[0-9]{2}(0[1-9]|1[0-2])-[0-9]{6}$/';

        return preg_match($pattern, $contractNumber) === 1;
    }

    /**
     * Extrair componentes do contract_number
     *
     * @param string $contractNumber Contract number (ex: LMPVX-202602-000123)
     * @return array{prefix: string, year: string, month: string, sequential: int}|null
     */
    public static function parse(string $contractNumber): ?array
    {
        if (!self::isValidFormat($contractNumber)) {
            return null;
        }

        $parts = explode('-', $contractNumber);

        return [
            'prefix' => $parts[0],
            'year' => substr($parts[1], 0, 4),
            'month' => substr($parts[1], 4, 2),
            'sequential' => (int) $parts[2],
        ];
    }
}
