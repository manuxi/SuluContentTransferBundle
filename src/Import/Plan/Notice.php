<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Plan;

use Manuxi\SuluContentTransferBundle\Format\Data;

/**
 * A remark of the analysis for the person who imports: something that will be changed, left out or could not be
 * checked. "code" is stable (the admin translates it later), "message" is the English text for the console and logs.
 */
final class Notice
{
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /**
     * @param self::INFO|self::WARNING|self::ERROR $level
     * @param array<string, scalar|null>           $params values for the translation of the message
     */
    public function __construct(
        public readonly string $level,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $articleUuid = null,
        public readonly ?string $locale = null,
        public readonly array $params = [],
    ) {
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $level = Data::string($data, 'level', 'plan.notice');
        if (!\in_array($level, [self::INFO, self::WARNING, self::ERROR], true)) {
            throw Data::error('plan.notice', 'level', 'info, warning or error');
        }

        $params = [];
        foreach (Data::map($data, 'params', 'plan.notice') as $name => $value) {
            if (null === $value || \is_scalar($value)) {
                $params[(string) $name] = $value;
            }
        }

        return new self(
            $level,
            Data::string($data, 'code', 'plan.notice'),
            Data::string($data, 'message', 'plan.notice'),
            Data::nullableString($data, 'articleUuid', 'plan.notice'),
            Data::nullableString($data, 'locale', 'plan.notice'),
            $params,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'code' => $this->code,
            'message' => $this->message,
            'articleUuid' => $this->articleUuid,
            'locale' => $this->locale,
            'params' => Data::mapOut($this->params),
        ];
    }
}
