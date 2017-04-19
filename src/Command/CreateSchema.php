<?php

namespace Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateSchema extends Command
{
    private $container;

    public function __construct($container)
    {
        parent::__construct();
        $this->container = $container;
    }

    protected function configure()
    {
        $this
        // the name of the command (the part after "bin/console")
        ->setName('app:create:schema')

        // the short description shown while running "php bin/console list"
        ->setDescription('Create the schema if none exists.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp("This command allows you to create the schema if it does not already exists.");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sm = $this->container['db']->getSchemaManager();
        $schema = new \Doctrine\DBAL\Schema\Schema();

        if (count($sm->listTables())) {
            throw new \Exception('Tables already exists');
        }

        $builds = $schema->createTable('build');
        $builds->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $builds->addColumn('number', 'integer');
        $builds->addColumn('state', 'string', ['length' => 32]);
        $builds->addColumn('pull_request_number', 'integer');
        $builds->addColumn('project', 'string', ['length' => 32]);
        $builds->setPrimaryKey(['id']);

        $pr = $schema->createTable('pullrequests');
        $pr->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $pr->addColumn('number', 'integer', ['unsigned' => true]);
        $pr->addColumn('body', 'text');
        $pr->addColumn('description', 'text');
        $pr->addColumn('comments', 'text');
        $pr->addColumn('project', 'string', ['length' => 32]);
        $pr->setPrimaryKey(['id']);

        foreach ($schema->toSql($sm->getDatabasePlatform()) as $query) {
            $this->container['db']->executeQuery($query);
        }
    }
}
