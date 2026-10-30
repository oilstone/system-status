<?php

namespace Oilstone\SystemStatus;

enum WatchState: string
{
    case Up = 'up';
    case Down = 'down';
}
