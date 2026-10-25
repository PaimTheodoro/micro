<?php

namespace Tests\Fixtures\Controllers;

class FakeAuth
{
    public function verify()
    {
        return (object) ['id' => 9];
    }
}
