<?php

namespace BuildEnv\Composer;

use Composer\Command\BaseCommand;
use Composer\IO\IOInterface;
use Dotenv;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BuildEnvCommand extends BaseCommand
{
    protected $defaults = [];
    protected $pinned = [];
    
    protected function configure()
    {
        $this->setName('build-env')
            ->setDescription('Build .env file from .env.json')
            ->setDefinition([new InputArgument('environment', InputArgument::OPTIONAL, 'Environment to create .env as: local, testing, staging or production', 'local'),
                             new InputArgument('defaults', InputArgument::OPTIONAL, 'Optional location of file to use for variable substitution and default values if keys match'),
                             new InputOption('set-defaults', null, InputOption::VALUE_NONE, 'Remember defaults file')]);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = $this->getIO();

        $this->convertEnvExample($io);    
        
        if (!file_exists('.env.json')) {
            $io->writeError('<error>Cannot find .env.json file</error>');
            exit(1);
        }
        
        $targetEnvironment = $input->getArgument('environment');

        switch ($targetEnvironment) {
            case 'test':
                $targetEnvironment = 'testing';
                break;
            case 'stag':
                $targetEnvironment = 'staging';
                break;
            case 'prod':
                $targetEnvironment = 'production';
                break;
        }

        $io->writeError('<info>Building .env for ' . $targetEnvironment . ' environment</info>');
        $this->readDefaults($input, $io);            
        
        if (($env = file_get_contents('.env.json')) === false) {
            $io->writeError('<error>Failed reading .env.json file</error>');
            exit(1);
        }
        
        $env = json_decode($env, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $io->writeError('<error>Failed reading json from .env.json: ' . json_last_error_msg() . '</error>');
            exit(1);
        }

        $pinnedValues = $this->getPinnedValues();
        
        $compiledEnv = <<<TEMPLATE
###############################################################
# This file is generated by "composer build-env"              #
#                                                             #
# IMPORTANT:                                                  #
# New and updated .env values should be added to .env.json    #
# and committed into your vcs. After updating .env.json run   #
# "composer build-env" to re-build this .env file.            #
#                                                             #
# Values in this file can be pinned to not be overwritten by  #
# "composer build-env". Add local pinned values only in the   #
# designated block below.                                     #
###############################################################

TEMPLATE;

        $keyGroup = '';
        
        ksort($env, SORT_NATURAL);

        foreach ($env as $key => $value) {
            $group = explode('_', $key)[0];
            if ($keyGroup != $group) {
                $keyGroup = $group;
                $compiledEnv .= "\n";
            }
            
            if (!empty($this->defaults) && isset($this->defaults[$key])) {
                $value = $this->defaults[$key];
            } elseif (is_array($value)) {
                if (isset($value[$targetEnvironment])) {
                    $value = $value[$targetEnvironment];
                } else {
                    $value = array_shift($value);
                }
            }
            
            $compiledEnv .= $this->printValue($key, $value);
        }
        
        $compiledEnv .= $pinnedValues;

        if (file_put_contents('.env', $compiledEnv) === false) {
            $io->writeError('<error>Failed to create .env file</error>');
            exit(1);
        }

        $io->writeError('<info>.env file built</info>');
    }
    
    protected function printValue($key, $value)
    {
        $str = '';
        
        if ($value === "") {
            $str = $key . "=\n";
        } elseif (is_null($value) || ($value === "null")) {
            $str = $key . "=null\n";
        } elseif (is_numeric($value)) {
            $str = $key . "=" . $value . "\n";
        } elseif (is_bool($value)) {
            $str = $key . "=" . (($value)?'true':'false') . "\n";
        } else {           
            if (preg_match('/\{\{(.*?)\}\}/', $value, $matches)) {
                if (!empty($this->defaults) && isset($this->defaults[$matches[1]])) {
                    $value = str_replace($matches[0], $this->defaults[$matches[1]], $value);
                }
            }
            
            if (preg_match('/[\s=#\\\$\(\)\{\}\[\]`"\']/', $value)) {
                $value = '"' . str_replace('"','\"', $value) . '"';
            }
            
            $str = $key . "=" . $value . "\n";
        }
        
        if (isset($this->pinned[$key])) {
            $str = '#' . $str;
        }
        
        return $str;
    }
    
    protected function getPinnedValues()
    {
        $pinned = null;
        
        $header = <<<TEMPLATE
###############################################################
# Local pinned values                                         #
###############################################################
TEMPLATE;

        if (file_exists('.env')) {
            if (($env = file_get_contents('.env')) === false) {
                $io->writeError('<error>Failed reading .env file</error>');
                exit(1);
            }
            $pos = strpos($env, $header);
            if ($pos > 0) {
                $pinned = "\n" . substr($env, $pos);
                
                $tmpfile = basename(tempnam(getcwd(), '.pinned'));
                
                if (file_put_contents($tmpfile, $pinned) === false) {
                    $io->writeError('<error>Failed to create tmp file, make sure the current directory is writable</error>');
                    exit(1);
                }
                
                $dotenv = Dotenv\Dotenv::create(getcwd(), $tmpfile, new Dotenv\Environment\DotenvFactory([]));
                $this->pinned = $dotenv->load();
                unlink($tmpfile);
            }
        }
        
        if (empty($pinned)) {
            $pinned = "\n" . $header . "\n\n#DB_USERNAME=\"root\"\n#DB_PASSWORD=\"\"\n";
        }

        return $pinned;
    }
    
    protected function readDefaults($input, $io)
    {
        $targetEnvironment = $input->getArgument('environment');
        $defaults = $input->getArgument('defaults');

        if (!empty($defaults) && !file_exists($defaults)) {
            $io->writeError('<error>Defaults is not a valid file</error>');
            exit(1);
        }
        
        $defaulsFile = dirname(dirname(__DIR__)) . '/.env.' . $targetEnvironment . '.defaults';
       
        if ($input->getOption('set-defaults')) {
            if (is_link($defaulsFile)) {
                unlink($defaulsFile);
            }

            if (empty($defaults)) {
                $io->writeError('<error>Argument defaults must be provided for option --set-defaults</error>');
                exit(1);
            }
            
            $io->writeError('<info>Setting defaults for ' . $targetEnvironment . ' to: ' . $defaults . '</info>');

            if (symlink($defaults, $defaulsFile) === false) {
                $io->writeError('<error>Failed to create symlink: ' . $defaulsFile . '</error>');
                exit(1);
            }
        }
        
        if (!empty($defaults)) {
            $defaulsFile = $defaults;
        } elseif (is_link($defaulsFile)) {
            $defaulsFile = readlink($defaulsFile);
        }
        
        if (file_exists($defaulsFile)) {
            $io->writeError('<info>Reading defaults from: ' . $defaulsFile . '</info>');
            
            if (($this->defaults = file_get_contents($defaulsFile)) === false) {
                $io->writeError('<error>Failed reading defaults file</error>');
                exit(1);                
            }

            $this->defaults = json_decode($this->defaults, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $io->writeError('<error>Defaults file must be valid json file. Getting json error: ' . json_last_error_msg() . '</error>');
                exit(1);
            }
        }
    }
    
    protected function convertEnvExample($io)
    {
        if (!file_exists('.env.json') && file_exists('.env.example')) {
            $io->writeError('<warning>Cannot find .env.json file, but found .env.example</warning>');
            $io->writeError('<info>Converting .env.example to .env.json</info>');
        
            $dotenv = Dotenv\Dotenv::create(getcwd(), '.env.example', new Dotenv\Environment\DotenvFactory([]));
            $env = $dotenv->load();
            
            ksort($env, SORT_NATURAL);
            
            foreach($env as $key => &$value) {
                if ($key == 'APP_ENV') {
                    $value = ['local' => 'local',
                              'testing' => 'testing',
                              'staging' => 'staging',
                              'production' => 'production'];
                }
                
                if ($key == 'APP_DEBUG') {
                    $value = ['local' => true,
                              'testing' => true,
                              'staging' => false,
                              'production' => false];
                }
            }
            
            if (file_put_contents('.env.json', json_encode($env, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_FORCE_OBJECT|JSON_NUMERIC_CHECK)) === false) {
                $io->writeError('<error>Failed to create .env.json file</error>');
                exit(1);
            }
            
            $io->writeError('<info>.env.json created</info>');
            
            if ($io->isInteractive() && $io->askConfirmation('Would you like to remove your old .env.example file [<comment>yes</comment>]? ', true)) {
                unlink('.env.example');
            }
        }
    }
}
