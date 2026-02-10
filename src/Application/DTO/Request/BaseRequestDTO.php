<?php
/**
 * BaseRequestDTO - Abstract base class for Request DTOs
 *
 * RESPONSABILIDADE:
 * - Definir interface comum para todos Request DTOs
 * - Fornecer métodos auxiliares de validação
 * - Garantir que todos DTOs implementem validate() e fromArray()
 *
 * PRINCÍPIOS:
 * - DTOs são imutáveis (readonly properties)
 * - Validação acontece no construtor
 * - Erros de validação lançam InvalidArgumentException
 *
 * @package LimpVix\Application\DTO\Request
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Request;

defined('ABSPATH') || exit;

abstract class BaseRequestDTO
{
    /**
     * Validate DTO data
     *
     * @return array Array of validation errors (empty if valid)
     */
    abstract public function validate(): array;

    /**
     * Create DTO from array data
     *
     * @param array $data Input data
     * @return static
     */
    abstract public static function fromArray(array $data): static;

    /**
     * Validate required fields
     *
     * @param array $data Input data
     * @param array $required Required field names
     * @return array Validation errors
     */
    protected function validateRequired(array $data, array $required): array
    {
        $errors = [];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $errors[] = "{$field}: Field is required";
            }
        }

        return $errors;
    }

    /**
     * Validate field type
     *
     * @param mixed $value Field value
     * @param string $type Expected type (int, string, float, bool, array)
     * @param string $field Field name
     * @return string|null Error message or null if valid
     */
    protected function validateType(mixed $value, string $type, string $field): ?string
    {
        $valid = match($type) {
            'int' => is_int($value),
            'string' => is_string($value),
            'float' => is_float($value) || is_int($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => false
        };

        if (!$valid) {
            return "{$field}: Expected {$type}, got " . gettype($value);
        }

        return null;
    }

    /**
     * Validate enum value
     *
     * @param mixed $value Field value
     * @param array $allowed Allowed values
     * @param string $field Field name
     * @return string|null Error message or null if valid
     */
    protected function validateEnum(mixed $value, array $allowed, string $field): ?string
    {
        if (!in_array($value, $allowed, true)) {
            $allowedStr = implode(', ', $allowed);
            return "{$field}: Invalid value. Allowed: {$allowedStr}";
        }

        return null;
    }

    /**
     * Validate positive number
     *
     * @param int|float $value Field value
     * @param string $field Field name
     * @return string|null Error message or null if valid
     */
    protected function validatePositive(int|float $value, string $field): ?string
    {
        if ($value <= 0) {
            return "{$field}: Must be positive";
        }

        return null;
    }

    /**
     * Validate date format (Y-m-d or Y-m-d H:i:s)
     *
     * @param string $value Date string
     * @param string $field Field name
     * @return string|null Error message or null if valid
     */
    protected function validateDate(string $value, string $field): ?string
    {
        $formats = ['Y-m-d', 'Y-m-d H:i:s'];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date && $date->format($format) === $value) {
                return null;
            }
        }

        return "{$field}: Invalid date format. Expected Y-m-d or Y-m-d H:i:s";
    }
}
