<?php

declare(strict_types=1);

namespace Answeb\CategoryTree\Move;

final class MoveRequest
{
    private const FIELDS = ['categoryId', 'newParentId', 'newIndex', 'expectedParentId', 'expectedIndex'];

    public function __construct(
        public readonly int $categoryId,
        public readonly int $newParentId,
        public readonly int $newIndex,
        public readonly int $expectedParentId,
        public readonly int $expectedIndex,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid payload.');
        }

        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? null;
            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException(sprintf('Invalid field "%s".', $field));
            }
            $values[] = $value;
        }

        if ($values[0] === 0 || $values[1] === 0 || $values[3] === 0) {
            throw new \InvalidArgumentException('Invalid category identifier.');
        }

        return new self(...$values);
    }

    public function toArray(): array
    {
        return [
            'categoryId' => $this->categoryId,
            'newParentId' => $this->newParentId,
            'newIndex' => $this->newIndex,
            'expectedParentId' => $this->expectedParentId,
            'expectedIndex' => $this->expectedIndex,
        ];
    }
}
