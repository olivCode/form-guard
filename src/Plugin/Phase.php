<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

enum Phase: int
{
    case Request = 0;
    case Form = 1;
}
