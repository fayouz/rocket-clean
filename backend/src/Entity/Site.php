<?php

namespace App\Entity;

use App\Repository\SiteRepository;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Symfony\Component\Uid\Uuid;

/**
 * A place as Rocket Clean knows it, keyed by its id (a UUID, the one of Rocket Place when configured).
 * - "local" (standalone mode, no ROCKET_PLACE_URL): a place created here, with only a name.
 * - "place": a local cache of a Rocket Place place (name, refreshed on every read), created lazily.
 * Either way it holds the Rocket Cloud folder of the place where cleaning photos go.
 */
#[ORM\Entity(repositoryClass: SiteRepository::class)]
class Site
{
    public const LOCAL = 'local';
    public const PLACE = 'place';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 8)]
    private string $source;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $cloudFolderId = null;

    use TrackedTrait;

    public function __construct(string $name, string $source = self::LOCAL, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->name = $name;
        $this->source = $source;
    }

    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getSource(): string { return $this->source; }
    public function isLocal(): bool { return self::LOCAL === $this->source; }
    public function getCloudFolderId(): ?string { return $this->cloudFolderId; }
    public function setCloudFolderId(?string $id): static { $this->cloudFolderId = $id; return $this; }

    /** @return array{id: string, name: string, source: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'source' => $this->source];
    }
}
