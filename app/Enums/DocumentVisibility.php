<?php

namespace App\Enums;

enum DocumentVisibility: string
{
    case Private = 'private';
    case SignedTemporary = 'signed-temporary';
}

