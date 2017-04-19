<?php

namespace Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Symfony\Component\DomCrawler\Crawler;

use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class ImportGitHub extends Command
{
    private $container;
    private $clientId = '2d79171f08fec4600cf8';
    private $clientSecret = 'f79aa44b10c18ffeb6286a212d190e1700a412be';

    public function __construct($container)
    {
        parent::__construct();
        $this->container = $container;
    }

    protected function configure()
    {
        $this
        // the name of the command (the part after "bin/console")
        ->setName('app:import:github')

        // the short description shown while running "php bin/console list"
        ->setDescription('Import all the data.')
        ->addArgument(
            'project',
            InputArgument::REQUIRED
        )
        ->addOption(
            'startAt'
        )

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp("This command allows you to import all the data from github");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $input->getArgument('project');

        $project_id = 0;
        if ($project == 'eclipse') {
            $projectPath = 'eclipse/eclipse-collections';
        } elseif ($project == 'atom') {
            $projectPath = 'atom/atom';
        } elseif ($project == 'react') {
            $projectPath = 'facebook/react';
        } elseif ($project == 'hub') {
            $projectPath = 'github/hub';
        }

        $client = new Client(['base_uri' => 'https://api.github.com/']);

        //$this->container['db']->executeUpdate('DELETE FROM pullrequests WHERE project = "' . $project . '"');

        // * * * * * * * * * * * *
        // 1. Retreive the data from the source for an entity
        // * * * * * * * * * * * *

        $headers = [];

        //Get the builds
        $builds = $this->container['db']->fetchAll('SELECT DISTINCT pull_request_number FROM build b LEFT JOIN pullrequests p ON p.number = b.pull_request_number WHERE b.project = "' . $project . '" AND p.id IS NULL ORDER BY pull_request_number DESC');

        $rawData = [];
        $prPromises = [];

        foreach ($builds as $build) {
            $output->writeln('Getting data from PR #'.$build['pull_request_number']);
            $url = '/repos/'.$projectPath.'/pulls/'.$build['pull_request_number'].'?client_id='.$this->clientId.'&client_secret='.$this->clientSecret;
            $prPromises[] = $client->getAsync($url, $headers)
            ->then(function (ResponseInterface $res) use ($client, $project, $build, &$rawData) {
                $body = $res->getBody()->getContents();
                $pr = json_decode(str_replace("\'", '\"', $body));

                $raw = new \stdClass();
                $raw->number = $build['pull_request_number'];
                $raw->description = $pr->title;
                $raw->project = $project;
                $raw->comments = '';
                $raw->body = $pr->body;

                //$commentsData = $client->get($pr->_links->comments->href.'?client_id='.$this->clientId.'&client_secret='.$this->clientSecret);
                //$comments = json_decode(str_replace("\'", '\"', $commentsData->getBody()->getContents()));

                //foreach ($comments as $comment) {
                //    $raw->comments .= $comment->body . "\n\n";
                //}

                $rawData[] = $raw;
            }, function (\Exception $e) use ($output) {
                $output->writeln($e->getMessage());
            });
            usleep(100);

            if (count($prPromises) > 10) {
                Promise\unwrap($prPromises);
                Promise\settle($prPromises)->wait();
                $prPromises = [];

                $this->insert($rawData, $output);
                $rawData = [];
            }
        }

        Promise\unwrap($prPromises);
        Promise\settle($prPromises)->wait();

        // * * * * * * * * * * * *
        // 2. Transform the data
        // * * * * * * * * * * * *

        // * * * * * * * * * * * *
        // 3. Map the data in the destination format
        // * * * * * * * * * * * *

        // * * * * * * * * * * * *
        // 4. Insert the data in the destination
        // * * * * * * * * * * * *
    }

    public function insert($data, $output)
    {
        foreach ($data as $toInsert) {
            $output->writeln('inserting data for pr #'.$toInsert->number);
            try {
                $this->container['db']->insert('pullrequests', (array)$toInsert);
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }
    }
}
