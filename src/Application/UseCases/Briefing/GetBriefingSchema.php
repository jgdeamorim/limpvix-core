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
            $this->getPropertyTypeStep(),
            $this->getCleaningTypesStep(),
            $this->getStructureStep($propertyType),
            $this->getFrequencyStep(),
            // Contract step é condicional (adicionado dinamicamente)
            $this->getDateTimeStep(),
            $this->getLocationStep(),
            $this->getPhoneVerificationStep(),
            $this->getCheckoutStep()
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
     * Step 4: Frequência
     */
    private function getFrequencyStep(): array
    {
        return [
            'step' => 4,
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
     * Step 5: Data e Janela de Chegada
     */
    private function getDateTimeStep(): array
    {
        return [
            'step' => 5,
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
     * Step 6: Localização
     */
    private function getLocationStep(): array
    {
        return [
            'step' => 6,
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
     * Step 7: Verificação de Telefone (Firebase OTP)
     */
    private function getPhoneVerificationStep(): array
    {
        return [
            'step' => 7,
            'name' => 'phone_verification',
            'title' => 'Verificação de Telefone',
            'description' => 'Confirme seu número de telefone via SMS',
            'component' => 'FirebasePhoneAuth',
            'fields' => []
        ];
    }

    /**
     * Step 8: Checkout
     */
    private function getCheckoutStep(): array
    {
        return [
            'step' => 8,
            'name' => 'checkout',
            'title' => 'Pagamento',
            'description' => 'Finalize seu pedido',
            'component' => 'CheckoutTransparent',
            'fields' => []
        ];
    }
}
