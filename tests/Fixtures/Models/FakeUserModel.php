<?php

namespace Tests\Fixtures\Models;

use Psf\Model\Model;
use Psf\Model\Attributes\Column;
use Psf\Model\Attributes\ColumnCreatedDate;
use Psf\Model\Attributes\Nullable;
use Psf\Model\Attributes\PrimaryKey;
use Psf\Model\Attributes\Table;
use Psf\Model\Attributes\Type;

#[Table('fake_users')]
class FakeUserModel extends Model
{
    #[PrimaryKey]
    #[Column('id')]
    #[Type('INT UNSIGNED AUTO_INCREMENT')]
    public ?int $id = null;

    #[Column('name')]
    #[Nullable(false)]
    #[Type('VARCHAR(255) NOT NULL')]
    public ?string $name = null;

    #[Column('email')]
    #[Nullable(false)]
    #[Type('VARCHAR(255) NOT NULL')]
    public ?string $email = null;

    #[Column('created_at')]
    #[ColumnCreatedDate]
    #[Type('DATETIME')]
    public ?string $createdAt = null;
}
