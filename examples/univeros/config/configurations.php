<?php

declare(strict_types=1);

use Altair\Configuration\EnvironmentConfiguration;
use Altair\Doctor\Configuration\DoctorConfiguration;
use Altair\Logging\Configuration\LoggingConfiguration;
use Altair\Persistence\Configuration\CycleOrmConfiguration;

/*
 * The Configuration chain applied at boot, before the modules: `.env`, logging, the database Orion shares with the
 * application, and the doctor whose registry the Orion checks join.
 *
 * @return list<Altair\Configuration\Contracts\ConfigurationInterface>
 */
$root = dirname(__DIR__);
$env = $root . '/.env';

return [
    ...(is_file($env) ? [new EnvironmentConfiguration($env)] : []),
    new LoggingConfiguration(),
    new CycleOrmConfiguration(),
    new DoctorConfiguration($root),
];
