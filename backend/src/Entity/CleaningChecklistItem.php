<?php

namespace App\Entity;

use App\Repository\CleaningChecklistItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One line of a place's (by id) cleaning checklist template for one type of cleaning, copied into every new cleaning task of that place. */
#[ORM\Entity(repositoryClass: CleaningChecklistItemRepository::class)]
#[ORM\Index(name: 'idx_cleaning_checklist_item_place', columns: ['place_id'])]
class CleaningChecklistItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'place_id', length: 36)]
    private string $placeId;

    /** Type of cleaning the template is for (CleaningTask::TYPES). */
    #[ORM\Column(length: 16, options: ['default' => 'rental'])]
    private string $type;

    #[ORM\Column(length: 160)]
    private string $label;

    #[ORM\Column]
    private int $position;

    /** @var list<string> other ways of saying it, for the voice assistant ("sdb" for "salle de bain") */
    #[ORM\Column(type: 'json')]
    private array $synonyms = [];

    /** A photo of this area is asked for at the end of the cleaning (guided photo round). */
    #[ORM\Column(options: ['default' => false])]
    private bool $photo = false;

    /** Room or area the point belongs to ("Chambre", "Cuisine"), named in the photo round; null: the label. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $area = null;

    public function __construct(string $placeId, string $label, int $position, string $type = CleaningTask::RENTAL)
    {
        $this->id = Uuid::v7();
        $this->placeId = $placeId;
        $this->label = $label;
        $this->type = $type;
        $this->position = $position;
    }

    public function getId(): Uuid { return $this->id; }
    public function getPlaceId(): string { return $this->placeId; }
    public function getType(): string { return $this->type; }
    public function getLabel(): string { return $this->label; }
    public function getPosition(): int { return $this->position; }
    /** @return list<string> */
    public function getSynonyms(): array { return $this->synonyms; }
    public function isPhoto(): bool { return $this->photo; }
    public function getArea(): ?string { return $this->area; }

    /** @param list<string> $synonyms */
    public function describe(array $synonyms, bool $photo, ?string $area): static
    {
        $this->synonyms = array_values(array_unique(array_filter(array_map(static fn ($s) => mb_substr(trim((string) $s), 0, 80), $synonyms), static fn (string $s) => '' !== $s)));
        $this->synonyms = \array_slice($this->synonyms, 0, 20);
        $this->photo = $photo;
        $area = null === $area ? '' : mb_substr(trim($area), 0, 80);
        $this->area = '' === $area ? null : $area;

        return $this;
    }

    /** @return array{label: string, synonyms?: list<string>, photo?: bool, area?: string} the line as copied into a cleaning */
    public function toLine(): array
    {
        $line = ['label' => $this->label];
        if ([] !== $this->synonyms) {
            $line['synonyms'] = $this->synonyms;
        }
        if ($this->photo) {
            $line['photo'] = true;
        }
        if (null !== $this->area) {
            $line['area'] = $this->area;
        }

        return $line;
    }
}
