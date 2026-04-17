#!/usr/bin/env php
<?php

declare(strict_types=1);

use Samudra\Agent\Commands\ExtractCommand;
use Samudra\Agent\Commands\InitCommand;
use Samudra\Agent\Commands\LoginCommand;
use Samudra\Agent\Commands\RegisterCommand;
use Samudra\Agent\Commands\SelfUpdateCommand;
use Samudra\Agent\Commands\StatusCommand;
use Samudra\Agent\Commands\UploadCommand;
use Samudra\Agent\AgentVersion;
use Symfony\Component\Console\Application;

Phar::mapPhar('samudra.phar');

require 'phar://samudra.phar/vendor/autoload.php';

$application = new Application('Samudra Agent', AgentVersion::CURRENT);
$application->add(new InitCommand());
$application->add(new LoginCommand());
$application->add(new ExtractCommand());
$application->add(new RegisterCommand());
$application->add(new SelfUpdateCommand());
$application->add(new StatusCommand());
$application->add(new UploadCommand());
$application->run();

__HALT_COMPILER();
