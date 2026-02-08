<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: ServiceLocation
 *
 * Representa a localização onde o serviço será executado.
 * Inclui endereço completo + coordenadas geográficas.
 *
 * IMUTÁVEL.
 */
final class ServiceLocation
{
    private string $street;
    private string $number;
    private ?string $complement;
    private string $neighborhood;
    private string $city;
    private string $state;
    private string $zipCode;
    private GeoCoordinates $coordinates;

    private function __construct(
        string $street,
        string $number,
        ?string $complement,
        string $neighborhood,
        string $city,
        string $state,
        string $zipCode,
        GeoCoordinates $coordinates
    ) {
        $this->validateNotEmpty($street, 'street');
        $this->validateNotEmpty($number, 'number');
        $this->validateNotEmpty($neighborhood, 'neighborhood');
        $this->validateNotEmpty($city, 'city');
        $this->validateNotEmpty($state, 'state');
        $this->validateZipCode($zipCode);

        $this->street = $street;
        $this->number = $number;
        $this->complement = $complement;
        $this->neighborhood = $neighborhood;
        $this->city = $city;
        $this->state = $state;
        $this->zipCode = $zipCode;
        $this->coordinates = $coordinates;
    }

    public static function create(
        string $street,
        string $number,
        ?string $complement,
        string $neighborhood,
        string $city,
        string $state,
        string $zipCode,
        GeoCoordinates $coordinates
    ): self {
        return new self(
            $street,
            $number,
            $complement,
            $neighborhood,
            $city,
            $state,
            $zipCode,
            $coordinates
        );
    }

    /**
     * Factory: Criar a partir de array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['street'] ?? '',
            $data['number'] ?? '',
            $data['complement'] ?? null,
            $data['neighborhood'] ?? '',
            $data['city'] ?? '',
            $data['state'] ?? '',
            $data['zip_code'] ?? '',
            GeoCoordinates::fromArray($data['coordinates'] ?? [])
        );
    }

    /**
     * Retorna endereço formatado
     */
    public function getFormattedAddress(): string
    {
        $parts = [
            $this->street . ', ' . $this->number,
            $this->complement,
            $this->neighborhood,
            $this->city . ' - ' . $this->state,
            'CEP: ' . $this->zipCode,
        ];

        return implode(', ', array_filter($parts));
    }

    /**
     * Retorna endereço curto (rua, número, cidade)
     */
    public function getShortAddress(): string
    {
        return sprintf(
            '%s, %s - %s',
            $this->street,
            $this->number,
            $this->city
        );
    }

    /**
     * Calcula distância até outra localização em metros
     */
    public function distanceToInMeters(ServiceLocation $other): float
    {
        return $this->coordinates->distanceToInMeters($other->coordinates);
    }

    /**
     * Verifica se está dentro de um raio de uma região
     */
    public function isWithinRadiusOf(GeoCoordinates $center, int $radiusMeters): bool
    {
        return $this->coordinates->isWithinRadius($center, $radiusMeters);
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getComplement(): ?string
    {
        return $this->complement;
    }

    public function getNeighborhood(): string
    {
        return $this->neighborhood;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getZipCode(): string
    {
        return $this->zipCode;
    }

    public function getCoordinates(): GeoCoordinates
    {
        return $this->coordinates;
    }

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'number' => $this->number,
            'complement' => $this->complement,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zipCode,
            'coordinates' => $this->coordinates->toArray(),
            'formatted_address' => $this->getFormattedAddress(),
        ];
    }

    private function validateNotEmpty(string $value, string $fieldName): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(
                sprintf('Field %s cannot be empty', $fieldName)
            );
        }
    }

    private function validateZipCode(string $zipCode): void
    {
        // Remove hífens e espaços
        $cleanZip = preg_replace('/[^0-9]/', '', $zipCode);

        if (strlen($cleanZip) !== 8) {
            throw new \InvalidArgumentException(
                sprintf('Invalid zip code: %s', $zipCode)
            );
        }
    }
}
