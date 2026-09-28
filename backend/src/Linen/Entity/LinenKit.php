<?php

namespace App\Linen\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A kit of linen ("Kit lit double", "Kit bain"): composition lines [{typeId, qty}]. */
#[ORM\Entity]
#[ORM\Table(name: 'linen_kit')]
class LinenKit
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 80)]
    private string $name;

    /** @var list<array{typeId: string, qty: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $lines;

    /** @param list<array{typeId: string, qty: int}> $lines */
    public function __construct(string $name, array $lines, ?string $id = null)
    {
        $this->id = null === $id ? Uuid::v7() : Uuid::fromString($id);
        $this->name = $name;
        $this->lines = $lines;
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    /** @return list<array{typeId: string, qty: int}> */
    public function getLines(): array { return $this->lines; }
    /** @param list<array{typeId: string, qty: int}> $lines */
    public function setLines(array $lines): static { $this->lines = $lines; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id->toRfc4122(), 'name' => $this->name, 'lines' => $this->lines];
    }
}
