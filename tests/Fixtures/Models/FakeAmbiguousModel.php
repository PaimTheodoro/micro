<?php

namespace Tests\Fixtures\Models;

use Psf\Model\Model;
use Psf\Model\ModelTrait;
use Psf\Model\Attributes\Column;
use Psf\Model\Attributes\PrimaryKey;
use Psf\Model\Attributes\Table;
use Psf\Model\Attributes\Type;

/**
 * `status` é a propriedade da coluna `status_id`, e `legacyStatus` é a propriedade da coluna
 * `status`: o nome solto `status` é, ao mesmo tempo, propriedade e coluna de outra propriedade.
 */
#[Table('fake_ambiguous')]
class FakeAmbiguousModel extends Model
{
    use ModelTrait;

    #[PrimaryKey]
    #[Column('id')]
    #[Type('INT')]
    public ?int $id = null;

    #[Column('status_id')]
    #[Type('INT')]
    public ?int $status = null;

    #[Column('status')]
    #[Type('VARCHAR(20)')]
    public ?string $legacyStatus = null;
}
