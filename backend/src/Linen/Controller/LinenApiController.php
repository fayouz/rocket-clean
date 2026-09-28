<?php

namespace App\Linen\Controller;

use App\Linen\Entity\LinenMovement;
use Rocket\Core\Entity\User;
use Rocket\Core\Security\ApplicationUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

/** Helpers shared by the controllers of the linen module. */
abstract class LinenApiController extends AbstractController
{
    /** Who made the change, recorded on movements: the user's e-mail or the application's name. */
    protected function actor(): ?string
    {
        $user = $this->getUser();

        return match (true) {
            $user instanceof User => $user->getEmail(),
            $user instanceof ApplicationUser => 'app:'.mb_substr($user->getApplication()->getName(), 0, 170),
            default => null,
        };
    }

    /** Default origin of a manual change: "host" for an application (Rocket Host, a PMS), else "linen". */
    protected function origin(mixed $value): string
    {
        if (null === $value) {
            return $this->getUser() instanceof ApplicationUser ? 'host' : 'linen';
        }

        return \in_array($value, LinenMovement::ORIGINS, true) ? $value : throw new HttpException(422, 'Origine invalide ('.implode(', ', LinenMovement::ORIGINS).').');
    }

    protected function placeParam(string $placeId): string
    {
        return Uuid::isValid($placeId) ? $placeId : throw new HttpException(404, 'Lieu inconnu.');
    }

    protected function dayParam(Request $request, string $name, string $default = 'today'): \DateTimeImmutable
    {
        $value = (string) $request->query->get($name, '');
        if ('' === $value) {
            return new \DateTimeImmutable($default);
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: throw new HttpException(422, "$name attendu au format AAAA-MM-JJ.");
    }

    protected function intIn(mixed $value, int $min, int $max, string $name): int
    {
        return \is_int($value) && $value >= $min && $value <= $max ? $value : throw new HttpException(422, "« $name » : entier entre $min et $max attendu.");
    }

    protected function text(mixed $value, int $max, string $name): string
    {
        $text = \is_string($value) ? trim($value) : '';

        return '' !== $text ? mb_substr($text, 0, $max) : throw new HttpException(422, "« $name » est requis.");
    }
}
