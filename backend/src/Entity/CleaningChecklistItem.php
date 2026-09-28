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
}
