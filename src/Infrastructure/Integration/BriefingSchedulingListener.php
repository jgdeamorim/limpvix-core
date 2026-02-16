<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Application\UseCases\Scheduling\CreateSchedule;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;
use LimpVix\Domain\Scheduling\ValueObjects\GeoCoordinates;

/**
 * Integration Listener: Briefing → Scheduling
 *
 * Cria Schedule automaticamente quando briefing é locked.
 */
final class BriefingSchedulingListener
{
    public static function init(): void
    {
        $instance = new self();

        // Briefing locked → Criar Schedule
        add_action('limpvix_briefing_locked', [$instance, 'handleBriefingLocked'], 10, 1);
    }

    /**
     * Handle briefing locked: Criar Schedule
     *
     * @param array $briefingData
     */
    public function handleBriefingLocked(array $briefingData): void
    {
        $orderUuid = $briefingData['order_uuid'] ?? null;
        $briefingId = $briefingData['briefing_id'] ?? null;
        $requestedTime = $briefingData['requested_time'] ?? null;
        $serviceAddress = $briefingData['service_address'] ?? null;

        if (!$orderUuid || !$briefingId || !$requestedTime || !$serviceAddress) {
            error_log('[LimpVix Scheduling] Briefing locked sem dados necessários');
            return;
        }

        try {
            // Criar ServiceLocation
            $location = $this->createServiceLocation($serviceAddress);

            // Criar Schedule via Use Case
            $useCase = new CreateSchedule(
                new \LimpVix\Infrastructure\Persistence\WpScheduleRepository()
            );

            $result = $useCase->execute(
                $orderUuid,
                $briefingId,
                new \DateTimeImmutable($requestedTime),
                $location
            );

            if ($result['success']) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[LimpVix Scheduling] Schedule created: %s (Order: %s, Briefing: %d)',
                        $result['schedule_uuid'],
                        $orderUuid,
                        $briefingId
                    ));
                }
            }
        } catch (\Exception $e) {
            error_log(sprintf(
                '[LimpVix Scheduling] Error creating schedule from briefing: %s',
                $e->getMessage()
            ));
        }
    }

    /**
     * Cria ServiceLocation a partir do endereço
     *
     * @param array $addressData
     * @return ServiceLocation
     */
    private function createServiceLocation(array $addressData): ServiceLocation
    {
        // Extrair coordenadas (pode vir do briefing ou geocode)
        $lat = $addressData['latitude'] ?? null;
        $lon = $addressData['longitude'] ?? null;

        if (!$lat || !$lon) {
            // Geocode via adapter (fallback: Vitória/ES)
            $adapter = new \LimpVix\Infrastructure\Adapters\Scheduling\GeolocationAdapter();
            $fullAddress = sprintf(
                '%s, %s, %s - %s, %s',
                $addressData['street'] ?? '',
                $addressData['number'] ?? '',
                $addressData['neighborhood'] ?? '',
                $addressData['city'] ?? 'Vitória',
                $addressData['state'] ?? 'ES'
            );

            $coordinates = $adapter->geocodeAddress($fullAddress);
        } else {
            $coordinates = GeoCoordinates::fromLatLong((float) $lat, (float) $lon);
        }

        return ServiceLocation::create(
            $addressData['street'] ?? '',
            $addressData['number'] ?? '',
            $addressData['neighborhood'] ?? '',
            $addressData['city'] ?? 'Vitória',
            $addressData['state'] ?? 'ES',
            $addressData['zip_code'] ?? '',
            $coordinates
        );
    }
}
