<?php

declare(strict_types=1);

use App\AppModule;
use Univeros\Orion\Module;

/*
 * Modules installed in this app. The application's own module binds the search engine and serves the product
 * routes; the Orion module is the one line that installs Orion: its storage on this app's Cycle connection, the
 * migration, the orion:* commands and the doctor checks.
 *
 * @return list<Altair\Module\Contracts\ModuleInterface>
 */
return [
    new AppModule(),
    new Module(),
];
