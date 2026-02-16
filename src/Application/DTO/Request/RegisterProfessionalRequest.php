<?php
/**
 * RegisterProfessionalRequest DTO
 *
 * @package LimpVix\Application\DTO\Request
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Request;

defined('ABSPATH') || exit;

final class RegisterProfessionalRequest extends BaseRequestDTO
{
    public function __construct(
        public readonly string $full_name,
        public readonly string $cpf,
        public readonly string $phone,
        public readonly string $email,
        public readonly array $address,
        public readonly array $skills,
        public readonly array $certifications,
        public readonly array $physical_limitations,
        public readonly float $service_radius_km,
        public readonly array $weekly_availability,
    ) {
        $errors = $this->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Validation failed: ' . implode(', ', $errors));
        }
    }

    public function validate(): array
    {
        $errors = [];

        // Validar full_name
        if (empty($this->full_name) || strlen($this->full_name) < 3) {
            $errors[] = 'full_name: Nome completo é obrigatório (mínimo 3 caracteres)';
        }

        // Validar CPF
        if (empty($this->cpf)) {
            $errors[] = 'cpf: CPF é obrigatório';
        } elseif (!$this->validateCPF($this->cpf)) {
            $errors[] = 'cpf: CPF inválido';
        }

        // Validar phone
        if (empty($this->phone)) {
            $errors[] = 'phone: Telefone é obrigatório';
        }

        // Validar email
        if (empty($this->email)) {
            $errors[] = 'email: Email é obrigatório';
        } elseif (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'email: Email inválido';
        }

        // Validar address
        if (empty($this->address)) {
            $errors[] = 'address: Endereço é obrigatório';
        } elseif (!isset($this->address['lat']) || !isset($this->address['lng'])) {
            $errors[] = 'address: Endereço deve conter lat e lng';
        }

        // Validar skills
        if (empty($this->skills)) {
            $errors[] = 'skills: Pelo menos uma habilidade é obrigatória';
        }

        // Validar service_radius_km
        if ($this->service_radius_km <= 0) {
            $errors[] = 'service_radius_km: Raio de atendimento deve ser positivo';
        }

        // Validar weekly_availability
        if (empty($this->weekly_availability)) {
            $errors[] = 'weekly_availability: Disponibilidade semanal é obrigatória';
        }

        return $errors;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            full_name: (string) ($data['full_name'] ?? ''),
            cpf: (string) ($data['cpf'] ?? ''),
            phone: (string) ($data['phone'] ?? ''),
            email: (string) ($data['email'] ?? ''),
            address: (array) ($data['address'] ?? []),
            skills: (array) ($data['skills'] ?? []),
            certifications: (array) ($data['certifications'] ?? []),
            physical_limitations: (array) ($data['physical_limitations'] ?? []),
            service_radius_km: (float) ($data['service_radius_km'] ?? 20),
            weekly_availability: (array) ($data['weekly_availability'] ?? []),
        );
    }

    /**
     * Convert to array for Use Case
     */
    public function toArray(): array
    {
        return [
            'full_name' => $this->full_name,
            'cpf' => $this->cpf,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'skills' => $this->skills,
            'certifications' => $this->certifications,
            'physical_limitations' => $this->physical_limitations,
            'service_radius_km' => $this->service_radius_km,
            'weekly_availability' => $this->weekly_availability,
        ];
    }

    /**
     * Validate CPF format
     */
    private function validateCPF(string $cpf): bool
    {
        // Remove non-numeric characters
        $cpf = preg_replace('/[^0-9]/', '', $cpf);

        // Must have 11 digits
        if (strlen($cpf) !== 11) {
            return false;
        }

        // Cannot be all same digits
        if (preg_match('/^(\d)\1+$/', $cpf)) {
            return false;
        }

        // Validate check digits
        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }

        return true;
    }
}
