<?php

namespace Tests\Fixtures\Models;

use Psf\Model\Model;
use Psf\Model\Attributes\Column;
use Psf\Model\Attributes\ColumnCreatedDate;
use Psf\Model\Attributes\ColumnUpdatedDate;
use Psf\Model\Attributes\Nullable;
use Psf\Model\Attributes\PrimaryKey;
use Psf\Model\Attributes\Table;
use Psf\Model\Attributes\Type;

#[Table('fake_products')]
class FakeProductModel extends Model
{
    #[PrimaryKey]
    #[Column('id')]
    #[Type('INT UNSIGNED AUTO_INCREMENT')]
    public ?int $id = null;

    #[Column('user_id')]
    #[Nullable(false)]
    #[Type('INT UNSIGNED NOT NULL')]
    public ?int $userId = null;

    #[Column('name')]
    #[Nullable(false)]
    #[Type('VARCHAR(255) NOT NULL')]
    public ?string $name = null;

    #[Column('price')]
    #[Nullable(false)]
    #[Type('DECIMAL(10,2) NOT NULL')]
    public ?float $price = null;

    #[Column('created_at')]
    #[ColumnCreatedDate]
    #[Type('DATETIME')]
    public ?string $createdAt = null;

    #[Column('updated_at')]
    #[ColumnUpdatedDate]
    #[Type('DATETIME')]
    public ?string $updatedAt = null;
}
