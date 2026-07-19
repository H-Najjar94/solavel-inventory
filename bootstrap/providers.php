<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // The deployed Apache/FPM runtime must register the view binding for
    // HTML/API exception rendering just as the CLI container does.
    Illuminate\View\ViewServiceProvider::class,
];
