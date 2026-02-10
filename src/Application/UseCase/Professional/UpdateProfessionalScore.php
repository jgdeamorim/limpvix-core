<?php
/**
 * UpdateProfessionalScore Use Case
 *
 * Atualiza score de profissional baseado em evento/feedback/performance.
 *
 * RESPONSABILIDADES:
 * - Buscar Professional pelo ID
 * - Calcular novo score baseado em regras de negócio
 * - Atualizar score no Aggregate Root
 * - Persistir com auditoria (via Repository)
 * - Disparar evento ProfessionalScoreUpdated
 *
 * MOTIVOS DE MUDANÇA DE SCORE:
 * - feedback: Avaliação do cliente (peso alto)
 * - late_checkin: Atraso no check-in (-0.1 por ocorrência)
 * - no_show: Não comparecimento (-0.5 por ocorrência)
 * - good_execution: Boa execução (+0.05 por ocorrência)
 * - epi_violation: Violação de EPI (-0.3 por ocorrência)
 * - completed_service: Serviço completado com sucesso (+0.02)
 * - contract_cancelled: Contrato cancelado pelo profissional (-0.2)
 * - client_complaint: Reclamação do cliente (-0.3)
 *
 * REGRAS DE CÁLCULO:
 * - Score mínimo: 0.00
 * - Score máximo: 5.00
 * - Feedback do cliente: média móvel ponderada (peso 70%)
 * - Performance histórica: peso 30%
 *
 * @package LimpVix\Application\UseCases\Professional
 * @since 0.2.0
 */

namespace LimpVix\Application\UseCase\Professional;

use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;

defined('ABSPATH') || exit;

class UpdateProfessionalScore
{
    private ProfessionalRepositoryInterface $repository;

    /**
     * Configuração de impacto no score por motivo
     */
    private const SCORE_IMPACTS = [
        'feedback' => 0.0, // Calculado dinamicamente
        'late_checkin' => -0.1,
        'no_show' => -0.5,
        'good_execution' => 0.05,
        'epi_violation' => -0.3,
        'completed_service' => 0.02,
        'contract_cancelled' => -0.2,
        'client_complaint' => -0.3,
        'excellent_feedback' => 0.1, // Rating >= 4.5
        'poor_feedback' => -0.2, // Rating < 3.0
    ];

    public function __construct(ProfessionalRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Executa atualização de score
     *
     * @param array $data Dados para atualização
     * @return array|WP_Error Resultado ou erro
     */
    public function execute(array $data)
    {
        // 1. Validar entrada
        $validation = $this->validateInput($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $professionalId = (int) $data['professional_id'];
        $reason = $data['reason'];
        $details = $data['details'] ?? [];

        // 2. Buscar Professional
        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            return new \WP_Error(
                'professional_not_found',
                'Profissional não encontrado',
                ['status' => 404, 'professional_id' => $professionalId]
            );
        }

        $oldScore = $professional->getScore();

        // 3. Calcular novo score
        $newScore = $this->calculateNewScore($professional, $reason, $details);

        // 4. Validar novo score (0.00-5.00)
        $newScore = max(0.00, min(5.00, $newScore));
        $newScore = round($newScore, 2);

        // 5. Atualizar score no AR (se mudou)
        if (abs($newScore - $oldScore) < 0.01) {
            // Score não mudou significativamente, não precisa atualizar
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[LimpVix] Score do profissional #%d não mudou (%.2f → %.2f), ignorando atualização',
                    $professionalId,
                    $oldScore,
                    $newScore
                ));
            }

            return [
                'success' => true,
                'score_changed' => false,
                'professional_id' => $professionalId,
                'old_score' => $oldScore,
                'new_score' => $newScore,
                'reason' => $reason,
                'message' => 'Score não mudou significativamente',
            ];
        }

        // 6. Persistir com auditoria (Repository já faz auditoria)
        try {
            $this->repository->updateScore($professionalId, $newScore, $reason, $details);
        } catch (\Exception $e) {
            return new \WP_Error(
                'score_update_failed',
                'Erro ao atualizar score: ' . $e->getMessage(),
                ['status' => 500]
            );
        }

        // 7. Disparar evento ProfessionalScoreUpdated
        do_action('limpvix_professional_score_updated', [
            'professional_id' => $professionalId,
            'old_score' => $oldScore,
            'new_score' => $newScore,
            'score_change' => $newScore - $oldScore,
            'reason' => $reason,
            'details' => $details,
            'timestamp' => current_time('mysql'),
        ]);

        // 8. Notificar profissional se score caiu significativamente
        if ($newScore < $oldScore && ($oldScore - $newScore) >= 0.3) {
            $this->notifyProfessionalScoreDropped($professional, $oldScore, $newScore, $reason);
        }

        // 9. Notificar admin se score ficou crítico (< 3.0)
        if ($newScore < 3.0 && $oldScore >= 3.0) {
            $this->notifyAdminLowScore($professional, $newScore, $reason);
        }

        // 10. Log de sucesso
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix] ✅ Score atualizado: Profissional #%d | %.2f → %.2f | Razão: %s',
                $professionalId,
                $oldScore,
                $newScore,
                $reason
            ));
        }

        return [
            'success' => true,
            'score_changed' => true,
            'professional_id' => $professionalId,
            'old_score' => $oldScore,
            'new_score' => $newScore,
            'score_change' => round($newScore - $oldScore, 2),
            'reason' => $reason,
            'message' => sprintf(
                'Score atualizado de %.2f para %.2f (%s)',
                $oldScore,
                $newScore,
                $newScore > $oldScore ? '+' . ($newScore - $oldScore) : ($newScore - $oldScore)
            ),
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
        if (empty($data['professional_id'])) {
            return new \WP_Error(
                'missing_professional_id',
                'professional_id é obrigatório',
                ['status' => 400]
            );
        }

        if (empty($data['reason'])) {
            return new \WP_Error(
                'missing_reason',
                'reason é obrigatório',
                ['status' => 400]
            );
        }

        // Validar motivo conhecido (warn se desconhecido, mas não bloqueia)
        $knownReasons = array_keys(self::SCORE_IMPACTS);
        if (!in_array($data['reason'], $knownReasons, true)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[LimpVix] ⚠️ Motivo de score desconhecido: %s (conhecido: %s)',
                    $data['reason'],
                    implode(', ', $knownReasons)
                ));
            }
        }

        return true;
    }

    /**
     * Calcula novo score baseado em regras de negócio
     *
     * @param \LimpVix\Domain\Professional\Professional $professional
     * @param string $reason
     * @param array $details
     * @return float
     */
    private function calculateNewScore($professional, string $reason, array $details): float
    {
        $currentScore = $professional->getScore();

        // Caso especial: feedback do cliente (calculado dinamicamente)
        if ($reason === 'feedback' && isset($details['rating'])) {
            return $this->calculateScoreFromFeedback($professional, $details['rating']);
        }

        // Caso especial: feedback excelente (>= 4.5)
        if ($reason === 'feedback' && isset($details['rating']) && $details['rating'] >= 4.5) {
            return min(5.00, $currentScore + self::SCORE_IMPACTS['excellent_feedback']);
        }

        // Caso especial: feedback ruim (< 3.0)
        if ($reason === 'feedback' && isset($details['rating']) && $details['rating'] < 3.0) {
            return max(0.00, $currentScore + self::SCORE_IMPACTS['poor_feedback']);
        }

        // Casos padrão: aplicar impacto direto
        if (isset(self::SCORE_IMPACTS[$reason])) {
            $impact = self::SCORE_IMPACTS[$reason];
            return $currentScore + $impact;
        }

        // Motivo desconhecido: não muda score
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[LimpVix] Motivo desconhecido para score: $reason, mantendo score atual");
        }

        return $currentScore;
    }

    /**
     * Calcula score baseado em feedback do cliente (média móvel ponderada)
     *
     * Algoritmo:
     * - Peso feedback atual: 70%
     * - Peso score histórico: 30%
     * - Isso faz o score reagir rapidamente a feedbacks mas mantém estabilidade
     *
     * @param \LimpVix\Domain\Professional\Professional $professional
     * @param float $rating Rating do feedback (0-5)
     * @return float
     */
    private function calculateScoreFromFeedback($professional, float $rating): float
    {
        $currentScore = $professional->getScore();
        $totalServices = $professional->getTotalServices();

        // Se é o primeiro serviço, usar rating diretamente
        if ($totalServices <= 1) {
            return round($rating, 2);
        }

        // Média móvel ponderada: 70% feedback atual + 30% score histórico
        $newScore = ($rating * 0.7) + ($currentScore * 0.3);

        return round($newScore, 2);
    }

    /**
     * Notifica profissional sobre queda significativa de score
     *
     * @param \LimpVix\Domain\Professional\Professional $professional
     * @param float $oldScore
     * @param float $newScore
     * @param string $reason
     * @return void
     */
    private function notifyProfessionalScoreDropped($professional, float $oldScore, float $newScore, string $reason): void
    {
        $email = $professional->getEmail();
        $fullName = $professional->getFullName();

        $subject = '⚠️ Seu Score na LimpVix Diminuiu';

        $message = "Olá {$fullName},\n\n";
        $message .= "Seu score na plataforma LimpVix sofreu uma alteração:\n\n";
        $message .= "📊 Score anterior: " . number_format($oldScore, 2, ',', '.') . "\n";
        $message .= "📊 Score atual: " . number_format($newScore, 2, ',', '.') . "\n";
        $message .= "📉 Mudança: " . number_format($newScore - $oldScore, 2, ',', '.') . "\n\n";
        $message .= "🔍 Motivo: " . $this->translateReason($reason) . "\n\n";
        $message .= "💡 Dicas para melhorar seu score:\n";
        $message .= "• Chegue sempre no horário (dentro da janela de ±1h)\n";
        $message .= "• Nunca falte aos compromissos agendados\n";
        $message .= "• Mantenha seus EPIs sempre em ordem\n";
        $message .= "• Capriche na qualidade do serviço\n";
        $message .= "• Seja educado e profissional com os clientes\n\n";
        $message .= "⚠️ ATENÇÃO: Score abaixo de 3.0 pode resultar em suspensão temporária.\n\n";
        $message .= "Qualquer dúvida, entre em contato com nosso suporte.\n\n";
        $message .= "Equipe LimpVix";

        wp_mail($email, $subject, $message);
    }

    /**
     * Notifica admin sobre profissional com score crítico
     *
     * @param \LimpVix\Domain\Professional\Professional $professional
     * @param float $newScore
     * @param string $reason
     * @return void
     */
    private function notifyAdminLowScore($professional, float $newScore, string $reason): void
    {
        $adminEmail = get_option('admin_email');
        $fullName = $professional->getFullName();
        $professionalId = $professional->getId();

        $subject = "⚠️ Profissional com Score Crítico: {$fullName}";

        $message = "Profissional com score crítico detectado:\n\n";
        $message .= "ID: {$professionalId}\n";
        $message .= "Nome: {$fullName}\n";
        $message .= "Email: {$professional->getEmail()}\n";
        $message .= "CPF: {$professional->getCpf()}\n";
        $message .= "Score atual: " . number_format($newScore, 2, ',', '.') . "\n";
        $message .= "Motivo da queda: " . $this->translateReason($reason) . "\n\n";
        $message .= "⚠️ AÇÃO RECOMENDADA:\n";
        $message .= "- Revisar histórico do profissional\n";
        $message .= "- Avaliar necessidade de suspensão temporária\n";
        $message .= "- Contatar profissional para orientação\n\n";
        $message .= "Acesse o painel administrativo para mais detalhes.";

        wp_mail($adminEmail, $subject, $message);

        // Disparar evento de notificação admin
        do_action('limpvix_admin_notification', [
            'type' => 'professional_low_score',
            'title' => 'Profissional com Score Crítico',
            'message' => "{$fullName} está com score " . number_format($newScore, 2, ',', '.'),
            'professional_id' => $professionalId,
            'score' => $newScore,
            'priority' => 'high',
        ]);
    }

    /**
     * Traduz motivo de mudança de score para português
     *
     * @param string $reason
     * @return string
     */
    private function translateReason(string $reason): string
    {
        $translations = [
            'feedback' => 'Avaliação do cliente',
            'late_checkin' => 'Atraso no check-in',
            'no_show' => 'Não comparecimento',
            'good_execution' => 'Boa execução',
            'epi_violation' => 'Violação de EPI',
            'completed_service' => 'Serviço completado',
            'contract_cancelled' => 'Contrato cancelado',
            'client_complaint' => 'Reclamação do cliente',
            'excellent_feedback' => 'Avaliação excelente',
            'poor_feedback' => 'Avaliação ruim',
        ];

        return $translations[$reason] ?? $reason;
    }
}
