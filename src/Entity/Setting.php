<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A setting chosen in the interface, which overrides the default of the environment.
 */
#[ORM\Entity]
class Setting
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $name,
        #[ORM\Column(length: 255)]
        private string $value,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function change(string $value): void
    {
        $this->value = $value;
    }
}
