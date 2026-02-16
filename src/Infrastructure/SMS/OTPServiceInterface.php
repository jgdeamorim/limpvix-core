<?php
/**
 * OTP Service Interface
 *
 * Contract for OTP (One-Time Password) delivery services.
 * Implementations: Twilio (SMS/WhatsApp), Firebase, AWS SNS, etc.
 *
 * @package LimpVix\Infrastructure\SMS
 * @since Sprint 9 - OTP Verification
 */

namespace LimpVix\Infrastructure\SMS;

defined('ABSPATH') || exit;

/**
 * Interface OTPServiceInterface
 *
 * Defines contract for sending OTP codes via SMS/WhatsApp.
 *
 * Security Requirements:
 * - Codes must be cryptographically random (6 digits)
 * - Rate limiting (max 3 attempts per hour per phone)
 * - Short expiry (5 minutes)
 * - Prevent brute force (lockout after 5 failed verifications)
 *
 * @since Sprint 9
 */
interface OTPServiceInterface
{
    /**
     * Send OTP code via SMS
     *
     * @param string $phoneNumber E.164 format (+55 11 99999-9999)
     * @param string $code 6-digit numeric code
     * @param string $locale Language code (pt_BR, en_US)
     * @return bool Success status
     * @throws \RuntimeException If delivery fails
     */
    public function sendSMS(string $phoneNumber, string $code, string $locale = 'pt_BR'): bool;

    /**
     * Send OTP code via WhatsApp
     *
     * @param string $phoneNumber E.164 format (+55 11 99999-9999)
     * @param string $code 6-digit numeric code
     * @param string $locale Language code (pt_BR, en_US)
     * @return bool Success status
     * @throws \RuntimeException If delivery fails
     */
    public function sendWhatsApp(string $phoneNumber, string $code, string $locale = 'pt_BR'): bool;

    /**
     * Verify if service is properly configured
     *
     * Checks:
     * - API credentials present
     * - API credentials valid
     * - Service reachable
     *
     * @return bool True if configured and working
     */
    public function isConfigured(): bool;

    /**
     * Get service name for logging/debugging
     *
     * @return string Service name (e.g., "Twilio", "Firebase")
     */
    public function getServiceName(): string;

    /**
     * Get current rate limit status for a phone number
     *
     * @param string $phoneNumber E.164 format
     * @return array ['remaining' => int, 'reset_at' => \DateTimeImmutable]
     */
    public function getRateLimitStatus(string $phoneNumber): array;
}
