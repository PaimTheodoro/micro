<?php

namespace Tests\Fixtures\Models;

use Psf\Model\Model;
use Psf\Model\ModelTrait;
use Psf\Model\Attributes\Column;
use Psf\Model\Attributes\ColumnDeletedDate;
use Psf\Model\Attributes\PrimaryKey;
use Psf\Model\Attributes\Table;
use Psf\Model\Attributes\Type;

#[Table('fake_notes')]
class FakeSoftDeleteModel extends Model
{
    use ModelTrait;

    #[PrimaryKey]
    #[Column('id')]
    #[Type('INT UNSIGNED AUTO_INCREMENT')]
    public ?int $id = null;

    #[Column('title')]
    #[Type('VARCHAR(255)')]
    public ?string $title = null;

    #[Column('deleted_at')]
    #[ColumnDeletedDate]
    #[Type('DATETIME')]
    public ?string $deletedAt = null;
}
