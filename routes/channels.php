<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('public.queue', fn() => true);
Broadcast::channel('department.{departmentId}', fn() => true);
