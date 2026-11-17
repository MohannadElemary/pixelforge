<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('pixelforge:recover')->everyMinute()->withoutOverlapping(2);
