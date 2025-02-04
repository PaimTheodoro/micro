<?php

namespace Psf\Enumerators;

enum Protocol : int {
    case Http          	= 1;
    case Https      	= 2;

    case Smtp  			= 11;
}