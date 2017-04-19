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

class FindIssues extends Command
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
        ->setName('app:analyse:github')

        // the short description shown while running "php bin/console list"
        ->setDescription('Parse the body of the pull requests to find the number of issues.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $prs = $this->container['db']->fetchAll('SELECT * FROM pullrequests p');


        foreach ($prs as $pr) {
            $allMatches = [];
            $matches = [];
            preg_match_all('/\/issues\/(\d+)/', $pr['body'], $matches);
            $allMatches = array_merge($matches[1], $allMatches);
            preg_match_all('/(#)(\d+)/', $pr['body'], $matches);
            $allMatches = array_merge($matches[2], $allMatches);
            preg_match_all('/(#)(\d+)/', $pr['description'], $matches);
            $allMatches = array_merge($matches[2], $allMatches);
            preg_match_all('/(\-\ \[x\])(.*)/', $pr['body'], $matches);
            $allMatches = array_merge($matches[2], $allMatches);

            foreach ($allMatches as $key => $match) {
                $allMatches[$key] = $match;
            }

            $pr['nb_issues'] = max(1, count(array_unique($allMatches)));
            $this->save($pr['id'], $pr, $output);
        }
    }

    public function save($id, $toInsert, $output)
    {
        $output->writeln('Updating data for pr #'.$toInsert['number']);
        try {
            $this->container['db']->update('pullrequests', (array)$toInsert, ['id' => $id]);
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
        }
    }
}
