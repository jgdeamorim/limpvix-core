<?php
declare(strict_types=1);

namespace LimpVix\Common;

/**
 * Result<T, E> - Result Pattern (Sprint 0)
 *
 * Tipo que encapsula sucesso (Ok) ou falha (Fail) sem exceptions implícitas.
 * Força tratamento explícito de erros em Use Cases.
 *
 * @template T
 * @template E
 * @package LimpVix\Common
 */
final class Result
{
    private bool $isOk;
    private mixed $value;
    private mixed $error;

    private function __construct(bool $isOk, mixed $value, mixed $error)
    {
        $this->isOk = $isOk;
        $this->value = $value;
        $this->error = $error;
    }

    /**
     * Cria Result de sucesso
     *
     * @template T
     * @param T $value
     * @return self<T, never>
     */
    public static function ok(mixed $value): self
    {
        return new self(true, $value, null);
    }

    /**
     * Cria Result de falha
     *
     * @template E
     * @param E $error
     * @return self<never, E>
     */
    public static function fail(mixed $error): self
    {
        return new self(false, null, $error);
    }

    /**
     * Verifica se é sucesso
     *
     * @return bool
     */
    public function isOk(): bool
    {
        return $this->isOk;
    }

    /**
     * Verifica se é falha
     *
     * @return bool
     */
    public function isFail(): bool
    {
        return !$this->isOk;
    }

    /**
     * Obtém valor (throws se for fail)
     *
     * @return T
     * @throws \RuntimeException
     */
    public function value(): mixed
    {
        if (!$this->isOk) {
            throw new \RuntimeException('Cannot get value from failed Result');
        }

        return $this->value;
    }

    /**
     * Obtém erro (throws se for ok)
     *
     * @return E
     * @throws \RuntimeException
     */
    public function error(): mixed
    {
        if ($this->isOk) {
            throw new \RuntimeException('Cannot get error from successful Result');
        }

        return $this->error;
    }

    /**
     * Obtém valor ou retorna default se falha
     *
     * @param T $default
     * @return T
     */
    public function valueOr(mixed $default): mixed
    {
        return $this->isOk ? $this->value : $default;
    }

    /**
     * Map sobre o valor (se Ok)
     *
     * @template U
     * @param callable(T): U $fn
     * @return self<U, E>
     */
    public function map(callable $fn): self
    {
        if (!$this->isOk) {
            return $this;
        }

        return self::ok($fn($this->value));
    }

    /**
     * FlatMap sobre o valor (se Ok)
     *
     * @template U
     * @param callable(T): Result<U, E> $fn
     * @return Result<U, E>
     */
    public function flatMap(callable $fn): self
    {
        if (!$this->isOk) {
            return $this;
        }

        return $fn($this->value);
    }

    /**
     * Match pattern (executa função baseado em Ok/Fail)
     *
     * @template U
     * @param callable(T): U $onOk
     * @param callable(E): U $onFail
     * @return U
     */
    public function match(callable $onOk, callable $onFail): mixed
    {
        return $this->isOk ? $onOk($this->value) : $onFail($this->error);
    }
}
