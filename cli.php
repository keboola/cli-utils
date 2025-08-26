#!/usr/bin/env php
<?php
// application.php

require __DIR__.'/vendor/autoload.php';

use Keboola\Console\Command\AddFeature;
use Keboola\Console\Command\AllStacksIterator;
use Keboola\Console\Command\DeleteOrganizationOrphanedWorkspaces;
use Keboola\Console\Command\DeleteOrphanedWorkspaces;
use Keboola\Console\Command\DeleteOwnerlessWorkspaces;
use Keboola\Console\Command\DescribeOrganizationWorkspaces;
use Keboola\Console\Command\LineageEventsExport;
use Keboola\Console\Command\MassDeleteProjectWorkspaces;
use Keboola\Console\Command\MassProjectEnableDynamicBackends;
use Keboola\Console\Command\MassProjectExtendExpiration;
use Keboola\Console\Command\OrganizationIntoMaintenanceMode;
use Keboola\Console\Command\OrganizationResetWorkspacePasswords;
use Keboola\Console\Command\OrganizationStorageBackend;
use Keboola\Console\Command\QueueMassTerminateJobs;
use Keboola\Console\Command\ReactivateSchedules;
use Keboola\Console\Command\ProjectsAddFeature;
use Keboola\Console\Command\ProjectsRemoveFeature;
use Keboola\Console\Command\DeletedProjectsPurge;
use Keboola\Console\Command\DeleteProjectSandboxes;
use Keboola\Console\Command\NotifyProjects;
use Keboola\Console\Command\RemoveUserFromOrganizationProjects;
use Symfony\Component\Console\Application;
use Keboola\Console\Command\SetDataRetention;
use Keboola\Console\Command\UpdateDataRetention;

$application = new Application();
$application->add(new ProjectsAddFeature());
$application->add(new ProjectsRemoveFeature());
$application->add(new DeletedProjectsPurge());
$application->add(new NotifyProjects());
$application->add(new SetDataRetention());
$application->add(new MassProjectExtendExpiration());
$application->add(new MassProjectEnableDynamicBackends());
$application->add(new AddFeature());
$application->add(new AllStacksIterator());
$application->add(new LineageEventsExport());
$application->add(new QueueMassTerminateJobs());
$application->add(new DeleteOrphanedWorkspaces());
$application->add(new DeleteOrganizationOrphanedWorkspaces());
$application->add(new OrganizationIntoMaintenanceMode());
$application->add(new OrganizationStorageBackend());
$application->add(new DeleteOwnerlessWorkspaces());
$application->add(new DeleteProjectSandboxes());
$application->add(new RemoveUserFromOrganizationProjects());
$application->add(new ReactivateSchedules());
$application->add(new DescribeOrganizationWorkspaces());
$application->add(new MassDeleteProjectWorkspaces());
$application->add(new UpdateDataRetention());
$application->add(new OrganizationResetWorkspacePasswords());
$application->run();
