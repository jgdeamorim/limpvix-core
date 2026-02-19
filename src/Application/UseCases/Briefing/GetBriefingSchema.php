<?php
/**
 * GetBriefingSchema - Use Case
 *
 * RESPONSABILIDADE:
 * - Retornar schema dinâmico dos steps do Briefing
 * - Aplicar regras condicionais (ex: comercial não tem quartos)
 * - Definir campos obrigatórios por step
 * - Validações e opções disponíveis
 *
 * Este Use Case é consumido pelo frontend para construir o stepper dinâmico.
 *
 * @package LimpVix\Application\UseCases\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Application\UseCases\Briefing;

defined('ABSPATH') || exit;

class GetBriefingSchema
{
    /**
     * Executar
     *
     * @param string $propertyType 'residential' ou 'commercial'
     * @param string|null $currentStep Step atual (para validações contextuais)
     * @return array Schema completo
     */
    public function execute(string $propertyType, ?string $currentStep = null): array
    {
        $schema = [
            'property_type' => $propertyType,
            'version' => '1.0',
            'steps' => $this->getSteps($propertyType),
            'current_step' => $currentStep
        ];

        return $schema;
    }

    /**
     * Obter steps dinâmicos
     *
     * @param string $propertyType
     * @return array
     */
    private function getSteps(string $propertyType): array
    {
        $steps = [
            $this->getPropertyTypeStep(),             // Step 1
            $this->getCleaningTypesStep(),             // Step 2
            $this->getStructureStep($propertyType),    // Step 3
            $this->getAdditionalsStep($propertyType),  // Step 4 (P0.5)
            $this->getPackageStep(),                   // Step 5 (P0.5)
            $this->getFrequencyStep(),                 // Step 6
            $this->getDateTimeStep(),                  // Step 7
            $this->getLocationStep(),                  // Step 8
            $this->getPhoneVerificationStep(),         // Step 9
            $this->getCheckoutStep()                   // Step 10
        ];

        return array_values(array_filter($steps));
    }

    /**
     * Step 1: Tipo de Propriedade
     */
    private function getPropertyTypeStep(): array
    {
        return [
            'step' => 1,
            'name' => 'property_type',
            'title' => 'Tipo de Propriedade',
            'description' => 'Selecione o tipo de imóvel',
            'fields' => [
                [
                    'name' => 'property_type',
                    'type' => 'radio',
                    'required' => true,
                    'options' => [
                        ['value' => 'residential', 'label' => 'Residencial'],
                        ['value' => 'commercial', 'label' => 'Comercial']
                    ]
                ]
            ]
        ];
    }

    /**
     * Step 2: Tipos de Limpeza
     */
    private function getCleaningTypesStep(): array
    {
        return [
            'step' => 2,
            'name' => 'cleaning_types',
            'title' => 'Tipos de Limpeza',
            'description' => 'Selecione os tipos de limpeza desejados',
            'fields' => [
                [
                    'name' => 'cleaning_types',
                    'type' => 'checkbox',
                    'required' => true,
                    'options' => [
                        ['value' => 'limpeza_basica', 'label' => 'Limpeza Básica'],
                        ['value' => 'limpeza_completa', 'label' => 'Limpeza Completa'],
                        ['value' => 'limpeza_pesada', 'label' => 'Limpeza Pesada (+40% tempo)'],
                        ['value' => 'pos_obra', 'label' => 'Pós-Obra (+70% tempo)'],
                        ['value' => 'pre_mudanca', 'label' => 'Pré-Mudança (+30% tempo)']
                    ]
                ]
            ]
        ];
    }

    /**
     * Step 3: Estrutura do Imóvel
     */
    private function getStructureStep(string $propertyType): array
    {
        $fields = [
            [
                'name' => 'bathrooms',
                'type' => 'number',
                'label' => 'Banheiros',
                'required' => true,
                'min' => 1,
                'max' => 10
            ],
            [
                'name' => 'has_living_room',
                'type' => 'checkbox',
                'label' => 'Possui Sala?'
            ],
            [
                'name' => 'has_kitchen',
                'type' => 'checkbox',
                'label' => 'Possui Cozinha?'
            ],
            [
                'name' => 'has_office',
                'type' => 'checkbox',
                'label' => 'Possui Escritório?'
            ],
            [
                'name' => 'has_external_area',
                'type' => 'checkbox',
                'label' => 'Possui Área Externa?'
            ]
        ];

        // Quartos apenas para residencial
        if ($propertyType === 'residential') {
            array_unshift($fields, [
                'name' => 'bedrooms',
                'type' => 'number',
                'label' => 'Quartos',
                'required' => true,
                'min' => 0,
                'max' => 10
            ]);
        }

        return [
            'step' => 3,
            'name' => 'structure',
            'title' => 'Estrutura do Imóvel',
            'description' => 'Informe a estrutura do imóvel',
            'fields' => $fields
        ];
    }

    /**
     * Step 4: Serviços Adicionais (P0.5)
     */
    private function getAdditionalsStep(string $propertyType): array
    {
        // Query additionals from catalog
        global $wpdb;
        $additionals = [];

        $table = $wpdb->prefix . 'limpvix_service_additionals';
        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;

        if ($tableExists) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, slug, description, base_price, unit, property_types
                     FROM {$table}
                     WHERE active = 1
                     ORDER BY sort_order ASC, name ASC"
                ),
                ARRAY_A
            );

            foreach ($rows as $row) {
                // Filter by property type if specified
                $propTypes = json_decode($row['property_types'] ?? '[]', true);
                if (!empty($propTypes) && !in_array($propertyType, $propTypes, true)) {
                    continue;
                }

                $additionals[] = [
                    'value' => (int) $row['id'],
                    'label' => $row['name'],
                    'description' => $row['description'] ?? '',
                    'price' => (float) ($row['base_price'] ?? 0),
                    'unit' => $row['unit'] ?? 'unit',
                    'slug' => $row['slug'] ?? '',
                ];
            }
        }

        // Fallback if table doesn't exist or is empty
        if (empty($additionals)) {
            $additionals = [
                ['value' => 'window_frames', 'label' => 'Limpeza de Esquadrias', 'price' => 30.0, 'unit' => 'unit'],
                ['value' => 'blinds', 'label' => 'Limpeza de Persianas', 'price' => 25.0, 'unit' => 'unit'],
                ['value' => 'ceiling_pvc', 'label' => 'Limpeza de Forro PVC', 'price' => 40.0, 'unit' => 'm2'],
                ['value' => 'upholstery', 'label' => 'Limpeza de Estofados', 'price' => 50.0, 'unit' => 'unit'],
                ['value' => 'carpets', 'label' => 'Limpeza de Tapetes', 'price' => 35.0, 'unit' => 'unit'],
                ['value' => 'garden', 'label' => 'Limpeza de Jardim', 'price' => 45.0, 'unit' => 'unit'],
                ['value' => 'organization', 'label' => 'Organização', 'price' => 40.0, 'unit' => 'hour'],
                ['value' => 'appliances', 'label' => 'Limpeza de Eletrodomésticos', 'price' => 20.0, 'unit' => 'unit'],
                ['value' => 'cabinets', 'label' => 'Limpeza de Armários', 'price' => 25.0, 'unit' => 'unit'],
                ['value' => 'curtains', 'label' => 'Limpeza de Cortinas', 'price' => 30.0, 'unit' => 'unit'],
            ];
        }

        return [
            'step' => 4,
            'name' => 'additionals',
            'title' => 'Serviços Adicionais',
            'description' => 'Selecione serviços adicionais (opcional)',
            'optional' => true,
            'fields' => [
                [
                    'name' => 'additionals',
                    'type' => 'checkbox_quantity',
                    'required' => false,
                    'options' => $additionals
                ]
            ]
        ];
    }

    /**
     * Step 5: Pacote (P0.5)
     */
    private function getPackageStep(): array
    {
        // Query packages from catalog
        global $wpdb;
        $packages = [];

        $table = $wpdb->prefix . 'limpvix_package_configs';
        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;

        if ($tableExists) {
            $rows = $wpdb->get_results(
                "SELECT type, name, description, percentage_increase
                 FROM {$table}
                 WHERE active = 1
                 ORDER BY percentage_increase ASC",
                ARRAY_A
            );

            foreach ($rows as $row) {
                $packages[] = [
                    'value' => $row['type'],
                    'label' => $row['name'],
                    'description' => $row['description'] ?? '',
                    'percentage_increase' => (float) ($row['percentage_increase'] ?? 0),
                ];
            }
        }

        // Fallback
        if (empty($packages)) {
            $packages = [
                ['value' => 'basic', 'label' => 'Básico', 'description' => 'Limpeza padrão', 'percentage_increase' => 0],
                ['value' => 'standard', 'label' => 'Standard', 'description' => 'Limpeza + itens adicionais selecionados', 'percentage_increase' => 15],
                ['value' => 'premium', 'label' => 'Premium', 'description' => 'Limpeza completa + todos adicionais + prioridade', 'percentage_increase' => 30],
            ];
        }

        return [
            'step' => 5,
            'name' => 'package',
            'title' => 'Pacote',
            'description' => 'Escolha o nível de serviço',
            'fields' => [
                [
                    'name' => 'package_type',
                    'type' => 'radio',
                    'required' => true,
                    'default' => 'basic',
                    'options' => $packages
                ]
            ]
        ];
    }

    /**
     * Step 6: Frequência
     */
    private function getFrequencyStep(): array
    {
        return [
            'step' => 6,
            'name' => 'frequency',
            'title' => 'Frequência',
            'description' => 'Com que frequência deseja o serviço?',
            'fields' => [
                [
                    'name' => 'type',
                    'type' => 'radio',
                    'required' => true,
                    'options' => [
                        ['value' => 'avulso', 'label' => 'Serviço Único (Avulso)'],
                        ['value' => 'weekly', 'label' => 'Semanal (requer contrato)'],
                        ['value' => 'monthly', 'label' => 'Mensal (requer contrato)']
                    ]
                ],
                [
                    'name' => 'executions_per_period',
                    'type' => 'number',
                    'label' => 'Execuções por período',
                    'conditional' => 'type != avulso',
                    'min' => 1,
                    'max' => 5
                ]
            ]
        ];
    }

    /**
     * Step 7: Data e Janela de Chegada
     */
    private function getDateTimeStep(): array
    {
        return [
            'step' => 7,
            'name' => 'datetime',
            'title' => 'Data e Horário',
            'description' => 'Escolha a data e janela de chegada',
            'fields' => [
                [
                    'name' => 'date',
                    'type' => 'date',
                    'label' => 'Data',
                    'required' => true,
                    'min_date' => '+1 day'
                ],
                [
                    'name' => 'arrival_window',
                    'type' => 'radio',
                    'label' => 'Janela de Chegada',
                    'required' => true,
                    'options' => [
                        ['value' => '08:00-10:00', 'label' => '08h - 10h'],
                        ['value' => '10:00-12:00', 'label' => '10h - 12h'],
                        ['value' => '13:00-15:00', 'label' => '13h - 15h'],
                        ['value' => '15:00-17:00', 'label' => '15h - 17h']
                    ]
                ]
            ]
        ];
    }

    /**
     * Step 8: Localização
     */
    private function getLocationStep(): array
    {
        return [
            'step' => 8,
            'name' => 'location',
            'title' => 'Localização',
            'description' => 'Endereço do serviço',
            'fields' => [
                [
                    'name' => 'address',
                    'type' => 'text',
                    'label' => 'Endereço',
                    'required' => true
                ],
                [
                    'name' => 'city',
                    'type' => 'text',
                    'label' => 'Cidade',
                    'required' => true,
                    'default' => 'Vitória'
                ],
                [
                    'name' => 'state',
                    'type' => 'text',
                    'label' => 'Estado',
                    'required' => true,
                    'default' => 'ES'
                ],
                [
                    'name' => 'zip_code',
                    'type' => 'text',
                    'label' => 'CEP',
                    'required' => true,
                    'mask' => '00000-000'
                ]
            ]
        ];
    }

    /**
     * Step 9: Verificação de Telefone (Firebase OTP)
     */
    private function getPhoneVerificationStep(): array
    {
        return [
            'step' => 9,
            'name' => 'phone_verification',
            'title' => 'Verificação de Telefone',
            'description' => 'Confirme seu número de telefone via SMS',
            'component' => 'FirebasePhoneAuth',
            'fields' => []
        ];
    }

    /**
     * Step 10: Checkout
     */
    private function getCheckoutStep(): array
    {
        return [
            'step' => 10,
            'name' => 'checkout',
            'title' => 'Pagamento',
            'description' => 'Finalize seu pedido',
            'component' => 'CheckoutTransparent',
            'fields' => []
        ];
    }
}
