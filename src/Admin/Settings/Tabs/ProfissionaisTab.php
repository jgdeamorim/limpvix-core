<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

class ProfissionaisTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'profissionais'; }
    public function getLabel(): string { return 'Profissionais'; }
    public function getIcon(): string { return '👷'; }

    public function handleSave(): void
    {
        // Save is handled inline within render() via POST detection
    }

    public function render(): void
    {
        // ONDA 2 - Task #106: Configurações de Profissionais

        // Carregar configurações atuais
        $requireIdVerification = get_option('limpvix_prof_require_id_verification', true);
        $requireBackgroundCheck = get_option('limpvix_prof_require_background_check', false);
        $autoVerifyAfterServices = get_option('limpvix_prof_auto_verify_after_services', 10);
        $verificationExpiryDays = get_option('limpvix_prof_verification_expiry_days', 365);
        $bgCheckValidityDays = get_option('limpvix_prof_background_check_validity_days', 365);
        $bgCheckUseMock = get_option('limpvix_prof_background_check_use_mock', true);

        $initialScore = get_option('limpvix_prof_initial_score', 80); // NOVO: Score inicial neutro
        $minScoreThreshold = get_option('limpvix_prof_min_score_threshold', 70);
        $recentReviewsWeight = get_option('limpvix_prof_recent_reviews_weight', 70);
        $autoSuspendBelowScore = get_option('limpvix_prof_auto_suspend_below_score', 50);

        $defaultAvailabilityWindow = get_option('limpvix_prof_default_availability_window', 30);
        $maxConcurrentBookings = get_option('limpvix_prof_max_concurrent_bookings', 3);
        $minNoticeHours = get_option('limpvix_prof_min_notice_hours', 24);
        $bufferBetweenAppointments = get_option('limpvix_prof_buffer_between_appointments', 60);
        $offerAcceptanceToleranceMinutes = get_option('limpvix_prof_offer_acceptance_tolerance', 10); // NOVO
        $allowUnavailableStatus = get_option('limpvix_prof_allow_unavailable_status', true); // NOVO

        $maxServiceRadius = get_option('limpvix_prof_max_service_radius', 20);
        $enableGpsTracking = get_option('limpvix_prof_enable_gps_tracking', false);
        $proximityScoringWeight = get_option('limpvix_prof_proximity_scoring_weight', 40);
        $useZipCodeForMatching = get_option('limpvix_prof_use_zipcode_matching', true);

        $minPayoutAmount = get_option('limpvix_prof_min_payout_amount', 50.00);
        $platformFeePercentage = \LimpVix\Infrastructure\Configuration\PlatformFeeConfig::getFeePercentage();
        $allowProfessionalWithdrawal = get_option('limpvix_prof_allow_withdrawal', true);

        // NOVO: Payouts baseados em Feedback
        $payout5StarsHoldHours = get_option('limpvix_prof_payout_5stars_hold', 0); // Instantâneo
        $payout4StarsHoldHours = get_option('limpvix_prof_payout_4stars_hold', 1); // 1 hora
        $payout3StarsHoldHours = get_option('limpvix_prof_payout_3stars_hold', 24); // 24 horas
        $payoutBelow3StarsHoldHours = get_option('limpvix_prof_payout_below3_hold', 24); // 24h + manual
        $allowClientReportLowRating = get_option('limpvix_prof_allow_client_report', true);

        // Processar salvamento
        if (isset($_POST['limpvix_save_profissionais_settings']) && check_admin_referer('limpvix_profissionais_settings')) {
            // Verificação
            update_option('limpvix_prof_require_id_verification', isset($_POST['require_id_verification']));
            update_option('limpvix_prof_require_background_check', isset($_POST['require_background_check']));
            update_option('limpvix_prof_auto_verify_after_services', intval($_POST['auto_verify_after_services']));
            update_option('limpvix_prof_verification_expiry_days', intval($_POST['verification_expiry_days']));

            // Background Check
            update_option('limpvix_prof_background_check_validity_days', max(30, intval($_POST['background_check_validity_days'] ?? 365)));
            update_option('limpvix_prof_background_check_use_mock', isset($_POST['background_check_use_mock']));

            // Score
            update_option('limpvix_prof_initial_score', intval($_POST['initial_score']));
            update_option('limpvix_prof_min_score_threshold', intval($_POST['min_score_threshold']));
            update_option('limpvix_prof_recent_reviews_weight', intval($_POST['recent_reviews_weight']));
            update_option('limpvix_prof_auto_suspend_below_score', intval($_POST['auto_suspend_below_score']));

            // Disponibilidade
            update_option('limpvix_prof_default_availability_window', intval($_POST['default_availability_window']));
            update_option('limpvix_prof_max_concurrent_bookings', intval($_POST['max_concurrent_bookings']));
            update_option('limpvix_prof_min_notice_hours', intval($_POST['min_notice_hours']));
            update_option('limpvix_prof_buffer_between_appointments', intval($_POST['buffer_between_appointments']));
            update_option('limpvix_prof_offer_acceptance_tolerance', intval($_POST['offer_acceptance_tolerance'])); // NOVO
            update_option('limpvix_prof_allow_unavailable_status', isset($_POST['allow_unavailable_status'])); // NOVO

            // Geolocalização
            update_option('limpvix_prof_max_service_radius', intval($_POST['max_service_radius']));
            update_option('limpvix_prof_enable_gps_tracking', isset($_POST['enable_gps_tracking']));
            update_option('limpvix_prof_proximity_scoring_weight', intval($_POST['proximity_scoring_weight']));
            update_option('limpvix_prof_use_zipcode_matching', isset($_POST['use_zipcode_matching']));

            // Payouts Gerais
            update_option('limpvix_prof_min_payout_amount', floatval($_POST['min_payout_amount']));
            // Persistir taxa via SSOT — única chave canônica: limpvix_platform_fee_percentage
            \LimpVix\Infrastructure\Configuration\PlatformFeeConfig::setFeePercentage(
                floatval($_POST['platform_fee_percentage'])
            );
            update_option('limpvix_prof_allow_withdrawal', isset($_POST['allow_professional_withdrawal']));

            // Payouts baseados em Feedback (NOVO)
            update_option('limpvix_prof_payout_5stars_hold', intval($_POST['payout_5stars_hold']));
            update_option('limpvix_prof_payout_4stars_hold', intval($_POST['payout_4stars_hold']));
            update_option('limpvix_prof_payout_3stars_hold', intval($_POST['payout_3stars_hold']));
            update_option('limpvix_prof_payout_below3_hold', intval($_POST['payout_below3_hold']));
            update_option('limpvix_prof_allow_client_report', isset($_POST['allow_client_report']));

            // Payouts EFI Bank PIX
            update_option('limpvix_payout_minimum_amount', floatval($_POST['payout_minimum_amount'] ?? 50));
            update_option('limpvix_payout_notify_admin_pix_pending', isset($_POST['payout_notify_admin_pix_pending']));

            // ── Feedback do Cliente
            update_option('limpvix_feedback_window_hours',        max(1, intval($_POST['feedback_window_hours'] ?? 48)));
            update_option('limpvix_feedback_require_evidence_lt3', isset($_POST['feedback_require_evidence_lt3']));
            update_option('limpvix_feedback_auto_approve_days',    max(0, intval($_POST['feedback_auto_approve_days'] ?? 7)));
            update_option('limpvix_feedback_allow_edit_hours',     max(0, intval($_POST['feedback_allow_edit_hours'] ?? 24)));

            // ── CheckIn / CheckOut
            update_option('limpvix_checkin_geofence_radius_m',    max(50, intval($_POST['checkin_geofence_radius_m'] ?? 150)));
            update_option('limpvix_checkin_time_tolerance_min',    max(0, intval($_POST['checkin_time_tolerance_min'] ?? 15)));
            update_option('limpvix_checkin_require_gps',           isset($_POST['checkin_require_gps']));
            update_option('limpvix_checkout_require_photo',        isset($_POST['checkout_require_photo']));

            // ── Evidências Profissional
            update_option('limpvix_evidence_prof_required',        isset($_POST['evidence_prof_required']));
            update_option('limpvix_evidence_prof_min_photos',      max(0, intval($_POST['evidence_prof_min_photos'] ?? 2)));
            update_option('limpvix_evidence_prof_max_photos',      max(1, intval($_POST['evidence_prof_max_photos'] ?? 10)));
            update_option('limpvix_evidence_prof_max_mb',          max(1, intval($_POST['evidence_prof_max_mb'] ?? 20)));
            update_option('limpvix_evidence_prof_allow_video',     isset($_POST['evidence_prof_allow_video']));

            // ── Evidências Cliente
            update_option('limpvix_evidence_client_required',      isset($_POST['evidence_client_required']));
            update_option('limpvix_evidence_client_min_photos',    max(0, intval($_POST['evidence_client_min_photos'] ?? 0)));
            update_option('limpvix_evidence_client_allow_dispute', isset($_POST['evidence_client_allow_dispute']));
            update_option('limpvix_evidence_client_dispute_hours', max(1, intval($_POST['evidence_client_dispute_hours'] ?? 72)));

            // ── Resolução de Feedbacks e Evidências
            update_option('limpvix_resolution_cap_authorize',  sanitize_key($_POST['resolution_cap_authorize'] ?? 'manage_options'));
            update_option('limpvix_resolution_cap_resolve',    sanitize_key($_POST['resolution_cap_resolve']   ?? 'limpvix_resolve_feedback'));
            update_option('limpvix_resolution_deadline_hours', max(1, intval($_POST['resolution_deadline_hours'] ?? 48)));
            update_option('limpvix_resolution_apply_penalty',  isset($_POST['resolution_apply_penalty']));
            update_option('limpvix_payout_cap_authorize',      sanitize_key($_POST['payout_cap_authorize']     ?? 'limpvix_authorize_payout'));
            update_option('limpvix_payout_cap_process',        sanitize_key($_POST['payout_cap_process']       ?? 'limpvix_process_payout'));

            wp_redirect(add_query_arg(['page' => 'limpvix-settings', 'tab' => 'profissionais', 'updated' => '1'], admin_url('admin.php')));
            exit;
        }

        // Buscar estatísticas de profissionais
        $profStats = $this->calculateProfessionalsStats();
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('limpvix_profissionais_settings'); ?>

            <!-- Dashboard de Estatísticas de Profissionais -->
            <div class="limpvix-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 20px; border: none;">
                <div class="limpvix-card-body" style="padding: 30px;">
                    <h2 style="color: white; margin: 0 0 20px 0; font-size: 24px;">
                        👷 Dashboard de Profissionais
                    </h2>

                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                        <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo $profStats['total']; ?></div>
                            <div style="font-size: 13px; opacity: 0.9;">Total Cadastrados</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo $profStats['verified']; ?></div>
                            <div style="font-size: 13px; opacity: 0.9;">KYC Aprovado</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo $profStats['pix_registered']; ?></div>
                            <div style="font-size: 13px; opacity: 0.9;">PIX Cadastrado</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo $profStats['active']; ?></div>
                            <div style="font-size: 13px; opacity: 0.9;">Aptos a Trabalhar</div>
                        </div>
                    </div>

                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.2);">
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; font-size: 13px;">
                            <div>
                                <strong>Método de Payout:</strong><br>
                                PIX Cadastrado: <?php echo $profStats['pix_registered']; ?> | Sem PIX: <?php echo max(0, $profStats['total'] - $profStats['pix_registered']); ?>
                            </div>
                            <div>
                                <strong>Score Médio:</strong><br>
                                <?php echo number_format($profStats['avg_score'], 1); ?> pontos
                            </div>
                            <div>
                                <strong>Taxa de Verificação:</strong><br>
                                <?php echo $profStats['total'] > 0 ? round(($profStats['verified'] / $profStats['total']) * 100) : 0; ?>% verificados
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card: KYC Biométrico -->
            <div class="limpvix-card" style="background: #f0f9ff; border-left: 4px solid #3b82f6; margin-bottom: 20px;">
                <div class="limpvix-card-header">
                    <h3><span class="dashicons dashicons-shield-alt"></span> 🔐 KYC Biométrico - Verificação de Identidade</h3>
                    <p>Verificação biométrica obrigatória com OCR + Liveness + Face Match</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Status do KYC:</th>
                            <td>
                                <?php
                                $ppidEnabled = get_option('limpvix_ppid_enabled', false);
                                $ppidEmail = get_option('limpvix_ppid_email', '');
                                if ($ppidEnabled && !empty($ppidEmail)) {
                                    echo '<span class="limpvix-badge limpvix-badge-success">✅ Ativo</span>';
                                } else {
                                    echo '<span class="limpvix-badge limpvix-badge-warning">⚠️ Não Configurado</span>';
                                }
                                ?>
                                <p class="description">
                                    <strong>Configure KYC em:</strong> <a href="?page=limpvix-settings&tab=conexoes">Configurações > Conexões > PPID KYC</a><br>
                                    <strong>Gerencie verificações em:</strong> <a href="?page=limpvix-kyc">Profissionais > KYC Biométrico</a>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Funcionalidades:</th>
                            <td>
                                <ul style="margin: 5px 0; padding-left: 20px;">
                                    <li>📄 <strong>OCR de Documentos:</strong> Extração automática de dados de RG/CNH</li>
                                    <li>🧑 <strong>Liveness Detection:</strong> Prova de vida (não aceita fotos)</li>
                                    <li>👤 <strong>Face Match:</strong> Comparação entre foto do documento e selfie</li>
                                    <li>⚡ <strong>Aprovação Automática:</strong> Baseada em scores PPID</li>
                                    <li>🔄 <strong>Modo Mock:</strong> Teste sem consumir créditos</li>
                                </ul>
                            </td>
                        </tr>
                    </table>
                    <div style="background: #fff3cd; padding: 12px; border-left: 3px solid #f0ad4e; margin-top: 15px;">
                        <strong>⚠️ Importante:</strong> Profissionais SEM KYC aprovado NÃO podem aceitar ofertas de trabalho (compliance e segurança).
                    </div>
                </div>
            </div>

            <!-- Seção 1: Verificação de Profissionais -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3><span class="dashicons dashicons-yes-alt"></span> ✅ Verificação de Profissionais</h3>
                    <p>Configurações de verificação e validação de profissionais</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Verificação de Identidade:</th>
                            <td>
                                <label><input type="checkbox" name="require_id_verification" value="1" <?php checked($requireIdVerification); ?>> Exigir verificação de documento de identidade</label>
                                <p class="description">Gerenciado via KYC Biométrico (PPID). <a href="?page=limpvix-settings&tab=conexoes">Configure credenciais em Conexões</a>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Checagem de Antecedentes:</th>
                            <td>
                                <label><input type="checkbox" name="require_background_check" value="1" <?php checked($requireBackgroundCheck); ?>> Exigir checagem de antecedentes criminais obrigatória</label>
                                <p class="description">Quando ativado, profissional só pode aceitar ofertas com background check aprovado.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Auto-Verificação:</th>
                            <td>
                                <input type="number" name="auto_verify_after_services" value="<?php echo esc_attr($autoVerifyAfterServices); ?>" min="0" class="small-text"> serviços completados
                                <p class="description">Verificar automaticamente após N serviços bem-sucedidos (0 = desabilitado)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Validade da Verificação (KYC):</th>
                            <td>
                                <input type="number" name="verification_expiry_days" value="<?php echo esc_attr($verificationExpiryDays); ?>" min="0" class="small-text"> dias
                                <p class="description">Verificação KYC expira após este período (0 = nunca expira)</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Card: Background Check -->
            <div class="limpvix-card" style="background: #faf5ff; border-left: 4px solid #7c3aed; margin-bottom: 20px; margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3><span class="dashicons dashicons-search"></span> 🔍 Background Check — Antecedentes Criminais</h3>
                    <p>Verificação de antecedentes via Exato Digital. Gerencie nas abas <a href="?page=limpvix-professionals&tab=risk_score">Risk Score</a> e <a href="?page=limpvix-settings&tab=conexoes">Conexões</a>.</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $exatoApiKey = get_option('limpvix_exato_api_key', '');
                    $exatoEnabled = !empty($exatoApiKey);
                    $bgMockActive = $bgCheckUseMock || !$exatoEnabled;
                    global $wpdb;
                    $bgTable = $wpdb->prefix . 'limpvix_professional_verification';
                    $bgStats = [];
                    if ($wpdb->get_var("SHOW TABLES LIKE '{$bgTable}'") === $bgTable) {
                        $bgStats = $wpdb->get_row("SELECT COUNT(*) AS total, SUM(background_status = 'CLEAR') AS cleared, SUM(background_status = 'CONSIDER') AS consider, SUM(background_status = 'ADVERSE') AS adverse, SUM(background_status = 'PENDING') AS pending, SUM(background_expires_at IS NOT NULL AND background_expires_at < NOW() AND final_status IN ('ACTIVE','ACTIVE_MONITORED')) AS expired FROM {$bgTable}", ARRAY_A) ?? [];
                    }
                    ?>
                    <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 160px; background: <?php echo $exatoEnabled ? '#d1fae5' : '#fef3c7'; ?>; border: 1px solid <?php echo $exatoEnabled ? '#10b981' : '#f59e0b'; ?>; border-radius: 8px; padding: 14px; text-align: center;">
                            <div style="font-size: 22px;"><?php echo $exatoEnabled ? '✅' : '⚠️'; ?></div>
                            <strong>Exato Digital</strong><br>
                            <span style="font-size: 12px; color: <?php echo $exatoEnabled ? '#065f46' : '#92400e'; ?>;"><?php echo $exatoEnabled ? 'Configurado' : 'Não configurado'; ?></span>
                            <?php if (!$exatoEnabled): ?><br><a href="?page=limpvix-settings&tab=conexoes" style="font-size: 11px;">Configurar →</a><?php endif; ?>
                        </div>
                        <div style="flex: 1; min-width: 160px; background: <?php echo $bgMockActive ? '#fef3c7' : '#f0fdf4'; ?>; border: 1px solid <?php echo $bgMockActive ? '#f59e0b' : '#22c55e'; ?>; border-radius: 8px; padding: 14px; text-align: center;">
                            <div style="font-size: 22px;"><?php echo $bgMockActive ? '🧪' : '🚀'; ?></div>
                            <strong>Modo Ativo</strong><br>
                            <span style="font-size: 12px; color: <?php echo $bgMockActive ? '#92400e' : '#15803d'; ?>;"><?php echo $bgMockActive ? 'Mock (Teste)' : 'Real (Produção)'; ?></span>
                        </div>
                        <?php if (!empty($bgStats)): ?>
                        <div style="flex: 1; min-width: 160px; background: #eff6ff; border: 1px solid #3b82f6; border-radius: 8px; padding: 14px; text-align: center;">
                            <div style="font-size: 22px;">📊</div>
                            <strong>Verificações</strong><br>
                            <span style="font-size: 12px; color: #1e40af;"><?php echo (int)($bgStats['cleared'] ?? 0); ?> aprovadas / <?php echo (int)($bgStats['total'] ?? 0); ?> total</span>
                        </div>
                        <?php if ((int)($bgStats['expired'] ?? 0) > 0): ?>
                        <div style="flex: 1; min-width: 160px; background: #fef2f2; border: 1px solid #ef4444; border-radius: 8px; padding: 14px; text-align: center;">
                            <div style="font-size: 22px;">⚠️</div>
                            <strong>Expirados</strong><br>
                            <span style="font-size: 12px; color: #991b1b;"><?php echo (int)$bgStats['expired']; ?> checks expirados</span>
                            <br><a href="?page=limpvix-professionals&tab=risk_score&filter_risk=bg_expired" style="font-size: 11px;">Ver →</a>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Usar Provider Mock (Teste):</th>
                            <td>
                                <label><input type="checkbox" name="background_check_use_mock" value="1" <?php checked($bgCheckUseMock); ?>> Ativar modo mock (simula aprovação automática, não consume créditos)</label>
                                <p class="description"><?php if (!$exatoEnabled): ?><strong>⚠️ Exato Digital não configurado.</strong> Mock é obrigatório enquanto as credenciais não estiverem configuradas em <a href="?page=limpvix-settings&tab=conexoes">Conexões</a>.<?php else: ?>Desmarque para usar a API real do Exato Digital em produção.<?php endif; ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Validade do Background Check:</th>
                            <td>
                                <input type="number" name="background_check_validity_days" value="<?php echo esc_attr($bgCheckValidityDays); ?>" min="30" max="730" class="small-text"> dias
                                <p class="description">Após este período, o profissional precisa renovar o background check.<br>Recomendado: <strong>365 dias</strong> (anual). Mínimo: 30 dias.</p>
                            </td>
                        </tr>
                    </table>
                    <?php if (!empty($bgStats) && (int)($bgStats['total'] ?? 0) > 0): ?>
                    <div style="margin-top: 15px; padding: 12px; background: #f8fafc; border-radius: 6px;">
                        <strong style="display: block; margin-bottom: 8px; font-size: 13px;">📈 Distribuição de Status:</strong>
                        <div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px;">
                            <span>✅ Clear: <strong><?php echo (int)($bgStats['cleared'] ?? 0); ?></strong></span>
                            <span>⚠️ Consider: <strong><?php echo (int)($bgStats['consider'] ?? 0); ?></strong></span>
                            <span>❌ Adverse: <strong><?php echo (int)($bgStats['adverse'] ?? 0); ?></strong></span>
                            <span>⏳ Pending: <strong><?php echo (int)($bgStats['pending'] ?? 0); ?></strong></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div style="background: #ede9fe; padding: 12px; border-left: 3px solid #7c3aed; margin-top: 15px;">
                        <strong>🔍 Gerencie verificações em:</strong>
                        <ul style="margin: 5px 0 0 20px;">
                            <li><a href="?page=limpvix-professionals&tab=risk_score">Profissionais → Aba Risk Score</a> — Ver todos os checks e renovar manualmente</li>
                            <li><a href="?page=limpvix-professionals&tab=kyc">Profissionais → Aba KYC</a> — Pipeline completo de verificação</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Seção 2: Score & Ratings -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header"><h3><span class="dashicons dashicons-star-filled"></span> ⭐ Score & Avaliações</h3><p>Configurações de pontuação e avaliação de profissionais</p></div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr><th scope="row">Score Inicial do Profissional:</th><td><input type="number" name="initial_score" value="<?php echo esc_attr($initialScore); ?>" min="0" max="100" class="small-text"> pontos<p class="description"><strong>Pontuação inicial quando profissional se cadastra</strong><br>⚠️ <strong>IMPORTANTE:</strong> Deve ser MENOR que 100 para permitir crescimento! Recomendado: <strong>80 pontos</strong></p></td></tr>
                        <tr><th scope="row">Score Mínimo para Alocação:</th><td><input type="number" name="min_score_threshold" value="<?php echo esc_attr($minScoreThreshold); ?>" min="0" max="100" class="small-text"> pontos<p class="description">Pontuação mínima necessária para receber ofertas de trabalho (recomendado: 70)</p></td></tr>
                        <tr><th scope="row">Método de Cálculo:</th><td><span class="limpvix-badge limpvix-badge-success">Média Ponderada com Decay Exponencial</span><p class="description">Score = média ponderada com decay 0.95^dias (avaliações recentes pesam mais). Método fixo do sistema.</p></td></tr>
                        <tr><th scope="row">Peso de Avaliações Recentes:</th><td><input type="number" name="recent_reviews_weight" value="<?php echo esc_attr($recentReviewsWeight); ?>" min="0" max="100" class="small-text"> %<p class="description">Peso das últimas 10 avaliações no cálculo (apenas se método = ponderado)</p></td></tr>
                        <tr><th scope="row">Auto-Suspender Abaixo de:</th><td><input type="number" name="auto_suspend_below_score" value="<?php echo esc_attr($autoSuspendBelowScore); ?>" min="0" max="100" class="small-text"> pontos<p class="description">Suspender profissional automaticamente se score cair abaixo deste valor (0 = desabilitado)</p></td></tr>
                    </table>
                </div>
            </div>

            <!-- Seção 3: Disponibilidade -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header"><h3><span class="dashicons dashicons-calendar-alt"></span> 📅 Disponibilidade</h3><p>Configurações de disponibilidade e agendamento</p></div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr><th scope="row">Janela de Disponibilidade Padrão:</th><td><input type="number" name="default_availability_window" value="<?php echo esc_attr($defaultAvailabilityWindow); ?>" min="1" class="small-text"> dias<p class="description">Profissionais devem manter disponibilidade atualizada para os próximos N dias</p></td></tr>
                        <tr><th scope="row">Agendamentos Simultâneos Máximos:</th><td><input type="number" name="max_concurrent_bookings" value="<?php echo esc_attr($maxConcurrentBookings); ?>" min="1" class="small-text"> serviços<p class="description">Número máximo de agendamentos simultâneos por profissional</p></td></tr>
                        <tr><th scope="row">Aviso Mínimo:</th><td><input type="number" name="min_notice_hours" value="<?php echo esc_attr($minNoticeHours); ?>" min="0" class="small-text"> horas<p class="description">Antecedência mínima para aceitar novo agendamento</p></td></tr>
                        <tr><th scope="row">Buffer Entre Serviços:</th><td><input type="number" name="buffer_between_appointments" value="<?php echo esc_attr($bufferBetweenAppointments); ?>" min="0" class="small-text"> minutos<p class="description">Tempo mínimo entre serviços consecutivos (deslocamento + preparação)</p></td></tr>
                        <tr><th scope="row">Tolerância para Aceitar Oferta:</th><td><input type="number" name="offer_acceptance_tolerance" value="<?php echo esc_attr($offerAcceptanceToleranceMinutes); ?>" min="1" max="60" class="small-text"> minutos<p class="description"><strong>Tempo máximo para profissional aceitar ou recusar oferta de trabalho</strong></p></td></tr>
                        <tr><th scope="row">Permitir Status "Indisponível":</th><td><label><input type="checkbox" name="allow_unavailable_status" value="1" <?php checked($allowUnavailableStatus); ?>> Profissional pode marcar-se como "Indisponível" em sua área</label></td></tr>
                    </table>
                </div>
            </div>

            <!-- Seção 4: Geolocalização e Matching -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header"><h3><span class="dashicons dashicons-location"></span> 📍 Geolocalização e Matching por Proximidade</h3><p>Configurações de matching geográfico para conectar profissionais e clientes em TODO O BRASIL</p></div>
                <div class="limpvix-card-body">
                    <div style="background: #e8f4f8; padding: 15px; border-left: 4px solid #00a0d2; margin-bottom: 20px;">
                        <h4 style="margin-top: 0;">🇧🇷 Marketplace Nacional - Como Funciona o Matching</h4>
                        <p><strong>LimpVix opera em TODO O TERRITÓRIO BRASILEIRO</strong> - não há "localização padrão" fixa!</p>
                    </div>
                    <div style="background: #f0fdf4; padding: 12px; border-left: 3px solid #22c55e; margin-bottom: 15px;">
                        <strong>Algoritmo de Matching (ProfessionalMatcher):</strong>
                        <div style="display: flex; gap: 15px; margin-top: 8px; flex-wrap: wrap; font-size: 13px;">
                            <span style="background: #dcfce7; padding: 4px 10px; border-radius: 4px;">Proximidade <strong>40%</strong></span>
                            <span style="background: #dbeafe; padding: 4px 10px; border-radius: 4px;">Disponibilidade <strong>30%</strong></span>
                            <span style="background: #fef9c3; padding: 4px 10px; border-radius: 4px;">Rating <strong>20%</strong></span>
                            <span style="background: #f3e8ff; padding: 4px 10px; border-radius: 4px;">Carga <strong>10%</strong></span>
                        </div>
                        <p style="font-size: 12px; color: #6b7280; margin: 8px 0 0;">Proximidade: Haversine linear decay (max <?php echo esc_html($maxServiceRadius); ?>km) | Carga max: 480min/dia | Alocação: 1-5 profissionais</p>
                    </div>
                    <table class="form-table">
                        <tr><th scope="row">Matching por CEP:</th><td><label><input type="checkbox" name="use_zipcode_matching" value="1" <?php checked($useZipCodeForMatching); ?>> <strong>Habilitar matching automático por proximidade de CEP</strong></label><p class="description"><strong>✅ RECOMENDADO: Sempre habilitado para marketplace nacional</strong></p></td></tr>
                        <tr><th scope="row">Geocodificação:</th><td><span class="limpvix-badge limpvix-badge-success">BrasilAPI CEP v2</span><p class="description">Geocoding via BrasilAPI CEP v2 + cache 24h + fallback mapa local IBGE. Serviço fixo do sistema.</p></td></tr>
                        <tr><th scope="row">Raio Máximo de Atendimento:</th><td><input type="number" name="max_service_radius" value="<?php echo esc_attr($maxServiceRadius); ?>" min="1" max="100" class="small-text"> km<p class="description"><strong>Distância máxima (Haversine) entre profissional e cliente</strong><br>Padrão do sistema: <strong>20 km</strong> | Scoring: <=5km=40pts, <=10km=30pts, <=15km=20pts, <=20km=10pts</p></td></tr>
                        <tr><th scope="row">Peso da Proximidade no Matching:</th><td><input type="number" name="proximity_scoring_weight" value="<?php echo esc_attr($proximityScoringWeight); ?>" min="0" max="100" class="small-text"> %<p class="description"><strong>Quanto a proximidade influencia na seleção do profissional</strong><br>Padrão do sistema: <strong>40%</strong> (AllocationPolicy + ProfessionalMatcher)</p></td></tr>
                        <tr><th scope="row">Rastreamento GPS em Tempo Real:</th><td><label><input type="checkbox" name="enable_gps_tracking" value="1" <?php checked($enableGpsTracking); ?>> Habilitar rastreamento GPS durante execução do serviço</label></td></tr>
                    </table>
                </div>
            </div>

            <!-- Seção 5: Configurações Gerais de Payouts -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header"><h3><span class="dashicons dashicons-money-alt"></span> 💰 Configurações Gerais de Payouts</h3><p>Configurações de pagamento por serviço prestado (profissionais autônomos)</p></div>
                <div class="limpvix-card-body">
                    <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #dc3232; margin-bottom: 20px;">
                        <h4 style="margin-top: 0; color: #dc3232;">⚠️ ATENÇÃO LEGAL: Evitar Vínculo Empregatício</h4>
                        <p><strong>Como marketplace de serviços, é CRÍTICO manter profissionais como AUTÔNOMOS.</strong></p>
                    </div>

                    <!-- EFI Bank PIX Payout Configuration -->
                    <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 30px;">
                        <h4 style="margin-top: 0; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">🏦 EFI Bank PIX Cash-Out</h4>
                        <div style="background: #f0fdf4; padding: 10px; border-left: 3px solid #22c55e; margin-bottom: 15px; font-size: 13px;">
                            <strong>Modo:</strong> On-demand por execução (profissional recebe POR SERVIÇO, nunca salário fixo)<br>
                            <strong>Gateway:</strong> EFI Bank PIX (mTLS + OAuth2, token cache 55min)<br>
                            <strong>Regra de Ouro:</strong> Payout só executa se Execution.status === CHECKED_OUT
                        </div>
                        <table class="form-table">
                            <tr><th scope="row">Valor Mínimo para Payout:</th><td>R$ <input type="number" name="payout_minimum_amount" value="<?php echo esc_attr(get_option('limpvix_payout_minimum_amount', 50)); ?>" min="1" step="0.01" class="small-text" style="width: 100px;"><p class="description">Payouts abaixo deste valor ficam acumulados até atingir o mínimo.</p></td></tr>
                            <tr><th scope="row">Notificar Admin sobre PIX Pendentes:</th><td><label><input type="checkbox" name="payout_notify_admin_pix_pending" value="1" <?php checked(get_option('limpvix_payout_notify_admin_pix_pending', 1)); ?>> Enviar email diário se houver PIX pendentes</label></td></tr>
                        </table>
                    </div>

                    <table class="form-table">
                        <tr><th scope="row">Permitir Saque Manual:</th><td><label><input type="checkbox" name="allow_professional_withdrawal" value="1" <?php checked($allowProfessionalWithdrawal); ?>> Profissional pode solicitar saque a qualquer momento</label></td></tr>
                        <tr><th scope="row">Valor Mínimo de Saque:</th><td>R$ <input type="number" name="min_payout_amount" value="<?php echo esc_attr($minPayoutAmount); ?>" min="0" step="0.01" class="small-text"></td></tr>
                        <tr><th scope="row">Taxa da Plataforma (Base):</th><td><input type="number" name="platform_fee_percentage" value="<?php echo esc_attr($platformFeePercentage); ?>" min="0" max="100" step="0.1" class="small-text"> %<p class="description"><strong>Taxa base retida pela plataforma.</strong> Dinâmica por geo-index IBGE:</p>
                            <div style="display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap; font-size: 12px;">
                                <span style="background: #fee2e2; padding: 2px 8px; border-radius: 3px;">Vulnerável: 15%</span>
                                <span style="background: #fef3c7; padding: 2px 8px; border-radius: 3px;">Popular: 17%</span>
                                <span style="background: #e0f2fe; padding: 2px 8px; border-radius: 3px;">Médio: 20%</span>
                                <span style="background: #dbeafe; padding: 2px 8px; border-radius: 3px;">Alto: 22%</span>
                                <span style="background: #ede9fe; padding: 2px 8px; border-radius: 3px;">Premium: 25%</span>
                            </div>
                        </td></tr>
                    </table>
                </div>
            </div>

            <!-- Seção 6: Payouts Baseados em Feedback -->
            <div class="limpvix-card" style="margin-top: 20px; border-left: 4px solid #f0ad4e;">
                <div class="limpvix-card-header" style="background: #fff9f0;"><h3><span class="dashicons dashicons-star-filled"></span> ⭐ Payouts Baseados em Feedback do Cliente</h3><p><strong>REGRA CRÍTICA:</strong> Liberação de repasse ao profissional depende da avaliação do cliente após o serviço</p></div>
                <div class="limpvix-card-body">
                    <div style="background: #e8f4f8; padding: 15px; border-left: 4px solid #00a0d2; margin-bottom: 20px;">
                        <h4 style="margin-top: 0;">💡 Como Funciona o Repasse Inteligente</h4>
                        <p>Após a conclusão do serviço, o cliente recebe solicitação de feedback (avaliação de 1 a 5 estrelas).</p>
                        <ul style="margin: 10px 0;">
                            <li><strong>⭐⭐⭐⭐⭐ 5 Estrelas:</strong> Repasse instantâneo</li>
                            <li><strong>⭐⭐⭐⭐ 4 Estrelas:</strong> Repasse após 1 hora</li>
                            <li><strong>⭐⭐⭐ 3 Estrelas:</strong> Retido 24 horas</li>
                            <li><strong>⭐⭐ ou ⭐ Menos de 3:</strong> Retido 24h + liberação manual do admin</li>
                        </ul>
                    </div>
                    <table class="form-table">
                        <tr><th scope="row"><span style="color: #46b450; font-size: 18px;">⭐⭐⭐⭐⭐</span><br>5 Estrelas - Hold:</th><td><input type="number" name="payout_5stars_hold" value="<?php echo esc_attr($payout5StarsHoldHours); ?>" min="0" max="24" class="small-text"> horas<p class="description"><strong>Recomendado: 0 horas (instantâneo)</strong></p></td></tr>
                        <tr><th scope="row"><span style="color: #5b9dd9; font-size: 18px;">⭐⭐⭐⭐</span><br>4 Estrelas - Hold:</th><td><input type="number" name="payout_4stars_hold" value="<?php echo esc_attr($payout4StarsHoldHours); ?>" min="0" max="24" class="small-text"> horas<p class="description"><strong>Recomendado: 1 hora</strong></p></td></tr>
                        <tr><th scope="row"><span style="color: #f0ad4e; font-size: 18px;">⭐⭐⭐</span><br>3 Estrelas - Hold:</th><td><input type="number" name="payout_3stars_hold" value="<?php echo esc_attr($payout3StarsHoldHours); ?>" min="1" max="168" class="small-text"> horas<p class="description"><strong>Recomendado: 24 horas</strong></p></td></tr>
                        <tr><th scope="row"><span style="color: #dc3232; font-size: 18px;">⭐⭐ ou ⭐</span><br>Menos de 3 - Hold:</th><td><input type="number" name="payout_below3_hold" value="<?php echo esc_attr($payoutBelow3StarsHoldHours); ?>" min="24" max="720" class="small-text"> horas<p class="description"><strong>Recomendado: 24 horas + liberação manual do admin</strong></p></td></tr>
                        <tr><th scope="row">Permitir Cliente Reportar Motivo:</th><td><label><input type="checkbox" name="allow_client_report" value="1" <?php checked($allowClientReportLowRating); ?>> Cliente pode descrever motivo de avaliações abaixo de 4 estrelas</label></td></tr>
                    </table>
                </div>
            </div>

            <?php
            // ── Carregar valores das novas seções
            $fbWindowHours       = get_option('limpvix_feedback_window_hours', 48);
            $fbRequireEvidLt3    = get_option('limpvix_feedback_require_evidence_lt3', true);
            $fbAutoApproveDays   = get_option('limpvix_feedback_auto_approve_days', 7);
            $fbAllowEditHours    = get_option('limpvix_feedback_allow_edit_hours', 24);
            $ciGeofence          = get_option('limpvix_checkin_geofence_radius_m', 150);
            $ciTimeTol           = get_option('limpvix_checkin_time_tolerance_min', 15);
            $ciRequireGps        = get_option('limpvix_checkin_require_gps', true);
            $coRequirePhoto      = get_option('limpvix_checkout_require_photo', true);
            $epRequired          = get_option('limpvix_evidence_prof_required', true);
            $epMinPhotos         = get_option('limpvix_evidence_prof_min_photos', 2);
            $epMaxPhotos         = get_option('limpvix_evidence_prof_max_photos', 10);
            $epMaxMb             = get_option('limpvix_evidence_prof_max_mb', 20);
            $epAllowVideo        = get_option('limpvix_evidence_prof_allow_video', true);
            $ecRequired          = get_option('limpvix_evidence_client_required', false);
            $ecMinPhotos         = get_option('limpvix_evidence_client_min_photos', 0);
            $ecAllowDispute      = get_option('limpvix_evidence_client_allow_dispute', true);
            $ecDisputeHours      = get_option('limpvix_evidence_client_dispute_hours', 72);
            $resCapAuthorize     = get_option('limpvix_resolution_cap_authorize', 'manage_options');
            $resCapResolve       = get_option('limpvix_resolution_cap_resolve', 'limpvix_resolve_feedback');
            $resDeadline         = get_option('limpvix_resolution_deadline_hours', 48);
            $resApplyPenalty     = get_option('limpvix_resolution_apply_penalty', true);
            $payoutCapAuth       = get_option('limpvix_payout_cap_authorize', 'limpvix_authorize_payout');
            $payoutCapProcess    = get_option('limpvix_payout_cap_process', 'limpvix_process_payout');
            $capOptions = [
                'manage_options'              => 'Administrador (manage_options)',
                'limpvix_authorize_payout'    => 'Autorizar Payout (gerente estadual+)',
                'limpvix_process_payout'      => 'Processar Payout (financeiro)',
                'limpvix_resolve_feedback'    => 'Resolver Feedback (gerente regional+)',
                'limpvix_review_evidence'     => 'Revisar Evidência (gerente regional+)',
                'limpvix_manage_settings'     => 'Gerenciar Configurações (gerente nacional)',
            ];
            ?>

            <!-- SEÇÃO: Feedback do Cliente -->
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header"><h3>⭐ Feedback do Cliente</h3></div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr><th><label for="feedback_window_hours">Janela de Feedback</label></th><td><input type="number" id="feedback_window_hours" name="feedback_window_hours" value="<?php echo esc_attr($fbWindowHours); ?>" min="1" max="168" class="small-text"> horas<p class="description">Tempo após o término do serviço em que o cliente pode enviar feedback (padrão: 48h).</p></td></tr>
                        <tr><th>Evidência obrigatória (rating &lt; 3)</th><td><label><input type="checkbox" name="feedback_require_evidence_lt3" value="1" <?php checked($fbRequireEvidLt3); ?>> Exigir foto/evidência quando rating for menor que 3 estrelas</label></td></tr>
                        <tr><th><label for="feedback_auto_approve_days">Auto-aprovação após</label></th><td><input type="number" id="feedback_auto_approve_days" name="feedback_auto_approve_days" value="<?php echo esc_attr($fbAutoApproveDays); ?>" min="0" max="30" class="small-text"> dias<p class="description">0 = sem auto-aprovação.</p></td></tr>
                        <tr><th><label for="feedback_allow_edit_hours">Edição pelo cliente</label></th><td><input type="number" id="feedback_allow_edit_hours" name="feedback_allow_edit_hours" value="<?php echo esc_attr($fbAllowEditHours); ?>" min="0" max="72" class="small-text"> horas<p class="description">0 = não permite edição após envio.</p></td></tr>
                    </table>
                </div>
            </div>

            <!-- SEÇÃO: CheckIn / CheckOut -->
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header"><h3>📍 CheckIn / CheckOut do Profissional</h3></div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr><th><label for="checkin_geofence_radius_m">Raio do Geofence</label></th><td><input type="number" id="checkin_geofence_radius_m" name="checkin_geofence_radius_m" value="<?php echo esc_attr($ciGeofence); ?>" min="50" max="5000" class="small-text"> metros</td></tr>
                        <tr><th><label for="checkin_time_tolerance_min">Tolerância de Horário</label></th><td><input type="number" id="checkin_time_tolerance_min" name="checkin_time_tolerance_min" value="<?php echo esc_attr($ciTimeTol); ?>" min="0" max="120" class="small-text"> minutos</td></tr>
                        <tr><th>GPS obrigatório no Check-In</th><td><label><input type="checkbox" name="checkin_require_gps" value="1" <?php checked($ciRequireGps); ?>> Rejeitar check-in sem coordenadas GPS válidas</label></td></tr>
                        <tr><th>Foto obrigatória no Check-Out</th><td><label><input type="checkbox" name="checkout_require_photo" value="1" <?php checked($coRequirePhoto); ?>> Exigir ao menos 1 foto no checkout</label></td></tr>
                    </table>
                </div>
            </div>

            <!-- SEÇÃO: Evidências do Profissional -->
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header"><h3>📷 Evidências do Profissional</h3></div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr><th>Evidência obrigatória</th><td><label><input type="checkbox" name="evidence_prof_required" value="1" <?php checked($epRequired); ?>> Profissional deve enviar evidências antes de finalizar execução</label></td></tr>
                        <tr><th><label for="evidence_prof_min_photos">Mínimo de fotos</label></th><td><input type="number" id="evidence_prof_min_photos" name="evidence_prof_min_photos" value="<?php echo esc_attr($epMinPhotos); ?>" min="0" max="20" class="small-text"></td></tr>
                        <tr><th><label for="evidence_prof_max_photos">Máximo de fotos</label></th><td><input type="number" id="evidence_prof_max_photos" name="evidence_prof_max_photos" value="<?php echo esc_attr($epMaxPhotos); ?>" min="1" max="50" class="small-text"></td></tr>
                        <tr><th><label for="evidence_prof_max_mb">Tamanho máximo total</label></th><td><input type="number" id="evidence_prof_max_mb" name="evidence_prof_max_mb" value="<?php echo esc_attr($epMaxMb); ?>" min="1" max="200" class="small-text"> MB</td></tr>
                        <tr><th>Permitir vídeo</th><td><label><input type="checkbox" name="evidence_prof_allow_video" value="1" <?php checked($epAllowVideo); ?>> Aceitar upload de vídeo (MP4, mov) além de fotos</label></td></tr>
                    </table>
                </div>
            </div>

            <!-- SEÇÃO: Evidências do Cliente -->
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header"><h3>📸 Evidências do Cliente</h3></div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr><th>Evidência do cliente obrigatória</th><td><label><input type="checkbox" name="evidence_client_required" value="1" <?php checked($ecRequired); ?>> Cliente deve enviar fotos ao registrar reclamação</label></td></tr>
                        <tr><th><label for="evidence_client_min_photos">Mínimo de fotos (reclamação)</label></th><td><input type="number" id="evidence_client_min_photos" name="evidence_client_min_photos" value="<?php echo esc_attr($ecMinPhotos); ?>" min="0" max="10" class="small-text"></td></tr>
                        <tr><th>Permitir disputa via app</th><td><label><input type="checkbox" name="evidence_client_allow_dispute" value="1" <?php checked($ecAllowDispute); ?>> Cliente pode abrir disputa formal após feedback</label></td></tr>
                        <tr><th><label for="evidence_client_dispute_hours">Prazo para disputa</label></th><td><input type="number" id="evidence_client_dispute_hours" name="evidence_client_dispute_hours" value="<?php echo esc_attr($ecDisputeHours); ?>" min="1" max="720" class="small-text"> horas após o serviço</td></tr>
                    </table>
                </div>
            </div>

            <!-- SEÇÃO: Resolução de Feedbacks e Autorização de Pagamentos -->
            <div class="limpvix-card" style="margin-bottom: 20px; border-left: 4px solid #d97706;">
                <div class="limpvix-card-header"><h3>🔍 Resolução de Feedbacks e Autorização de Pagamentos</h3></div>
                <div class="limpvix-card-body">
                    <p style="color:#6b7280; margin-bottom: 16px;">Define quais roles da equipe podem <strong>resolver feedbacks bloqueantes</strong> e <strong>autorizar/processar pagamentos</strong>.</p>
                    <table class="form-table">
                        <tr><th><label for="resolution_cap_resolve">Quem pode resolver feedback</label></th><td><select id="resolution_cap_resolve" name="resolution_cap_resolve"><?php foreach ($capOptions as $cap => $label): ?><option value="<?php echo esc_attr($cap); ?>" <?php selected($resCapResolve, $cap); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><p class="description">Capability mínima para registrar resolução de feedback e liberar payout bloqueante.</p></td></tr>
                        <tr><th><label for="resolution_deadline_hours">Prazo para resolução</label></th><td><input type="number" id="resolution_deadline_hours" name="resolution_deadline_hours" value="<?php echo esc_attr($resDeadline); ?>" min="1" max="720" class="small-text"> horas<p class="description">SLA interno para resolver feedbacks bloqueantes antes de escalonar.</p></td></tr>
                        <tr><th>Aplicar penalidade de score</th><td><label><input type="checkbox" name="resolution_apply_penalty" value="1" <?php checked($resApplyPenalty); ?>> Aplicar penalidade no score do profissional conforme gravidade da resolução</label><p class="description">Grave: -1.50 pts | Médio: -0.75 pts | Leve: sem penalidade.</p></td></tr>
                        <tr><th style="border-top: 1px solid #e5e7eb; padding-top: 16px;"><label for="payout_cap_authorize">Quem pode <em>autorizar</em> payout</label></th><td style="border-top: 1px solid #e5e7eb; padding-top: 16px;"><select id="payout_cap_authorize" name="payout_cap_authorize"><?php foreach ($capOptions as $cap => $label): ?><option value="<?php echo esc_attr($cap); ?>" <?php selected($payoutCapAuth, $cap); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><p class="description">Pré-aprovação do payout (ex: gerente estadual). Passo 1 do fluxo dual.</p></td></tr>
                        <tr><th><label for="payout_cap_process">Quem pode <em>processar</em> payout</label></th><td><select id="payout_cap_process" name="payout_cap_process"><?php foreach ($capOptions as $cap => $label): ?><option value="<?php echo esc_attr($cap); ?>" <?php selected($payoutCapProcess, $cap); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><p class="description">Execução via EFI Bank PIX (ex: financeiro). Passo 2 do fluxo dual.</p></td></tr>
                    </table>
                    <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 6px; padding: 12px; margin-top: 16px;">
                        <strong>ℹ️ Como funciona o fluxo de autorização:</strong><br>
                        <ol style="margin: 8px 0 0 20px; font-size: 13px;">
                            <li><strong>Resolver feedback</strong> (se bloqueante): usuário com capability acima registra resolução + gravidade</li>
                            <li><strong>Autorizar payout</strong>: usuário com capability "autorizar" pré-aprova (status → <code>authorized</code>)</li>
                            <li><strong>Processar payout</strong>: usuário com capability "processar" dispara PIX via EFI Bank</li>
                        </ol>
                    </div>
                </div>
            </div>

            <p class="submit">
                <button type="submit" name="limpvix_save_profissionais_settings" class="button button-primary button-large">
                    💾 Salvar Configurações de Profissionais
                </button>
            </p>
        </form>
        <?php
    }

    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================

    private function calculateProfessionalsStats(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_professionals';

        // Verificar se tabela existe
        $tableExists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        )) === $table;

        $defaults = [
            'total' => 0,
            'verified' => 0,
            'pix_registered' => 0,
            'active' => 0,
            'avg_score' => 0,
        ];

        if (!$tableExists) {
            return $defaults;
        }

        // Total de profissionais
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        if ($total === 0) {
            return $defaults;
        }

        // Verificados (KYC aprovado)
        $verified = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_verified = 1");

        // PIX cadastrado (têm chave PIX)
        $pix_registered = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE pix_key IS NOT NULL AND pix_key != ''");

        // Ativos (score >= mínimo e verificados)
        $minScore = get_option('limpvix_prof_min_score_threshold', 70);
        $active = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE score >= %d AND is_verified = 1",
            $minScore
        ));

        // Score médio
        $avg_score = (float) $wpdb->get_var("SELECT AVG(score) FROM {$table}");
        if ($avg_score === null) {
            $avg_score = 0;
        }

        return [
            'total' => $total,
            'verified' => $verified,
            'pix_registered' => $pix_registered,
            'active' => $active,
            'avg_score' => $avg_score,
        ];
    }
}
