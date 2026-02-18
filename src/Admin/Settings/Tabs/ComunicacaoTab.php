<?php

namespace LimpVix\Admin\Settings\Tabs;

use LimpVix\Admin\Settings\NVoipSettings;

defined('ABSPATH') || exit;

class ComunicacaoTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'comunicacao'; }
    public function getLabel(): string { return 'Comunicacao'; }
    public function getIcon(): string { return '📡'; }

    public function handleSave(): void
    {
        // No POST handling for this tab
    }

    public function render(): void
    {
        // Buscar status dos providers
        $providers = $this->getCommunicationProvidersStatus();

        // Verificar se Twilio está configurado
        $twilioConfigured = !empty(get_option('limpvix_twilio_account_sid')) &&
                           !empty(get_option('limpvix_twilio_auth_token'));
        $twilioFromNumber = get_option('limpvix_twilio_from_number', '');

        // Verificar se NVoip está configurado
        $nvoipConfigured = $providers['nvoip']['connected'] ?? false;

        // Detectar provider ativo automaticamente
        // Lógica: Se apenas um está configurado, ele é o ativo
        // Se ambos estão configurados, usar a option 'limpvix_active_sms_provider'
        // Se nenhum está configurado, mostrar 'nenhum'
        if ($twilioConfigured && !$nvoipConfigured) {
            $activeProvider = 'twilio';
        } elseif ($nvoipConfigured && !$twilioConfigured) {
            $activeProvider = 'nvoip';
        } elseif ($twilioConfigured && $nvoipConfigured) {
            // Ambos configurados, usar preferência salva
            $activeProvider = get_option('limpvix_active_sms_provider', 'twilio');
        } else {
            // Nenhum configurado
            $activeProvider = 'nenhum';
        }
        ?>

        <!-- HERO CARD -->
        <div class="limpvix-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 20px; border: none;">
            <div class="limpvix-card-body" style="padding: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h2 style="color: white; margin: 0 0 5px 0; font-size: 22px;">📡 Comunicação - Dual Provider</h2>
                        <p style="color: #f0f0f0; margin: 0; font-size: 13px;">
                            Sistema com suporte a NVoip e Twilio para OTP, SMS e WhatsApp
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div style="background: rgba(255,255,255,0.2); padding: 12px 20px; border-radius: 6px; backdrop-filter: blur(10px);">
                            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9;">Provider Ativo</div>
                            <div style="font-size: 18px; font-weight: bold; margin-top: 2px;"><?php echo strtoupper($activeProvider); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 20px;">
                    <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 6px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 24px; font-weight: bold; margin-bottom: 3px;">2</div>
                        <div style="font-size: 11px; opacity: 0.9;">Providers Disponíveis</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 6px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 24px; font-weight: bold; margin-bottom: 3px;">7</div>
                        <div style="font-size: 11px; opacity: 0.9;">Fluxos Automáticos (C1-C3, P1-P3 + Check-in)</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 6px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 24px; font-weight: bold; margin-bottom: 3px;">✓</div>
                        <div style="font-size: 11px; opacity: 0.9;">Fallback Automático</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- STATUS DE PROVIDERS -->
        <div class="limpvix-card">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-admin-plugins"></span>
                    📡 Status dos Providers
                </h3>
                <p>Conexão com serviços de envio de mensagens</p>
            </div>
            <div class="limpvix-card-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;">
                    <!-- NVoip -->
                    <div style="background: #fff; border-left: 4px solid <?php echo $providers['nvoip']['connected'] ? '#10b981' : '#ef4444'; ?>; padding: 16px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); position: relative;">
                        <?php if ($activeProvider === 'nvoip'): ?>
                        <div style="position: absolute; top: 8px; right: 8px; background: #10b981; color: white; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 600;">ATIVO</div>
                        <?php endif; ?>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="font-size: 32px;">📱</div>
                            <div style="flex: 1;">
                                <div style="color: #6b7280; font-size: 12px; text-transform: uppercase; font-weight: 600;">NVoip</div>
                                <div style="font-size: 18px; font-weight: bold; color: #1f2937; margin-top: 4px;">
                                    <?php echo $providers['nvoip']['connected'] ? '✅ Conectado' : '❌ Não configurado'; ?>
                                </div>
                                <?php if ($providers['nvoip']['connected']): ?>
                                    <div style="color: #6b7280; font-size: 12px; margin-top: 6px;">
                                        📞 WhatsApp/SMS<br>
                                        🔐 OTP: <?php echo $providers['nvoip']['otp_enabled'] ? 'ON' : 'OFF'; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Twilio -->
                    <div style="background: #fff; border-left: 4px solid <?php echo $twilioConfigured ? '#10b981' : '#ef4444'; ?>; padding: 16px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); position: relative;">
                        <?php if ($activeProvider === 'twilio'): ?>
                        <div style="position: absolute; top: 8px; right: 8px; background: #10b981; color: white; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 600;">ATIVO</div>
                        <?php endif; ?>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="font-size: 32px;">📲</div>
                            <div style="flex: 1;">
                                <div style="color: #6b7280; font-size: 12px; text-transform: uppercase; font-weight: 600;">Twilio</div>
                                <div style="font-size: 18px; font-weight: bold; color: #1f2937; margin-top: 4px;">
                                    <?php echo $twilioConfigured ? '✅ Conectado' : '❌ Não configurado'; ?>
                                </div>
                                <?php if ($twilioConfigured): ?>
                                    <div style="color: #6b7280; font-size: 12px; margin-top: 6px;">
                                        📞 WhatsApp/SMS<br>
                                        🔐 OTP: ON
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sistema -->
                    <div style="background: #fff; border-left: 4px solid <?php echo $providers['system_active'] ? '#3b82f6' : '#9ca3af'; ?>; padding: 16px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="font-size: 32px;">⚙️</div>
                            <div style="flex: 1;">
                                <div style="color: #6b7280; font-size: 12px; text-transform: uppercase; font-weight: 600;">Sistema</div>
                                <div style="font-size: 18px; font-weight: bold; color: #1f2937; margin-top: 4px;">
                                    <?php echo $providers['system_active'] ? '✅ Ativo' : '⏸️ Pausado'; ?>
                                </div>
                                <div style="color: #6b7280; font-size: 12px; margin-top: 6px;">
                                    Staff: <?php echo $providers['staff_notifications'] ? 'ON' : 'OFF'; ?><br>
                                    Fallback: ON
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabela Comparativa -->
                <div style="margin-top: 25px;">
                    <h4 style="margin-bottom: 12px;">📊 Comparativo de Recursos</h4>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 200px;">Recurso</th>
                                <th style="text-align: center;">NVoip</th>
                                <th style="text-align: center;">Twilio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>OTP Verificação</strong></td>
                                <td style="text-align: center;"><?php echo $providers['nvoip']['connected'] ? '✅' : '❌'; ?></td>
                                <td style="text-align: center;"><?php echo $twilioConfigured ? '✅' : '❌'; ?></td>
                            </tr>
                            <tr>
                                <td><strong>SMS</strong></td>
                                <td style="text-align: center;">✅</td>
                                <td style="text-align: center;">✅</td>
                            </tr>
                            <tr>
                                <td><strong>WhatsApp</strong></td>
                                <td style="text-align: center;">✅</td>
                                <td style="text-align: center;">✅</td>
                            </tr>
                            <tr>
                                <td><strong>Fallback Automático</strong></td>
                                <td style="text-align: center;">✅</td>
                                <td style="text-align: center;">✅</td>
                            </tr>
                            <tr>
                                <td><strong>Custo Estimado</strong></td>
                                <td style="text-align: center;"><small>Consultar plano</small></td>
                                <td style="text-align: center;"><small>~R$ 0.30/SMS</small></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Links de Configuração -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px;">
                    <div style="padding: 12px; background: #f0f9ff; border-left: 4px solid #3b82f6; border-radius: 4px;">
                        <p style="margin: 0; color: #1e40af; font-size: 13px;">
                            ℹ️ <strong>Configurar NVoip:</strong> Acesse
                            <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=conexoes'); ?>">Conexões</a>
                        </p>
                    </div>
                    <div style="padding: 12px; background: #f0fdf4; border-left: 4px solid #10b981; border-radius: 4px;">
                        <p style="margin: 0; color: #065f46; font-size: 13px;">
                            ℹ️ <strong>Configurar Twilio:</strong> Acesse
                            <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=conexoes'); ?>">Conexões</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- FLUXOS AUTOMÁTICOS -->
        <div class="limpvix-card" style="margin-top: 20px;">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-update"></span>
                    🔄 Fluxos Automáticos Implementados
                </h3>
                <p>Sistema de mensagens automáticas com fallback inteligente (WhatsApp → SMS → Email)</p>
            </div>
            <div class="limpvix-card-body">
                <!-- Fluxos de Comunicação -->
                <h4 style="margin-top: 0;">📱 Fluxos de Comunicação (Configuráveis)</h4>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom: 25px;">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Fluxo</th>
                            <th>Descrição</th>
                            <th style="width: 120px;">Canal</th>
                            <th style="width: 150px;">Trigger</th>
                            <th style="width: 80px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>C1</strong></td>
                            <td>Feedback Cliente - Solicita avaliação do serviço</td>
                            <td><span class="limpvix-badge limpvix-badge-success">WhatsApp</span></td>
                            <td><small>D+1, D+3, D+7</small></td>
                            <td>✅</td>
                        </tr>
                        <tr>
                            <td><strong>C2</strong></td>
                            <td>Feedback Negativo - Bloqueado (atendimento humano)</td>
                            <td><span class="limpvix-badge limpvix-badge-warning">Manual</span></td>
                            <td><small>Feedback < 3★</small></td>
                            <td>✅</td>
                        </tr>
                        <tr>
                            <td><strong>C3</strong></td>
                            <td>Google Review - Convite para avaliar no Google</td>
                            <td><span class="limpvix-badge limpvix-badge-success">WhatsApp</span></td>
                            <td><small>Após 5⭐</small></td>
                            <td>✅</td>
                        </tr>
                        <tr>
                            <td><strong>P1</strong></td>
                            <td>Serviço Concluído - Notifica profissional</td>
                            <td><span class="limpvix-badge limpvix-badge-success">WhatsApp</span></td>
                            <td><small>Check-out</small></td>
                            <td>✅</td>
                        </tr>
                        <tr>
                            <td><strong>P2</strong></td>
                            <td>Pagamento Autorizado - Notifica profissional</td>
                            <td><span class="limpvix-badge limpvix-badge-success">WhatsApp</span></td>
                            <td><small>Payout approved</small></td>
                            <td>✅</td>
                        </tr>
                        <tr>
                            <td><strong>P3</strong></td>
                            <td>Pagamento em Análise - Notifica profissional</td>
                            <td><span class="limpvix-badge limpvix-badge-warning">SMS</span></td>
                            <td><small>Payout hold</small></td>
                            <td>✅</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Fluxos Operacionais -->
                <h4>⚙️ Fluxos Operacionais Automáticos (Event-Driven)</h4>
                <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 4px; margin-bottom: 15px;">
                    <h5 style="margin: 0 0 10px 0; color: #155724;">✅ GAP #3: Notificação ao Cliente no Check-in (Implementado)</h5>
                    <div style="font-size: 13px; color: #155724; line-height: 1.6;">
                        <strong>Trigger:</strong> Professional faz check-in<br>
                        <strong>Ação:</strong> Cliente recebe notificação automática<br>
                        <strong>Fallback:</strong> WhatsApp → SMS → Email<br>
                        <strong>Mensagem:</strong> "✅ Seu profissional chegou! Serviço em execução."<br>
                        <strong>Implementação:</strong> Event listener + CustomerNotifier service<br>
                        <strong>Commit:</strong> 28fb29a
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 300px;">Fluxo Operacional</th>
                            <th>Provider</th>
                            <th style="width: 120px;">Fallback</th>
                            <th style="width: 80px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="background: #d4edda;">
                            <td><strong>✅ Check-in Notification (GAP #3)</strong></td>
                            <td><?php echo strtoupper($activeProvider); ?> (WhatsApp → SMS → Email)</td>
                            <td>✅ Ativo</td>
                            <td>✅</td>
                        </tr>
                        <tr>
                            <td>OTP Verification (Professional Registration)</td>
                            <td><?php echo strtoupper($activeProvider); ?> (SMS/WhatsApp)</td>
                            <td>✅ Ativo</td>
                            <td>✅</td>
                        </tr>
                        <tr>
                            <td>Issue Reported Notification</td>
                            <td><?php echo strtoupper($activeProvider); ?> (WhatsApp)</td>
                            <td>✅ Ativo</td>
                            <td>⏳ Pendente</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Ações -->
                <p style="margin-top: 25px;">
                    <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=fluxos'); ?>" class="button button-primary">
                        📊 Gerenciar Fluxos →
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=templates'); ?>" class="button">
                        📝 Gerenciar Templates
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=conexoes'); ?>" class="button">
                        🔌 Configurar Providers
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    private function getCommunicationProvidersStatus(): array
    {
        // Get NVoip status
        $nvoipSettings = NVoipSettings::getSettings();
        $nvoipConnected = NVoipSettings::isConnected();

        return [
            'nvoip' => [
                'name' => 'NVoip OTP',
                'channel' => 'whatsapp_sms',
                'enabled' => !empty($nvoipSettings['enabled']),
                'configured' => $nvoipConnected,
                'connected' => $nvoipConnected,
                'from_number' => $nvoipSettings['default_number'] ?? '',
                'otp_enabled' => !empty($nvoipSettings['enable_otp']),
                'status' => 'active',
            ],
            'system_active' => get_option('limpvix_comm_active', true),
            'staff_notifications' => get_option('limpvix_notify_staff_enabled', true),
        ];
    }
}
