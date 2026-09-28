<?php

namespace App\Linen\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A laundry provider (blanchisserie): order e-mail, pricing per kg (pricePerKg, cents) or per piece (piecePrices:
 * {typeId: cents}), turnaround in days (expected return of a batch).
 */
#[ORM\Entity]
#[ORM\Table(name: 'linen_laundry')]
class Laundry
{
    public const PER_KG = 'kg';
    public const PER_PIECE = 'piece';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $orderEmail = null;

    #[ORM\Column(length: 8)]
    private string $pricing = self::PER_KG;

    #[ORM\Column(nullable: true)]
    private ?int $pricePerKg = null;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $piecePrices = [];

    #[ORM\Column]
    private int $turnaroundDays = 3;

    #[ORM\Column]
    private bool $active = true;

    public function __construct(string $name, ?string $id = null)
    {
        $this->id = null === $id ? Uuid::v7() : Uuid::fromString($id);
        $this->name = $name;
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getOrderEmail(): ?string { return $this->orderEmail; }
    public function setOrderEmail(?string $email): static { $this->orderEmail = $email; return $this; }
    public function getPricing(): string { return $this->pricing; }
    public function setPricing(string $pricing): static { $this->pricing = $pricing; return $this; }
    public function getPricePerKg(): ?int { return $this->pricePerKg; }
    public function setPricePerKg(?int $cents): static { $this->pricePerKg = $cents; return $this; }
    /** @return array<string, int> */
    public function getPiecePrices(): array { return $this->piecePrices; }
    /** @param array<string, int> $prices */
    public function setPiecePrices(array $prices): static { $this->piecePrices = $prices; return $this; }
    public function getTurnaroundDays(): int { return $this->turnaroundDays; }
    public function setTurnaroundDays(int $days): static { $this->turnaroundDays = $days; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(), 'name' => $this->name, 'orderEmail' => $this->orderEmail, 'pricing' => $this->pricing,
            'pricePerKg' => $this->pricePerKg, 'piecePrices' => (object) $this->piecePrices, 'turnaroundDays' => $this->turnaroundDays, 'active' => $this->active,
        ];
    }
}
