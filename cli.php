#!/usr/bin/env php
<?php
// application.php

require __DIR__.'/vendor/autoload.php';

use Keboola\Console\Command\MassDedup;
use Keboola\Console\Command\RedshiftDeepCopy;
use Keboola\Console\Command\ProjectsAddFeature;
use Keboola\Console\Command\ProjectsRemoveFeature;
use Keboola\Console\Command\RedshiftSchemasCount;
use Keboola\Console\Command\RedshiftOrphanedWorkspaces;
use Keboola\Console\Command\DeletedProjectsPurge;
use Keboola\Console\Command\TouchTables;
use Keboola\Console\Command\NotifyProjects;
use Symfony\Component\Console\Application;
use Keboola\Console\Command\SetDataRetention;
use Keboola\Console\Command\MaintainerCleanup;

$application = new Application();
$application->add(new MassDedup());
$application->add(new RedshiftDeepCopy());
$application->add(new ProjectsAddFeature());
$application->add(new ProjectsRemoveFeature());
$application->add(new RedshiftSchemasCount());
$application->add(new RedshiftOrphanedWorkspaces());
$application->add(new DeletedProjectsPurge());
$application->add(new TouchTables());
$application->add(new NotifyProjects());
$application->add(new SetDataRetention());
$application->add(new MaintainerCleanup());
$application->run();
