#!/usr/bin/env php
<?php
// application.php

require __DIR__.'/vendor/autoload.php';

use Keboola\Console\Command\MassDedup;
use Keboola\Console\Command\RedshiftDeepCopy;
use Symfony\Component\Console\Application;

$application = new Application();
$application->add(new MassDedup());
$application->add(new RedshiftDeepCopy());
$application->run();
