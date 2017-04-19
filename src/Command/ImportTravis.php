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

class ImportTravis extends Command
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
        ->setName('app:import:travis')

        // the short description shown while running "php bin/console list"
        ->setDescription('Import all the data.')
        ->addArgument(
            'project',
            InputArgument::REQUIRED
        )

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp("This command allows you to import all the data from travis");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $input->getArgument('project');

        $project_id = 0;
        if ($project == 'eclipse') {
            $project_id = 6687497;
        } elseif ($project == 'atom') {
            $project_id = 2311719;
        } elseif ($project == 'react') {
            $project_id = 959804;
        } elseif ($project == 'hub') {
            $project_id = 1083389;
        }

        $client = new Client(['base_uri' => 'https://api.travis-ci.org/']);

        $this->container['db']->executeUpdate('DELETE FROM build WHERE project = "' . $project . '"');

        // * * * * * * * * * * * *
        // 1. Retreive the data from the source for an entity
        // * * * * * * * * * * * *

        $headers = [
            'headers' => [
                'Accept' => 'application/vnd.travis-ci.2+json'
            ]
        ];

        //Get the builds
        $promise = $client->requestAsync('GET', '/builds?event_type=pull_request&repository_id='.$project_id, $headers);

        $rawData = [];
        $promise->then(
            function (ResponseInterface $res) use ($output, $client, $headers, $project, $project_id, &$rawData) {
                $output->writeln('Got the list. Try to convert to JSON');
                $bodyContents = $res->getBody()->getContents();
                $builds = json_decode(str_replace("\'", '\"', $bodyContents));

                if (count($builds->builds) == 0) {
                    throw new Exception('Unable to convert json response to object');
                }


                $number = $builds->builds[0]->number;
                $output->writeln($number . ' builds found');
                $buildPromises = [];

                while ($number > 0) {
                    $output->writeln('Getting data from build #'.$number);
                    $buildPromises[] = $client->getAsync('/builds?after_number='.($number+1).'&event_type=pull_request&repository_id='.$project_id, $headers)
                    ->then(function (ResponseInterface $res) use ($project, &$rawData) {
                        $body = $res->getBody()->getContents();
                        $builds = json_decode(str_replace("\'", '\"', $body));

                        foreach ($builds->builds as $build) {
                            $raw = new \stdClass();
                            $raw->number = $build->number;
                            $raw->state = $build->state;
                            $raw->pull_request_number = $build->pull_request_number;
                            $raw->project = $project;
                            $rawData[] = $raw;
                        }
                    });
                    $number -= 24;
                    usleep(100);

                    if (count($buildPromises) > 10) {
                        Promise\unwrap($buildPromises);
                        Promise\settle($buildPromises)->wait();
                        $buildPromises = [];
                    }
                }

                Promise\unwrap($buildPromises);

                // Wait for the requests to complete, even if some of them fail
                Promise\settle($buildPromises)->wait();
            },
            function (RequestException $e) {
                echo $e->getMessage() . "\n";
                echo $e->getRequest()->getMethod();
            }
        );
        $list = $promise->wait();

        // * * * * * * * * * * * *
        // 2. Transform the data
        // * * * * * * * * * * * *

        // * * * * * * * * * * * *
        // 3. Map the data in the destination format
        // * * * * * * * * * * * *

        // * * * * * * * * * * * *
        // 4. Insert the data in the destination
        // * * * * * * * * * * * *
        foreach ($rawData as $toInsert) {
            $output->writeln('inserting data for build #'.$toInsert->number);
            try {
                $this->container['db']->insert('build', (array)$toInsert);
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }
    }
}
