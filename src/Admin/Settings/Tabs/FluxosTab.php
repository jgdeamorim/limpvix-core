<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

class FluxosTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'fluxos'; }
    public function getLabel(): string { return 'Fluxos'; }
    public function getIcon(): string { return '🔄'; }

    public function handleSave(): void {}

    public function handleUpdateFlows(): void
    {
        if (!isset($_POST['limpvix_flows_nonce']) ||
            !wp_verify_nonce($_POST['limpvix_flows_nonce'], 'limpvix_update_flows')) {
            wp_die('Erro de segurança.');
        }
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }

        $enabledFlows = [];
        if (isset($_POST['enabled_flows']) && is_array($_POST['enabled_flows'])) {
            foreach ($_POST['enabled_flows'] as $flowId => $value) {
                $enabledFlows[sanitize_key($flowId)] = (bool) $value;
            }
        }
        update_option('limpvix_enabled_flows', $enabledFlows);

        if (isset($_POST['c1_timing']) && is_array($_POST['c1_timing'])) {
            update_option('limpvix_c1_timing', [
                'attempt1_hours' => (int) ($_POST['c1_timing']['attempt1_hours'] ?? 24),
                'attempt2_hours' => (int) ($_POST['c1_timing']['attempt2_hours'] ?? 48),
                'attempt3_hours' => (int) ($_POST['c1_timing']['attempt3_hours'] ?? 72),
            ]);
        }

        wp_redirect(add_query_arg(['page' => 'limpvix-settings', 'tab' => 'fluxos', 'updated' => 'true'], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        global $wpdb;
        $enabledFlows = get_option('limpvix_enabled_flows', ['c1'=>true,'c2'=>true,'c3'=>true,'p1'=>true,'p2'=>true,'p3'=>true]);
        $c1Timing = get_option('limpvix_c1_timing', ['attempt1_hours'=>24,'attempt2_hours'=>48,'attempt3_hours'=>72]);
        $d = $this->collectFlowData($wpdb, $enabledFlows);

        $this->renderStyles();
        $this->renderHeroCard($d);
        $this->renderCommunicationSection($enabledFlows, $c1Timing);
        $this->renderOverviewTable($d);

        // Individual flow sections
        foreach ($d['flows'] as $key => $flow) {
            $this->renderFlowSection($flow);
        }

        // Gaps summary
        $this->renderGapsSummary($d);
        echo '</div>';
    }

    // ═════════════════════════════════════════════════════════════════
    // DATA COLLECTION
    // ═════════════════════════════════════════════════════════════════

    private function collectFlowData(\wpdb $wpdb, array $enabledFlows): array
    {
        $d = [];

        // Communication
        $commEnabled = 0;
        foreach (['c1','c2','c3','p1','p2','p3'] as $f) {
            if (!empty($enabledFlows[$f])) $commEnabled++;
        }
        $d['comm_enabled'] = $commEnabled;

        // Feature flags
        $flags = get_option('limpvix_feature_flags', []);
        $d['flags'] = is_array($flags) ? $flags : [];

        // Seeded/config data counts
        $d['service_catalog'] = $this->tableCount($wpdb, 'service_catalog');
        $d['service_additionals'] = $this->tableCount($wpdb, 'service_additionals');
        $d['message_templates'] = $this->tableCount($wpdb, 'message_templates');
        $d['epi_catalog'] = $this->tableCount($wpdb, 'epi_catalog');
        $d['package_configs'] = $this->tableCount($wpdb, 'package_configs');
        $d['user_verifications'] = $this->tableCount($wpdb, 'user_verifications');

        // Build each flow with full metadata
        $d['flows'] = [];

        // ── Briefing ──
        $briefDist = $this->getStatusDist($wpdb, 'briefings', 'status');
        $d['flows']['briefing'] = [
            'title' => 'Briefing Pipeline',
            'desc' => 'Cliente solicita servico &rarr; etapas din&acirc;micas &rarr; pagamento &rarr; lock &rarr; gera contrato',
            'completeness' => 98,
            'sm_class' => 'LimpVix\\Domain\\Briefing\\BriefingStatus',
            'total' => array_sum($briefDist),
            'distribution' => $briefDist,
            'states' => [
                'draft'                       => ['label'=>'Draft',             'color'=>'#6b7280'],
                'in_progress'                 => ['label'=>'Em Andamento',     'color'=>'#3b82f6'],
                'pending_phone_verification'  => ['label'=>'Verif. Telefone',  'color'=>'#f59e0b'],
                'awaiting_payment'            => ['label'=>'Aguard. Pagto',    'color'=>'#f59e0b'],
                'paid'                        => ['label'=>'Pago',             'color'=>'#22c55e'],
                'locked'                      => ['label'=>'Locked',           'color'=>'#6b7280'],
            ],
            'transitions' => 'draft &rarr; in_progress &rarr; verif_phone &rarr; awaiting_payment &rarr; paid &rarr; locked',
            'gaps' => [],
            'insights' => $this->buildInsights($briefDist, [
                ['condition' => array_sum($briefDist) === 0, 'type'=>'info', 'text'=>'Nenhum briefing criado ainda. Flag briefing_enabled=' . (!empty($flags['briefing_enabled']) ? 'ON' : 'OFF')],
            ]),
            'related' => ['Schemas: ' . $d['service_catalog'] . ' servicos, ' . $d['service_additionals'] . ' adicionais, ' . $d['package_configs'] . ' pacotes'],
        ];

        // ── Contract ──
        $contDist = $this->getStatusDist($wpdb, 'contracts', 'status');
        $allocDist = $this->getStatusDist($wpdb, 'contracts', 'allocation_status');
        $totalCont = array_sum($contDist);
        $pendAlloc = $contDist['pending_allocation'] ?? 0;
        $d['flows']['contract'] = [
            'title' => 'Contract Lifecycle',
            'desc' => 'Contrato recorrente: briefing &rarr; aloca&ccedil;&atilde;o profissional &rarr; ativo &rarr; renova&ccedil;&atilde;o/cancelamento',
            'completeness' => 100,
            'sm_class' => 'LimpVix\\Domain\\Contract\\ValueObjects\\ContractStatus',
            'total' => $totalCont,
            'distribution' => $contDist,
            'states' => [
                'draft'               => ['label'=>'Draft',           'color'=>'#6b7280'],
                'pending_allocation'  => ['label'=>'Pend. Alocacao',  'color'=>'#f59e0b'],
                'confirmed'           => ['label'=>'Confirmado',      'color'=>'#3b82f6'],
                'active'              => ['label'=>'Ativo',           'color'=>'#22c55e'],
                'paused'              => ['label'=>'Pausado',         'color'=>'#f59e0b'],
                'completed'           => ['label'=>'Completo',        'color'=>'#059669'],
                'expired'             => ['label'=>'Expirado',        'color'=>'#6b7280'],
                'cancelled'           => ['label'=>'Cancelado',       'color'=>'#9ca3af'],
            ],
            'transitions' => 'draft &rarr; pending_allocation &rarr; confirmed &rarr; active &harr; paused | active &rarr; completed/cancelled/expired',
            'gaps' => [],
            'insights' => $this->buildInsights($contDist, [
                ['condition' => $totalCont > 0 && $pendAlloc > ($totalCont * 0.5), 'type'=>'warning', 'text'=>'Bottleneck: ' . $pendAlloc . '/' . $totalCont . ' contratos aguardam aloca&ccedil;&atilde;o de profissional (' . round($pendAlloc/$totalCont*100) . '%)'],
            ]),
            'related' => $allocDist ? ['Allocation status: ' . implode(', ', array_map(fn($k,$v) => "$k=$v", array_keys($allocDist), $allocDist))] : [],
        ];

        // ── Execution (Real-Time) ──
        $execDist = $this->getStatusDist($wpdb, 'executions', 'status');
        $checkIns = $this->tableCount($wpdb, 'check_ins');
        $checkOuts = $this->tableCount($wpdb, 'check_outs');
        $d['flows']['execution'] = [
            'title' => 'Execution (Real-Time)',
            'desc' => 'GPS check-in &rarr; evid&ecirc;ncias em tempo real &rarr; check-out com valida&ccedil;&atilde;o &rarr; fechamento',
            'completeness' => 99,
            'sm_class' => 'LimpVix\\Domain\\Execution\\Enums\\ExecutionStatusEnum',
            'total' => array_sum($execDist),
            'distribution' => $execDist,
            'states' => [
                'created'       => ['label'=>'Criado',       'color'=>'#6b7280'],
                'checked_in'    => ['label'=>'Check-in',     'color'=>'#3b82f6'],
                'in_execution'  => ['label'=>'Em Execucao',  'color'=>'#22c55e'],
                'checked_out'   => ['label'=>'Check-out',    'color'=>'#059669'],
                'closed'        => ['label'=>'Fechado',      'color'=>'#6b7280'],
            ],
            'transitions' => 'created &rarr; checked_in &rarr; in_execution &rarr; checked_out &rarr; closed',
            'gaps' => [
                ['severity'=>'low', 'text'=>'ReportIssue: falta valida&ccedil;&atilde;o de que reportedByUserId &eacute; cliente ou profissional (TODO)'],
            ],
            'insights' => $this->buildInsights($execDist, [
                ['condition' => array_sum($execDist) === 0, 'type'=>'info', 'text'=>'Pipeline vazio &mdash; nenhuma execu&ccedil;&atilde;o de servi&ccedil;o iniciada. Check-ins: ' . $checkIns . ', Check-outs: ' . $checkOuts],
                ['condition' => $pendAlloc > 0 && array_sum($execDist) === 0, 'type'=>'warning', 'text'=>'Contratos aguardando aloca&ccedil;&atilde;o bloqueiam gera&ccedil;&atilde;o de execu&ccedil;&otilde;es'],
            ]),
            'related' => ['EPI catalog: ' . $d['epi_catalog'] . ' itens'],
        ];

        // ── Contract Execution (Scheduling) ──
        $contExDist = $this->getStatusDist($wpdb, 'contract_executions', 'status');
        $schedDist = $this->getStatusDist($wpdb, 'schedules', 'status');
        $d['flows']['scheduling'] = [
            'title' => 'Scheduling Pipeline',
            'desc' => 'Agendamento de execu&ccedil;&otilde;es de contrato: aloca&ccedil;&atilde;o &rarr; agenda &rarr; execu&ccedil;&atilde;o &rarr; conclus&atilde;o',
            'completeness' => 98,
            'sm_class' => 'LimpVix\\Domain\\Execution\\ExecutionStatus',
            'total' => array_sum($contExDist) + array_sum($schedDist),
            'distribution' => array_merge($contExDist, $schedDist ? ['(schedules) ' . implode(', ', array_map(fn($k,$v) => "$k:$v", array_keys($schedDist), $schedDist)) => 0] : []),
            'states' => [
                'draft'        => ['label'=>'Draft',         'color'=>'#6b7280'],
                'scheduled'    => ['label'=>'Agendado',      'color'=>'#3b82f6'],
                'in_progress'  => ['label'=>'Em Progresso',  'color'=>'#22c55e'],
                'completed'    => ['label'=>'Completo',      'color'=>'#059669'],
                'cancelled'    => ['label'=>'Cancelado',     'color'=>'#9ca3af'],
                'no_show'      => ['label'=>'No-Show',       'color'=>'#ef4444'],
            ],
            'transitions' => 'draft &rarr; scheduled &rarr; in_progress &rarr; completed | cancelled | no_show',
            'gaps' => [],
            'insights' => $this->buildInsights($contExDist, [
                ['condition' => array_sum($contExDist) === 0 && array_sum($schedDist) === 0, 'type'=>'info', 'text'=>'Nenhum agendamento criado. Depende de contratos ativos com profissional alocado'],
            ]),
            'related' => ['Schedules: ' . array_sum($schedDist) . ', Allocations: ' . $this->tableCount($wpdb, 'professional_allocations')],
        ];

        // ── Order ──
        $orderDist = $this->getStatusDist($wpdb, 'orders', 'status');
        $finDist = $this->getStatusDist($wpdb, 'orders', 'financial_status');
        $d['flows']['order'] = [
            'title' => 'Order Pipeline',
            'desc' => 'Pedido de servi&ccedil;o: cria&ccedil;&atilde;o &rarr; confirma&ccedil;&atilde;o &rarr; execu&ccedil;&atilde;o &rarr; fechamento financeiro',
            'completeness' => 98,
            'sm_class' => 'LimpVix\\Domain\\Order\\Enums\\OrderStatusEnum',
            'total' => array_sum($orderDist),
            'distribution' => $orderDist,
            'states' => [
                'created'       => ['label'=>'Criado',       'color'=>'#6b7280'],
                'confirmed'     => ['label'=>'Confirmado',   'color'=>'#3b82f6'],
                'scheduled'     => ['label'=>'Agendado',     'color'=>'#3b82f6'],
                'in_execution'  => ['label'=>'Em Execucao',  'color'=>'#22c55e'],
                'completed'     => ['label'=>'Completo',     'color'=>'#059669'],
                'closed'        => ['label'=>'Fechado',      'color'=>'#6b7280'],
                'cancelled'     => ['label'=>'Cancelado',    'color'=>'#9ca3af'],
                'disputed'      => ['label'=>'Disputado',    'color'=>'#ef4444'],
            ],
            'transitions' => 'created &rarr; confirmed &rarr; scheduled &rarr; in_execution &rarr; completed &rarr; closed',
            'gaps' => [],
            'insights' => $this->buildInsights($orderDist, [
                ['condition' => !empty($finDist), 'type'=>'info', 'text'=>'Financial status: ' . implode(', ', array_map(fn($k,$v) => "$k=$v", array_keys($finDist), $finDist))],
            ]),
            'related' => ['Gateway: EFI Bank PIX (cob + webhook)'],
        ];

        // ── Financial/Payouts ──
        $payDist = $this->getStatusDist($wpdb, 'payouts', 'status');
        $recurDist = $this->getStatusDist($wpdb, 'recurring_payments', 'status');
        $ledger = $this->tableCount($wpdb, 'financial_ledger');
        $d['flows']['financial'] = [
            'title' => 'Payouts &amp; Financeiro',
            'desc' => 'Repasses aos profissionais: aprova&ccedil;&atilde;o &rarr; processamento EFI Bank PIX &rarr; pagamento &rarr; reconcilia&ccedil;&atilde;o',
            'completeness' => 100,
            'sm_class' => 'LimpVix\\Domain\\Finance\\Enums\\FinancialStatusEnum',
            'total' => array_sum($payDist),
            'distribution' => $payDist,
            'states' => [
                'pending'     => ['label'=>'Pendente',     'color'=>'#6b7280'],
                'approved'    => ['label'=>'Aprovado',     'color'=>'#3b82f6'],
                'processing'  => ['label'=>'Processando',  'color'=>'#f59e0b'],
                'completed'   => ['label'=>'Pago',         'color'=>'#22c55e'],
                'on_hold'     => ['label'=>'Retido',       'color'=>'#f59e0b'],
                'failed'      => ['label'=>'Falhou',       'color'=>'#ef4444'],
                'cancelled'   => ['label'=>'Cancelado',    'color'=>'#9ca3af'],
            ],
            'transitions' => 'pending &rarr; approved &rarr; processing &rarr; completed | failed &rarr; retry | on_hold (feedback)',
            'gaps' => [],
            'insights' => $this->buildInsights($payDist, [
                ['condition' => ($payDist['failed'] ?? 0) > 0, 'type'=>'warning', 'text'=>($payDist['failed'] ?? 0) . ' payout(s) falharam &mdash; verificar logs de gateway EFI/PIX'],
                ['condition' => ($payDist['on_hold'] ?? 0) > 0, 'type'=>'info', 'text'=>($payDist['on_hold'] ?? 0) . ' payout(s) retidos por feedback pendente (liberados ap&oacute;s 48h ou aprova&ccedil;&atilde;o)'],
                ['condition' => !empty($recurDist), 'type'=>'info', 'text'=>'Pagamentos recorrentes: ' . implode(', ', array_map(fn($k,$v) => "$k=$v", array_keys($recurDist), $recurDist))],
            ]),
            'related' => ['Ledger: ' . $ledger . ' registros, Recurring: ' . array_sum($recurDist)],
        ];

        // ── Feedback ──
        $fbDist = $this->getStatusDist($wpdb, 'feedback', 'validation_status');
        $disputes = $this->tableCount($wpdb, 'feedback_disputes');
        $d['flows']['feedback'] = [
            'title' => 'Feedback &amp; Avalia&ccedil;&atilde;o',
            'desc' => 'Cliente avalia servi&ccedil;o &rarr; valida&ccedil;&atilde;o admin &rarr; impacto no score do profissional e libera&ccedil;&atilde;o de payout',
            'completeness' => 100,
            'sm_class' => 'LimpVix\\Domain\\Feedback\\Feedback',
            'total' => array_sum($fbDist),
            'distribution' => $fbDist,
            'states' => [
                'pending'   => ['label'=>'Pendente',   'color'=>'#f59e0b'],
                'approved'  => ['label'=>'Aprovado',   'color'=>'#22c55e'],
                'rejected'  => ['label'=>'Rejeitado',  'color'=>'#ef4444'],
            ],
            'transitions' => 'pending &rarr; approved/rejected | rating &le; 2 ret&eacute;m payout 48h',
            'gaps' => [],
            'insights' => $this->buildInsights($fbDist, [
                ['condition' => ($fbDist['pending'] ?? 0) > 0, 'type'=>'warning', 'text'=>($fbDist['pending'] ?? 0) . ' feedback(s) aguardando valida&ccedil;&atilde;o admin'],
                ['condition' => ($fbDist['rejected'] ?? 0) > 0, 'type'=>'info', 'text'=>($fbDist['rejected'] ?? 0) . ' feedback(s) rejeitados pelo admin'],
            ]),
            'related' => ['Disputas: ' . $disputes],
        ];

        // ── Professional ──
        $profDist = $this->getProfessionalDistribution($wpdb);
        $docs = $this->getStatusDist($wpdb, 'professional_documents', 'status');
        $verif = $this->tableCount($wpdb, 'professional_verification');
        $skills = $this->tableCount($wpdb, 'professional_skills');
        $avail = $this->tableCount($wpdb, 'professional_availability');
        $d['flows']['professional'] = [
            'title' => 'Professional Lifecycle',
            'desc' => 'Cadastro &rarr; upload documentos &rarr; KYC biom&eacute;trico &rarr; background check &rarr; ativa&ccedil;&atilde;o &rarr; monitoramento',
            'completeness' => 85,
            'sm_class' => 'LimpVix\\Domain\\Verification\\Enums\\ProfessionalStatus',
            'total' => array_sum($profDist),
            'distribution' => $profDist,
            'states' => [
                'active/verified'    => ['label'=>'Ativo Verificado',  'color'=>'#22c55e'],
                'active/unverified'  => ['label'=>'Ativo N&atilde;o Verif.',   'color'=>'#f59e0b'],
                'pending/unverified' => ['label'=>'Pendente',          'color'=>'#6b7280'],
                'suspended/verified' => ['label'=>'Suspenso Verif.',   'color'=>'#ef4444'],
                'suspended/unverified'=>['label'=>'Suspenso',          'color'=>'#ef4444'],
            ],
            'transitions' => 'pending &rarr; active (unverified) &rarr; active (verified) | suspended',
            'gaps' => [
                ['severity'=>'medium', 'text'=>'PPID KYC: provider REAL implementado + credenciais criptografadas &mdash; aguardando credenciais produ&ccedil;&atilde;o para ativar'],
                ['severity'=>'high', 'text'=>'ExatoBackgroundProvider &eacute; STUB &mdash; aguardando contrata&ccedil;&atilde;o Exato Digital'],
            ],
            'insights' => $this->buildInsights($profDist, [
                ['condition' => ($profDist['active/unverified'] ?? 0) > 0, 'type'=>'warning', 'text'=>($profDist['active/unverified'] ?? 0) . ' profissional(is) ativo(s) sem verifica&ccedil;&atilde;o &mdash; KYC necess&aacute;rio'],
            ]),
            'related' => ['Documentos: ' . array_sum($docs) . ', Verifica&ccedil;&otilde;es: ' . $verif . ', Skills: ' . $skills . ', Disponibilidade: ' . $avail],
        ];

        // ── Document/KYC ──
        $d['flows']['document'] = [
            'title' => 'Documentos &amp; KYC',
            'desc' => 'Upload de documentos &rarr; revis&atilde;o admin &rarr; aprova&ccedil;&atilde;o/rejei&ccedil;&atilde;o &rarr; expira&ccedil;&atilde;o autom&aacute;tica',
            'completeness' => 75,
            'sm_class' => 'LimpVix\\Domain\\Professional\\ValueObjects\\DocumentStatus',
            'total' => array_sum($docs),
            'distribution' => $docs,
            'states' => [
                'pending'   => ['label'=>'Pendente',   'color'=>'#f59e0b'],
                'approved'  => ['label'=>'Aprovado',   'color'=>'#22c55e'],
                'rejected'  => ['label'=>'Rejeitado',  'color'=>'#ef4444'],
                'expired'   => ['label'=>'Expirado',   'color'=>'#6b7280'],
            ],
            'transitions' => 'pending &rarr; approved/rejected | approved &rarr; expired | rejected &rarr; pending (re-upload)',
            'gaps' => [
                ['severity'=>'medium', 'text'=>'PPID KYC real implementado (OCR + Liveness + FaceMatch) &mdash; aguardando credenciais produ&ccedil;&atilde;o'],
                ['severity'=>'high', 'text'=>'Background check (Exato Digital) &eacute; STUB &mdash; aguardando contrata&ccedil;&atilde;o'],
            ],
            'insights' => $this->buildInsights($docs, [
                ['condition' => array_sum($docs) === 0, 'type'=>'info', 'text'=>'Nenhum documento enviado por profissionais ainda'],
            ]),
            'related' => ['User verifications: ' . $d['user_verifications']],
        ];

        // ── Offers ──
        $offerDist = $this->getStatusDist($wpdb, 'contract_offers', 'status');
        $d['flows']['offer'] = [
            'title' => 'Ofertas a Profissionais',
            'desc' => 'Oferta auto-enviada ao profissional &rarr; aceitar/rejeitar &rarr; aloca&ccedil;&atilde;o no contrato (first-to-accept)',
            'completeness' => 100,
            'sm_class' => 'LimpVix\\Domain\\Contract\\ContractOffer',
            'total' => array_sum($offerDist),
            'distribution' => $offerDist,
            'states' => [
                'pending'   => ['label'=>'Pendente',  'color'=>'#f59e0b'],
                'accepted'  => ['label'=>'Aceita',    'color'=>'#22c55e'],
                'rejected'  => ['label'=>'Rejeitada', 'color'=>'#ef4444'],
                'expired'   => ['label'=>'Expirada',  'color'=>'#6b7280'],
            ],
            'transitions' => 'pending &rarr; accepted/rejected/expired | first-to-accept wins',
            'gaps' => [],
            'insights' => $this->buildInsights($offerDist, [
                ['condition' => ($offerDist['pending'] ?? 0) > 0, 'type'=>'info', 'text'=>($offerDist['pending'] ?? 0) . ' oferta(s) aguardando resposta de profissional. Flag auto_send_offers=' . (!empty($flags['auto_send_offers']) ? 'ON' : 'OFF')],
            ]),
            'related' => [],
        ];

        // ── Message Delivery ──
        $msgDist = $this->getStatusDist($wpdb, 'message_queue', 'status');
        $msgLog = $this->tableCount($wpdb, 'message_log');
        $d['flows']['message'] = [
            'title' => 'Comunica&ccedil;&atilde;o &amp; Mensagens',
            'desc' => 'Fila de mensagens: template &rarr; envio via provider &rarr; entrega &rarr; confirma&ccedil;&atilde;o | retry 3x',
            'completeness' => 95,
            'sm_class' => 'LimpVix\\Domain\\Communication\\MessageDelivery',
            'total' => array_sum($msgDist),
            'distribution' => $msgDist,
            'states' => [
                'pending'    => ['label'=>'Pendente',  'color'=>'#6b7280'],
                'sent'       => ['label'=>'Enviado',   'color'=>'#3b82f6'],
                'delivered'  => ['label'=>'Entregue',  'color'=>'#22c55e'],
                'read'       => ['label'=>'Lido',      'color'=>'#059669'],
                'failed'     => ['label'=>'Falhou',    'color'=>'#ef4444'],
            ],
            'transitions' => 'pending &rarr; sent &rarr; delivered &rarr; read | failed (retry 3x) | push via FCM',
            'gaps' => [
                ['severity'=>'low', 'text'=>'Flag notifications=OFF &mdash; ativar em Settings para habilitar envio (providers SMS + WhatsApp + Push FCM prontos)'],
            ],
            'insights' => $this->buildInsights($msgDist, [
                ['condition' => true, 'type'=>'info', 'text'=>'Templates cadastrados: ' . $d['message_templates'] . ', Logs: ' . $msgLog . ', Flag notifications=' . (!empty($flags['notifications']) ? 'ON' : 'OFF')],
            ]),
            'related' => [],
        ];

        // Summary stats
        $d['total_gaps'] = 0;
        $d['total_gaps_high'] = 0;
        foreach ($d['flows'] as $f) {
            $d['total_gaps'] += count($f['gaps']);
            $d['total_gaps_high'] += count(array_filter($f['gaps'], fn($g) => $g['severity'] === 'high'));
        }
        $d['avg_completeness'] = count($d['flows']) > 0 ? round(array_sum(array_column($d['flows'], 'completeness')) / count($d['flows'])) : 0;

        return $d;
    }

    private function buildInsights(array $dist, array $rules): array
    {
        $result = [];
        foreach ($rules as $r) {
            if ($r['condition']) {
                $result[] = ['type' => $r['type'], 'text' => $r['text']];
            }
        }
        return $result;
    }

    private function tableCount(\wpdb $wpdb, string $suffix): int
    {
        $table = $wpdb->prefix . 'limpvix_' . $suffix;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        return $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") : 0;
    }

    private function getStatusDist(\wpdb $wpdb, string $suffix, string $col): array
    {
        $table = $wpdb->prefix . 'limpvix_' . $suffix;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        if (!$exists) return [];
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
        if (!in_array($col, $cols, true)) return [];
        $rows = $wpdb->get_results("SELECT `{$col}` as v, COUNT(*) as c FROM `{$table}` GROUP BY `{$col}`", ARRAY_A);
        $d = [];
        foreach ($rows as $r) { $d[$r['v'] ?? '(null)'] = (int) $r['c']; }
        return $d;
    }

    private function getProfessionalDistribution(\wpdb $wpdb): array
    {
        $table = $wpdb->prefix . 'limpvix_professionals';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        if (!$exists) return [];
        $rows = $wpdb->get_results(
            "SELECT CONCAT(status, '/', CASE WHEN is_verified=1 THEN 'verified' ELSE 'unverified' END) as v, COUNT(*) as c FROM `{$table}` GROUP BY status, is_verified",
            ARRAY_A
        );
        $d = [];
        foreach ($rows as $r) { $d[$r['v']] = (int) $r['c']; }
        return $d;
    }

    // ═════════════════════════════════════════════════════════════════
    // STYLES
    // ═════════════════════════════════════════════════════════════════

    private function renderStyles(): void
    {
        ?>
        <style>
            .lvx-flx { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

            /* Hero */
            .lvx-flx-hero { background: linear-gradient(135deg, #1E7A66 0%, #2D9B83 50%, #3BB59A 100%); color: #fff; border-radius: 12px; padding: 28px; margin-bottom: 24px; }
            .lvx-flx-hero h1 { color: #fff; margin: 0 0 6px; font-size: 24px; font-weight: 700; }
            .lvx-flx-hero .sub { color: #c8ede5; margin: 0; font-size: 13px; }
            .lvx-flx-qgrid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-top: 20px; }
            .lvx-flx-qs { background: rgba(255,255,255,.13); padding: 14px 8px; border-radius: 8px; text-align: center; }
            .lvx-flx-qs .num { font-size: 20px; font-weight: 700; }
            .lvx-flx-qs .lbl { font-size: 10px; opacity: .85; margin-top: 2px; }

            /* Section card */
            .lvx-flx-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 20px; overflow: hidden; }
            .lvx-flx-card-head { padding: 16px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
            .lvx-flx-card-head h3 { margin: 0; font-size: 15px; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 8px; }
            .lvx-flx-card-body { padding: 16px 20px; }

            /* Completeness badge */
            .lvx-flx-pct { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; }
            .lvx-flx-pct-100 { background: #d1fae5; color: #065f46; }
            .lvx-flx-pct-high { background: #dbeafe; color: #1e40af; }
            .lvx-flx-pct-med { background: #fef3c7; color: #92400e; }
            .lvx-flx-pct-low { background: #fee2e2; color: #991b1b; }

            .lvx-flx-cnt { background: #f3f4f6; color: #374151; font-size: 11px; padding: 3px 10px; border-radius: 99px; font-weight: 600; }

            /* Pipeline */
            .lvx-flx-pipeline { display: flex; align-items: flex-start; gap: 0; margin: 16px 0; flex-wrap: wrap; }
            .lvx-flx-node { display: flex; flex-direction: column; align-items: center; min-width: 90px; }
            .lvx-flx-box { padding: 7px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; text-align: center; min-width: 75px; border: 1.5px solid; transition: all .2s; }
            .lvx-flx-box-empty { border-color: #e5e7eb; background: #fafafa; color: #c0c4cc; border-style: dashed; }
            .lvx-flx-box-filled { background: #fff; }
            .lvx-flx-node-cnt { font-size: 14px; font-weight: 800; margin-top: 3px; color: #1f2937; }
            .lvx-flx-arrow { font-size: 16px; color: #d1d5db; margin: 8px 2px 0; flex-shrink: 0; }

            /* Distribution bar */
            .lvx-flx-bar { height: 6px; background: #e5e7eb; border-radius: 99px; overflow: hidden; display: flex; margin-top: 4px; }
            .lvx-flx-bar-seg { height: 100%; }

            /* Pills */
            .lvx-flx-dist { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
            .lvx-flx-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #f3f4f6; color: #374151; }
            .lvx-flx-pill .dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

            /* Empty state */
            .lvx-flx-empty { text-align: center; padding: 20px; color: #9ca3af; font-size: 13px; }

            /* Insights/notices */
            .lvx-flx-insight { padding: 8px 14px; border-radius: 6px; margin-top: 8px; font-size: 12px; display: flex; align-items: flex-start; gap: 6px; }
            .lvx-flx-insight-info { background: #eff6ff; border-left: 3px solid #3b82f6; color: #1e40af; }
            .lvx-flx-insight-warning { background: #fffbeb; border-left: 3px solid #f59e0b; color: #92400e; }

            /* Gaps */
            .lvx-flx-gaps { margin-top: 10px; }
            .lvx-flx-gap { padding: 6px 12px; border-radius: 4px; font-size: 12px; margin-top: 4px; display: flex; align-items: flex-start; gap: 6px; }
            .lvx-flx-gap-high { background: #fef2f2; border-left: 3px solid #ef4444; color: #991b1b; }
            .lvx-flx-gap-medium { background: #fffbeb; border-left: 3px solid #f59e0b; color: #92400e; }
            .lvx-flx-gap-low { background: #f0fdf4; border-left: 3px solid #22c55e; color: #166534; }

            /* Related info */
            .lvx-flx-related { font-size: 11px; color: #9ca3af; margin-top: 8px; }

            /* Table */
            .lvx-flx-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
            .lvx-flx-tbl th { background: #f9fafb; padding: 8px 12px; text-align: left; font-weight: 600; color: #374151; border-bottom: 2px solid #e5e7eb; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
            .lvx-flx-tbl td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; color: #4b5563; }

            /* Toggles */
            .limpvix-toggle { position: relative; display: inline-block; width: 50px; height: 24px; }
            .limpvix-toggle input { opacity: 0; width: 0; height: 0; }
            .limpvix-toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 24px; }
            .limpvix-toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
            .limpvix-toggle input:checked + .limpvix-toggle-slider { background-color: #2D9B83; }
            .limpvix-toggle input:checked + .limpvix-toggle-slider:before { transform: translateX(26px); }

            @media (max-width: 1200px) { .lvx-flx-qgrid { grid-template-columns: repeat(3, 1fr); } }
        </style>
        <?php
    }

    // ═════════════════════════════════════════════════════════════════
    // HERO
    // ═════════════════════════════════════════════════════════════════

    private function renderHeroCard(array $d): void
    {
        $totalEntities = 0;
        foreach ($d['flows'] as $f) $totalEntities += $f['total'];
        ?>
        <div class="lvx-flx">
        <div class="lvx-flx-hero">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h1>&#9881; Fluxos Operacionais</h1>
                    <p class="sub">State machines, pipelines, gaps e dados em tempo real &bull; <?php echo count($d['flows']); ?> fluxos monitorados</p>
                </div>
                <div style="display:flex;gap:16px;">
                    <div style="background:rgba(255,255,255,.18);padding:12px 20px;border-radius:8px;text-align:center;">
                        <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;opacity:.85;">Completude</div>
                        <div style="font-size:28px;font-weight:800;"><?php echo $d['avg_completeness']; ?>%</div>
                    </div>
                    <div style="background:rgba(255,255,255,.18);padding:12px 20px;border-radius:8px;text-align:center;">
                        <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;opacity:.85;">Gaps</div>
                        <div style="font-size:28px;font-weight:800;"><?php echo $d['total_gaps']; ?></div>
                    </div>
                </div>
            </div>
            <div class="lvx-flx-qgrid">
                <?php
                $qstats = [
                    ['Entidades', $totalEntities],
                    ['Contratos', $d['flows']['contract']['total'] ?? 0],
                    ['Payouts', $d['flows']['financial']['total'] ?? 0],
                    ['Profissionais', $d['flows']['professional']['total'] ?? 0],
                    ['Comunic.', $d['comm_enabled'] . '/6'],
                ];
                foreach ($qstats as [$lbl, $val]): ?>
                    <div class="lvx-flx-qs">
                        <div class="num"><?php echo $val; ?></div>
                        <div class="lbl"><?php echo $lbl; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    // ═════════════════════════════════════════════════════════════════
    // COMMUNICATION (with form)
    // ═════════════════════════════════════════════════════════════════

    private function renderCommunicationSection(array $enabledFlows, array $c1Timing): void
    {
        $fluxos = $this->getFluxosDefinition();
        ?>
        <div class="lvx-flx-card">
            <div class="lvx-flx-card-head">
                <h3>&#128225; Fluxos de Comunica&ccedil;&atilde;o Autom&aacute;tica</h3>
                <span class="lvx-flx-cnt">C1-C3 + P1-P3</span>
            </div>
            <div class="lvx-flx-card-body">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('limpvix_update_flows', 'limpvix_flows_nonce'); ?>
                    <input type="hidden" name="action" value="limpvix_update_flows">

                    <h4 style="margin-top:0;">&#128241; Fluxos de Clientes</h4>
                    <?php $this->renderFlowTable($fluxos['client'], $enabledFlows); ?>

                    <div style="background:#f0fdf4;padding:14px;border-radius:8px;border:1px solid #bbf7d0;margin:16px 0;">
                        <h4 style="margin:0 0 8px;font-size:13px;color:#166534;">&#9202; Timing &mdash; C1 (Tentativas)</h4>
                        <div style="display:flex;gap:16px;flex-wrap:wrap;">
                            <label style="font-size:12px;">1&ordf;: <input type="number" name="c1_timing[attempt1_hours]" value="<?php echo esc_attr($c1Timing['attempt1_hours']); ?>" min="0" max="48" class="small-text">h</label>
                            <label style="font-size:12px;">2&ordf;: <input type="number" name="c1_timing[attempt2_hours]" value="<?php echo esc_attr($c1Timing['attempt2_hours']); ?>" min="0" max="96" class="small-text">h</label>
                            <label style="font-size:12px;">3&ordf;: <input type="number" name="c1_timing[attempt3_hours]" value="<?php echo esc_attr($c1Timing['attempt3_hours']); ?>" min="0" max="168" class="small-text">h</label>
                        </div>
                    </div>

                    <h4>&#128119; Fluxos de Equipe</h4>
                    <?php $this->renderFlowTable($fluxos['staff'], $enabledFlows); ?>

                    <p class="submit">
                        <button type="submit" class="button button-primary" style="background:#2D9B83;border-color:#1E7A66;">&#128190; Salvar</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    private function renderFlowTable(array $flows, array $enabled): void
    {
        ?>
        <table class="lvx-flx-tbl" style="margin-bottom:12px;">
            <thead><tr><th style="width:60px;">Ativo</th><th style="width:50px;">ID</th><th>Descri&ccedil;&atilde;o</th><th style="width:90px;">Canal</th><th style="width:110px;">Trigger</th></tr></thead>
            <tbody>
            <?php foreach ($flows as $id => $f): ?>
                <tr>
                    <td><label class="limpvix-toggle"><input type="checkbox" name="enabled_flows[<?php echo esc_attr($id); ?>]" value="1" <?php checked(!empty($enabled[$id])); ?>><span class="limpvix-toggle-slider"></span></label></td>
                    <td><strong><?php echo esc_html(strtoupper($id)); ?></strong></td>
                    <td><strong><?php echo esc_html($f['name']); ?></strong><br><small style="color:#6b7280;"><?php echo esc_html($f['description']); ?></small></td>
                    <td><?php echo $f['channel'] === 'whatsapp' ? '<span class="lvx-flx-pill" style="background:#d1fae5;color:#065f46;">WhatsApp</span>' : '<span class="lvx-flx-pill" style="background:#fef3c7;color:#92400e;">SMS</span>'; ?></td>
                    <td><small><?php echo esc_html($f['trigger']); ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ═════════════════════════════════════════════════════════════════
    // OVERVIEW TABLE
    // ═════════════════════════════════════════════════════════════════

    private function renderOverviewTable(array $d): void
    {
        ?>
        <div class="lvx-flx-card">
            <div class="lvx-flx-card-head">
                <h3>&#9881; Vis&atilde;o Geral dos Fluxos</h3>
                <span class="lvx-flx-cnt"><?php echo count($d['flows']); ?> fluxos &bull; <?php echo $d['total_gaps']; ?> gaps</span>
            </div>
            <div class="lvx-flx-card-body">
                <table class="lvx-flx-tbl">
                    <thead><tr><th style="width:36px;">SM</th><th>Fluxo</th><th style="width:90px;text-align:center;">Completude</th><th style="width:80px;text-align:right;">Registros</th><th style="width:70px;text-align:center;">Gaps</th><th style="width:120px;">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['flows'] as $key => $f):
                        $smLoaded = class_exists($f['sm_class']);
                        $pct = $f['completeness'];
                        $pctClass = $pct >= 100 ? 'lvx-flx-pct-100' : ($pct >= 90 ? 'lvx-flx-pct-high' : ($pct >= 70 ? 'lvx-flx-pct-med' : 'lvx-flx-pct-low'));
                        $gapCount = count($f['gaps']);
                        $highGaps = count(array_filter($f['gaps'], fn($g) => $g['severity'] === 'high'));
                    ?>
                        <tr>
                            <td><?php echo $smLoaded ? '<span style="color:#059669;">&#10003;</span>' : '<span style="color:#dc2626;">&#10007;</span>'; ?></td>
                            <td><strong><?php echo $f['title']; ?></strong></td>
                            <td style="text-align:center;"><span class="lvx-flx-pct <?php echo $pctClass; ?>"><?php echo $pct; ?>%</span></td>
                            <td style="text-align:right;font-weight:600;<?php echo $f['total'] > 0 ? 'color:#1f2937;' : 'color:#d1d5db;'; ?>"><?php echo number_format($f['total']); ?></td>
                            <td style="text-align:center;"><?php
                                if ($gapCount === 0) echo '<span style="color:#059669;">0</span>';
                                elseif ($highGaps > 0) echo '<span style="color:#dc2626;font-weight:700;">' . $gapCount . '</span>';
                                else echo '<span style="color:#d97706;font-weight:600;">' . $gapCount . '</span>';
                            ?></td>
                            <td><?php
                                if ($pct >= 100 && $f['total'] > 0) echo '<span class="lvx-flx-pct lvx-flx-pct-100">OPERACIONAL</span>';
                                elseif ($pct >= 100) echo '<span class="lvx-flx-pct lvx-flx-pct-high">PRONTO</span>';
                                elseif ($highGaps > 0) echo '<span class="lvx-flx-pct lvx-flx-pct-low">GAPS CR&Iacute;TICOS</span>';
                                elseif ($pct >= 70) echo '<span class="lvx-flx-pct lvx-flx-pct-med">PARCIAL</span>';
                                else echo '<span class="lvx-flx-pct lvx-flx-pct-low">INCOMPLETO</span>';
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ═════════════════════════════════════════════════════════════════
    // INDIVIDUAL FLOW SECTION
    // ═════════════════════════════════════════════════════════════════

    private function renderFlowSection(array $f): void
    {
        $pct = $f['completeness'];
        $pctClass = $pct >= 100 ? 'lvx-flx-pct-100' : ($pct >= 90 ? 'lvx-flx-pct-high' : ($pct >= 70 ? 'lvx-flx-pct-med' : 'lvx-flx-pct-low'));
        $total = $f['total'];
        $dist = $f['distribution'];
        ?>
        <div class="lvx-flx-card">
            <div class="lvx-flx-card-head">
                <h3>
                    <?php echo $f['title']; ?>
                    <span class="lvx-flx-pct <?php echo $pctClass; ?>"><?php echo $pct; ?>%</span>
                </h3>
                <span class="lvx-flx-cnt"><?php echo $total; ?> registros<?php if (count($f['gaps']) > 0): ?> &bull; <?php echo count($f['gaps']); ?> gaps<?php endif; ?></span>
            </div>
            <div class="lvx-flx-card-body">
                <p style="margin:0 0 10px;font-size:13px;color:#6b7280;"><?php echo $f['desc']; ?></p>

                <!-- Pipeline -->
                <div class="lvx-flx-pipeline">
                    <?php $i = 0; foreach ($f['states'] as $key => $state):
                        $cnt = $dist[$key] ?? 0;
                        $isEmpty = ($cnt <= 0);
                        $boxStyle = $isEmpty
                            ? 'lvx-flx-box-empty'
                            : 'lvx-flx-box-filled';
                        $borderColor = $isEmpty ? '#e5e7eb' : $state['color'];
                    ?>
                        <?php if ($i > 0): ?><span class="lvx-flx-arrow">&#8594;</span><?php endif; ?>
                        <div class="lvx-flx-node">
                            <div class="lvx-flx-box <?php echo $boxStyle; ?>" style="border-color:<?php echo $borderColor; ?>;<?php if (!$isEmpty): ?>color:<?php echo $state['color']; ?>;<?php endif; ?>">
                                <?php echo $state['label']; ?>
                            </div>
                            <?php if (!$isEmpty): ?>
                                <div class="lvx-flx-node-cnt"><?php echo $cnt; ?></div>
                            <?php endif; ?>
                        </div>
                    <?php $i++; endforeach; ?>
                </div>

                <?php if ($total > 0): ?>
                    <!-- Bar -->
                    <div class="lvx-flx-bar">
                        <?php foreach ($f['states'] as $key => $state):
                            $cnt = $dist[$key] ?? 0;
                            if ($cnt <= 0) continue;
                            $pctBar = round(($cnt / $total) * 100, 1);
                        ?>
                            <div class="lvx-flx-bar-seg" style="width:<?php echo $pctBar; ?>%;background:<?php echo $state['color']; ?>;" title="<?php echo esc_attr(strip_tags($state['label']) . ': ' . $cnt); ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Pills -->
                    <div class="lvx-flx-dist">
                        <?php foreach ($f['states'] as $key => $state):
                            $cnt = $dist[$key] ?? 0;
                            if ($cnt <= 0) continue;
                        ?>
                            <span class="lvx-flx-pill"><span class="dot" style="background:<?php echo $state['color']; ?>;"></span><?php echo $state['label']; ?>: <?php echo $cnt; ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($total === 0): ?>
                    <div class="lvx-flx-empty">&#128230; Nenhum registro neste fluxo</div>
                <?php endif; ?>

                <!-- Insights -->
                <?php foreach ($f['insights'] as $ins): ?>
                    <div class="lvx-flx-insight lvx-flx-insight-<?php echo esc_attr($ins['type']); ?>">
                        <?php echo $ins['type'] === 'warning' ? '&#9888;' : '&#8505;'; ?>
                        <span><?php echo $ins['text']; ?></span>
                    </div>
                <?php endforeach; ?>

                <!-- Gaps -->
                <?php if (!empty($f['gaps'])): ?>
                    <div class="lvx-flx-gaps">
                        <?php foreach ($f['gaps'] as $g): ?>
                            <div class="lvx-flx-gap lvx-flx-gap-<?php echo esc_attr($g['severity']); ?>">
                                <?php echo $g['severity'] === 'high' ? '&#10060;' : ($g['severity'] === 'medium' ? '&#9888;' : '&#10003;'); ?>
                                <span><?php echo $g['text']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Related + Transitions -->
                <div class="lvx-flx-related">
                    &#128260; <?php echo $f['transitions']; ?>
                    <?php foreach ($f['related'] as $r): ?>
                        &bull; <?php echo $r; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ═════════════════════════════════════════════════════════════════
    // GAPS SUMMARY
    // ═════════════════════════════════════════════════════════════════

    private function renderGapsSummary(array $d): void
    {
        $allGaps = [];
        foreach ($d['flows'] as $key => $f) {
            foreach ($f['gaps'] as $g) {
                $allGaps[] = array_merge($g, ['flow' => $f['title']]);
            }
        }
        if (empty($allGaps)) return;

        // Sort: high first
        usort($allGaps, function ($a, $b) {
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($order[$a['severity']] ?? 3) <=> ($order[$b['severity']] ?? 3);
        });
        ?>
        <div class="lvx-flx-card">
            <div class="lvx-flx-card-head">
                <h3>&#128680; Gaps &amp; Lacunas &mdash; Resumo Consolidado</h3>
                <span class="lvx-flx-cnt"><?php echo count($allGaps); ?> gaps &bull; <?php echo $d['total_gaps_high']; ?> cr&iacute;ticos</span>
            </div>
            <div class="lvx-flx-card-body">
                <table class="lvx-flx-tbl">
                    <thead><tr><th style="width:80px;">Prioridade</th><th style="width:200px;">Fluxo</th><th>Descri&ccedil;&atilde;o</th></tr></thead>
                    <tbody>
                    <?php foreach ($allGaps as $g): ?>
                        <tr>
                            <td>
                                <?php if ($g['severity'] === 'high'): ?>
                                    <span class="lvx-flx-pct lvx-flx-pct-low">ALTA</span>
                                <?php elseif ($g['severity'] === 'medium'): ?>
                                    <span class="lvx-flx-pct lvx-flx-pct-med">M&Eacute;DIA</span>
                                <?php else: ?>
                                    <span class="lvx-flx-pct lvx-flx-pct-100">BAIXA</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo $g['flow']; ?></strong></td>
                            <td><?php echo $g['text']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ═════════════════════════════════════════════════════════════════
    // HELPERS
    // ═════════════════════════════════════════════════════════════════

    private function getFluxosDefinition(): array
    {
        return [
            'client' => [
                'c1' => ['name'=>'C1 - Tentativa de Contato', 'description'=>'Ate 3 tentativas apos briefing', 'channel'=>'whatsapp', 'trigger'=>'Briefing recebido'],
                'c2' => ['name'=>'C2 - Confirmacao Agendamento', 'description'=>'24h antes do servico', 'channel'=>'whatsapp', 'trigger'=>'24h antes'],
                'c3' => ['name'=>'C3 - Feedback Pos-Servico', 'description'=>'Solicita feedback apos conclusao', 'channel'=>'whatsapp', 'trigger'=>'Servico concluido'],
            ],
            'staff' => [
                'p1' => ['name'=>'P1 - Oferta de Servico', 'description'=>'Nova oferta para profissional', 'channel'=>'whatsapp', 'trigger'=>'Briefing aceito'],
                'p2' => ['name'=>'P2 - Lembrete Pre-Servico', 'description'=>'2h antes do servico', 'channel'=>'sms', 'trigger'=>'2h antes'],
                'p3' => ['name'=>'P3 - Alerta de Atraso', 'description'=>'Coordenador se sem check-in', 'channel'=>'whatsapp', 'trigger'=>'15min apos inicio'],
            ],
        ];
    }
}
